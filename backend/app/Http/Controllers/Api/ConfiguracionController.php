<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SunatSetupException;
use App\Http\Controllers\Controller;
use App\Models\FacturacionConfig;
use App\Services\Facturacion\SincronizarLogoFacturacionService;
use App\Services\LogoProcessorService;
use App\Support\ResourceCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ConfiguracionController extends Controller
{
    public function show(): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json($this->defaults());
        }

        $data = Cache::remember(ResourceCache::key('configuracion-empresa', 'sin-logo'), ResourceCache::TTL_SECONDS, function (): array {
            $row = DB::table('configuracion_empresa')->where('id', 1)->first();
            if (!$row) return $this->defaults();
            $data = (array) $row;
            unset($data['logo_base64']);
            return $data;
        });

        return response()->json($data);
    }

    public function showConLogo(): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json($this->defaults());
        }

        $data = Cache::remember(ResourceCache::key('configuracion-empresa', 'con-logo'), ResourceCache::TTL_SECONDS, function (): array {
            $row = DB::table('configuracion_empresa')->where('id', 1)->first();
            return $row ? (array) $row : $this->defaults();
        });

        return response()->json($data);
    }

    public function update(Request $request): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json(['error' => 'Tabla configuracion_empresa no existe.'], 422);
        }

        // Campos opcionales vacíos ('') se normalizan a null para que las
        // reglas `regex`/`email` no se evalúen sobre cadenas vacías.
        $request->merge(array_map(
            static fn ($valor) => $valor === '' ? null : $valor,
            $request->only(['gerente_dni', 'correo_contacto', 'nombre_comercial', 'gerente_general', 'sistema_nombre', 'telefono_contacto', 'direccion_principal'])
        ));

        $data = $request->validate([
            'razon_social'        => ['required', 'string', 'max:200'],
            'nombre_comercial'    => ['nullable', 'string', 'max:200'],
            'ruc'                 => ['required', 'string', 'size:11', 'regex:/^\d{11}$/'],
            'gerente_general'     => ['nullable', 'string', 'max:100'],
            'gerente_dni'         => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'sistema_nombre'      => ['nullable', 'string', 'max:100'],
            'telefono_contacto'   => ['nullable', 'string', 'max:20'],
            'direccion_principal' => ['nullable', 'string', 'max:255'],
            'correo_contacto'     => ['nullable', 'email', 'max:100'],
        ], [
            'ruc.regex'         => 'El RUC debe tener 11 dígitos numéricos.',
            'ruc.size'          => 'El RUC debe tener 11 dígitos numéricos.',
            'gerente_dni.regex' => 'El DNI del gerente debe tener 8 dígitos numéricos.',
        ]);

        // UPSERT — crea id=1 si no existe
        DB::table('configuracion_empresa')->upsert(
            array_merge(['id' => 1], $data),
            ['id'],
            array_keys($data)
        );
        ResourceCache::invalidate('configuracion-empresa');

        return response()->json(['message' => 'Configuración guardada.']);
    }

    public function updateLogo(Request $request, LogoProcessorService $logoProcessor): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json(['error' => 'Tabla configuracion_empresa no existe.'], 422);
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        // Quita el fondo sólido por flood-fill desde las 4 esquinas y redimensiona
        // a máx. 400px, para que el logo se acople a modo claro/oscuro.
        $dataUrl = $logoProcessor->procesar($request->file('logo')->getRealPath());

        if ($dataUrl === null) {
            return response()->json(['error' => 'No se pudo procesar la imagen. Sube un PNG, JPG o WebP válido.'], 422);
        }

        DB::table('configuracion_empresa')
            ->upsert(['id' => 1, 'logo_base64' => $dataUrl], ['id'], ['logo_base64']);
        ResourceCache::invalidate('configuracion-empresa');

        return response()->json(['message' => 'Logo actualizado.', 'logo_base64' => $dataUrl]);
    }

    public function deleteLogo(): JsonResponse
    {
        DB::table('configuracion_empresa')
            ->where('id', 1)
            ->update(['logo_base64' => null]);
        ResourceCache::invalidate('configuracion-empresa');

        return response()->json(['message' => 'Logo eliminado.']);
    }

    /**
     * Envía el logo actual del Perfil de Empresa a la company de la API de
     * facturación externa (PUT companies, multipart). Port de
     * `gerencia/ajax_sync_logo_facturacion.php` (legacy).
     *
     * Nota conocida (documentada también en la UI): la API tiene el logo del
     * PDF oficial hardcodeado — este sync cubre los datos de la company, no
     * cambia el PDF.
     */
    public function syncLogoFacturacion(Request $request, SincronizarLogoFacturacionService $sincronizar): JsonResponse
    {
        $datos = $request->validate([
            'tienda_id' => ['nullable', 'string', 'max:20'],
            'clave_sol' => ['required', 'string', 'max:100'],
        ]);

        $tiendaId = trim((string) ($datos['tienda_id'] ?? '')) ?: null;
        $config = FacturacionConfig::paraTienda($tiendaId);

        if ($config === null) {
            return response()->json([
                'ok'  => false,
                'msg' => 'No hay una configuración de facturación registrada para este emisor.',
            ], 400);
        }

        try {
            $sincronizar->ejecutar($config, trim($datos['clave_sol']));
        } catch (SunatSetupException $e) {
            return response()->json(['ok' => false, 'msg' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            Log::error('[FACT-SYNC-LOGO] fallo inesperado', [
                'excepcion' => $e::class,
                'mensaje'   => $e->getMessage(),
                'tienda_id' => $config->tienda_id,
            ]);

            return response()->json(['ok' => false, 'msg' => 'Error interno al sincronizar el logo.'], 500);
        }

        return response()->json([
            'ok'  => true,
            'msg' => 'Logo sincronizado con el servicio de facturación. Nota: la API tiene el logo del PDF '
                .'oficial fijo — este sync actualiza los datos de la empresa, no cambia el logo que aparece '
                .'en el PDF de los comprobantes.',
        ]);
    }

    private function defaults(): array
    {
        return [
            'id'                  => null,
            'razon_social'        => '',
            'nombre_comercial'    => '',
            'ruc'                 => '',
            'gerente_general'     => '',
            'gerente_dni'         => '',
            'sistema_nombre'      => 'SIS-KYRO',
            'telefono_contacto'   => '',
            'direccion_principal' => '',
            'correo_contacto'     => '',
        ];
    }
}
