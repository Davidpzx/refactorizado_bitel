<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SunatSetupException;
use App\Http\Controllers\Controller;
use App\Models\FacturacionConfig;
use App\Services\Facturacion\ConfigurarSunatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Configuración de facturación electrónica por tienda (con fallback global) y
 * activación de producción contra la API externa.
 *
 * Solo rol admin (ver `routes/api.php`), igual que el legacy
 * (`gerencia/ajax_configurar_sunat.php` exigía `$_SESSION['rol'] === 'admin'`).
 */
class FacturacionConfigController extends Controller
{
    /** Campos de texto donde '' significa "sin valor" y se normaliza a NULL. */
    private const CAMPOS_VACIABLES = ['tienda_id', 'base_url', 'api_token'];

    public function __construct(private readonly ConfigurarSunatService $configurarSunat)
    {
    }

    /** La global primero, luego las tiendas por código. */
    public function index(): JsonResponse
    {
        $configs = FacturacionConfig::query()
            ->orderByRaw('tienda_id is null desc')
            ->orderBy('tienda_id')
            ->get();

        return response()->json(['data' => $configs->map($this->presentar(...))->all()]);
    }

    public function show(FacturacionConfig $facturacionConfig): JsonResponse
    {
        return response()->json($this->presentar($facturacionConfig));
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $this->validar($request, creando: true);

        $this->rechazarDuplicado($datos['tienda_id'] ?? null);

        return response()->json($this->presentar(FacturacionConfig::create($datos)), 201);
    }

    /**
     * `api_token` ausente = se conserva el actual. `api_token: null` = se borra.
     * Nunca vuelve en la respuesta (el modelo lo tiene en `$hidden`).
     */
    public function update(Request $request, FacturacionConfig $facturacionConfig): JsonResponse
    {
        $datos = $this->validar($request, creando: false);

        if (array_key_exists('tienda_id', $datos) && $datos['tienda_id'] !== $facturacionConfig->tienda_id) {
            $this->rechazarDuplicado($datos['tienda_id'], ignorarId: $facturacionConfig->id);
        }

        $facturacionConfig->update($datos);

        return response()->json($this->presentar($facturacionConfig->refresh()));
    }

    public function destroy(FacturacionConfig $facturacionConfig): JsonResponse
    {
        $facturacionConfig->delete();

        return response()->json(['message' => 'Configuración de facturación eliminada.']);
    }

    /**
     * Sube el certificado digital + credenciales SOL a la API externa y deja al
     * emisor en modo producción. Port de `ajax_configurar_sunat.php`.
     */
    public function configureSunat(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tienda_id'   => ['nullable', 'string', 'max:20'],
            'certificado' => [
                'required',
                'file',
                'max:5120',
                // `mimes:pem` no sirve: PHP detecta un PEM como text/plain y un PFX
                // como application/octet-stream. La extensión es el filtro fuerte;
                // el contenido lo valida OpenSSL al convertir.
                'extensions:pem,pfx,p12',
                'mimetypes:application/x-pkcs12,application/pkcs12,application/x-pem-file,application/octet-stream,text/plain',
            ],
            'certificado_password' => ['required', 'string', 'max:200'],
            'usuario_sol'          => ['required', 'string', 'max:100'],
            'clave_sol'            => ['required', 'string', 'max:100'],
        ], [
            'certificado.extensions' => 'El certificado debe ser un archivo .pem, .pfx o .p12.',
            'certificado.mimetypes'  => 'El certificado debe ser un archivo .pem, .pfx o .p12.',
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
            $resultado = $this->configurarSunat->ejecutar(
                $config,
                $request->file('certificado'),
                trim($datos['certificado_password']),
                trim($datos['usuario_sol']),
                trim($datos['clave_sol']),
            );
        } catch (SunatSetupException $e) {
            Log::error($e);

            return response()->json(['ok' => false, 'msg' => 'Error interno del servidor'], $e->status);
        } catch (Throwable $e) {
            // Sin secretos: el password va por STDIN a OpenSSL, nunca por argv.
            Log::error('[FACT-CONFIGURE-SUNAT] fallo inesperado', [
                'excepcion' => $e::class,
                'mensaje'   => $e->getMessage(),
                'tienda_id' => $config->tienda_id,
            ]);

            return response()->json(['ok' => false, 'msg' => 'Error interno al activar la facturación.'], 500);
        }

        return response()->json([
            'ok'  => true,
            'msg' => '¡Facturación real activada! Se cargó el certificado, se guardaron tus credenciales SOL '
                .'y el sistema quedó en modo producción. La validación definitiva la hace SUNAT al emitir '
                .'tu primer comprobante real.',
            'certificado_path' => $resultado['certificado_path'],
            'config'           => $this->presentar($config->refresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $creando): array
    {
        $this->normalizarVacios($request);

        $obligatorioAlCrear = $creando ? 'required' : 'sometimes';

        return $request->validate([
            'tienda_id'           => ['nullable', 'string', 'max:20'],
            'company_id'          => [$obligatorioAlCrear, 'integer', 'min:1'],
            'branch_id'           => [$obligatorioAlCrear, 'integer', 'min:1'],
            'base_url'            => ['nullable', 'url:http,https', 'max:255'],
            'api_token'           => ['nullable', 'string', 'max:500'],
            'ruc'                 => ['nullable', 'string', 'max:20'],
            'razon_social_emisor' => ['nullable', 'string', 'max:200'],
            'emisor_ubigeo'       => ['nullable', 'string', 'max:10'],
            'emisor_distrito'     => ['nullable', 'string', 'max:60'],
            'emisor_provincia'    => ['nullable', 'string', 'max:60'],
            'emisor_departamento' => ['nullable', 'string', 'max:60'],
            'modo'                => ['nullable', Rule::in(['beta', 'produccion'])],
            'serie_boleta'        => ['nullable', 'string', 'max:10'],
            'serie_factura'       => ['nullable', 'string', 'max:10'],
            'serie_nota_credito'  => ['nullable', 'string', 'max:10'],
            'igv_porcentaje'      => ['nullable', 'numeric', 'between:0,100'],
            'activo'              => ['nullable', 'boolean'],
        ]);
    }

    /** Un '' desde el formulario es "sin valor", no la cadena vacía. */
    private function normalizarVacios(Request $request): void
    {
        foreach (self::CAMPOS_VACIABLES as $campo) {
            if ($request->has($campo) && trim((string) $request->input($campo)) === '') {
                $request->merge([$campo => null]);
            }
        }
    }

    /**
     * La UNIQUE de la tabla no protege la fila global (en MySQL/SQLite los NULL
     * no colisionan), así que la unicidad de la global se valida aquí.
     */
    private function rechazarDuplicado(?string $tiendaId, ?int $ignorarId = null): void
    {
        $existe = FacturacionConfig::query()
            ->when($ignorarId !== null, fn ($q) => $q->where('id', '!=', $ignorarId))
            ->when(
                $tiendaId === null,
                fn ($q) => $q->globales(),
                fn ($q) => $q->where('tienda_id', $tiendaId),
            )
            ->exists();

        if (! $existe) {
            return;
        }

        throw ValidationException::withMessages([
            'tienda_id' => $tiendaId === null
                ? 'Ya existe una configuración global de facturación.'
                : "La tienda {$tiendaId} ya tiene una configuración de facturación.",
        ]);
    }

    /**
     * `toArray()` ya excluye `api_token` ($hidden). Se expone solo si está puesto.
     *
     * @return array<string, mixed>
     */
    private function presentar(FacturacionConfig $config): array
    {
        return $config->toArray() + [
            'tiene_api_token' => trim((string) $config->api_token) !== '',
            'es_global'       => $config->esGlobal(),
            'esta_operativa'  => $config->estaOperativa(),
        ];
    }
}
