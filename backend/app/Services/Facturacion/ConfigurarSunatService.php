<?php

namespace App\Services\Facturacion;

use App\Exceptions\SunatSetupException;
use App\Models\FacturacionConfig;
use App\Services\Facturacion\Concerns\InteractuaConApiEmpresa;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Activa la facturación real (producción) de un emisor contra la API externa.
 *
 * Port de `gerencia/ajax_configurar_sunat.php` (legacy). Los 5 pasos, en orden:
 *   0. Convertir el certificado a PEM si vino como .pfx/.p12.
 *   1. Leer los datos actuales de la empresa (la API exige reenviarlos al actualizar).
 *   2. Subir el certificado y activar el ambiente de producción SUNAT.
 *   3. Guardar las credenciales SOL (y sincronizar el logo de paso).
 *   4. Encender el modo producción en la API.
 *   5. Reflejar el cambio en la configuración local.
 *
 * Ningún secreto (contraseña del certificado, clave SOL, api_token) se escribe
 * en logs ni se devuelve en las respuestas.
 */
class ConfigurarSunatService
{
    use InteractuaConApiEmpresa;

    public function __construct(private readonly CertificadoDigitalService $certificados)
    {
    }

    /**
     * @return array{certificado_path: string}
     *
     * @throws SunatSetupException
     */
    public function ejecutar(
        FacturacionConfig $config,
        UploadedFile $certificado,
        string $password,
        string $usuarioSol,
        string $claveSol,
    ): array {
        // El legacy solo exige destino y credencial; `activo` puede seguir en false
        // (justamente es lo que este flujo va a encender).
        if (trim((string) $config->base_url) === '' || trim((string) $config->api_token) === '') {
            throw new SunatSetupException(
                'La conexión con el servicio de facturación no está configurada (falta URL o token).',
            );
        }

        try {
            [$pemPath, $nombre, $mime] = $this->prepararCertificado($certificado, $password);

            $cliente = new FacturacionApiClient($config);

            $empresa = $this->leerEmpresa($cliente);
            $this->subirCertificado($cliente, $pemPath, $nombre, $mime, $password);
            $this->guardarCredencialesSol($cliente, $config, $empresa, $usuarioSol, $claveSol);
            $this->activarProduccion($cliente);

            $rutaCertificado = $this->certificados->guardarPemPrivado($pemPath, (string) $config->ruc);

            $config->forceFill(['modo' => 'produccion', 'activo' => true])->save();

            return ['certificado_path' => $rutaCertificado];
        } finally {
            // Los temporales llevan la clave privada del cliente: se borran pase lo que pase.
            $this->certificados->limpiarTemporales();
        }
    }

    /**
     * Paso 0. Devuelve `[ruta, nombre, mime]` del PEM listo para subir.
     *
     * Igual que el legacy, un archivo con extensión .pfx/.p12 se trata como PFX
     * aunque su contenido ya sea PEM, y un archivo binario se trata como PFX
     * aunque se llame .pem.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function prepararCertificado(UploadedFile $certificado, string $password): array
    {
        $ruta = (string) $certificado->getRealPath();
        $extension = strtolower($certificado->getClientOriginalExtension());

        $esPfx = in_array($extension, ['pfx', 'p12'], true) || ! $this->certificados->esPem($ruta);

        if (! $esPfx) {
            return [$ruta, 'certificado.pem', 'application/x-pem-file'];
        }

        if (! $this->certificados->opensslDisponible()) {
            throw new SunatSetupException(
                'El servidor no puede procesar certificados .pfx en este momento. '
                .'Sube el certificado en formato .pem o avisa al soporte técnico.',
                500,
            );
        }

        return [$this->certificados->convertirPfxAPem($ruta, $password), 'certificado.pem', 'application/x-pem-file'];
    }

    /**
     * Paso 1.
     *
     * @return array<string, mixed>
     */
    private function leerEmpresa(FacturacionApiClient $cliente): array
    {
        $mensaje = 'No se pudo conectar con el servicio de facturación para leer los datos de la empresa.';

        try {
            $respuesta = $cliente->obtenerEmpresa();
        } catch (ConnectionException $e) {
            throw new SunatSetupException($mensaje, 502, $e);
        }

        $empresa = $respuesta->json('data.company');

        if ($respuesta->failed() || ! is_array($empresa) || $empresa === []) {
            throw new SunatSetupException($mensaje, 502);
        }

        return $empresa;
    }

    /** Paso 2. */
    private function subirCertificado(
        FacturacionApiClient $cliente,
        string $pemPath,
        string $nombre,
        string $mime,
        string $password,
    ): void {
        try {
            $respuesta = $cliente->configurarSunat($pemPath, $nombre, $mime, $password);
        } catch (ConnectionException $e) {
            throw new SunatSetupException('No se pudo enviar el certificado al servicio de facturación.', 502, $e);
        }

        if ($respuesta->failed()) {
            throw new SunatSetupException(
                trim('El servicio rechazó el certificado. '.$this->erroresApi($respuesta)),
            );
        }
    }

    /**
     * Paso 3.
     *
     * @param  array<string, mixed>  $empresa
     */
    private function guardarCredencialesSol(
        FacturacionApiClient $cliente,
        FacturacionConfig $config,
        array $empresa,
        string $usuarioSol,
        string $claveSol,
    ): void {
        $campos = [
            'ruc'              => (string) ($empresa['ruc'] ?? $config->ruc),
            'razon_social'     => (string) ($empresa['razon_social'] ?? $config->razon_social_emisor),
            'nombre_comercial' => (string) ($empresa['nombre_comercial'] ?? ''),
            'direccion'        => (string) ($empresa['direccion'] ?? ''),
            'ubigeo'           => (string) ($empresa['ubigeo'] ?? $config->emisor_ubigeo),
            'distrito'         => (string) ($empresa['distrito'] ?? $config->emisor_distrito),
            'provincia'        => (string) ($empresa['provincia'] ?? $config->emisor_provincia),
            'departamento'     => (string) ($empresa['departamento'] ?? $config->emisor_departamento),
            'telefono'         => (string) ($empresa['telefono'] ?? ''),
            'email'            => (string) ($empresa['email'] ?? ''),
            'usuario_sol'      => $usuarioSol,
            'clave_sol'        => $claveSol,
            'activo'           => '1',
        ];

        foreach (['web', 'endpoint_beta', 'endpoint_produccion'] as $opcional) {
            if (! empty($empresa[$opcional])) {
                $campos[$opcional] = (string) $empresa[$opcional];
            }
        }

        try {
            $respuesta = $cliente->actualizarEmpresa($campos, $this->logoEmpresaActual());
        } catch (ConnectionException $e) {
            throw new SunatSetupException('No se pudieron guardar las credenciales SOL.', 502, $e);
        }

        if ($respuesta->failed()) {
            throw new SunatSetupException(
                trim('Revisa los datos ingresados: '.$this->erroresApi($respuesta)),
            );
        }
    }

    /** Paso 4. */
    private function activarProduccion(FacturacionApiClient $cliente): void
    {
        try {
            $respuesta = $cliente->activarProduccion();
        } catch (ConnectionException) {
            // Paridad con el legacy: si el toggle no llega a responder, el flujo
            // sigue y la config local pasa a producción igual. El certificado y
            // las credenciales YA se guardaron; reventar aquí obligaría al admin
            // a repetir todo el upload.
            Log::warning('[FACT-CONFIGURE-SUNAT] no se pudo contactar toggle-production; se continúa.');

            return;
        }

        if ($respuesta->failed()) {
            throw new SunatSetupException(
                trim('El certificado y las credenciales se guardaron, pero no se pudo activar '
                    .'el modo producción: '.$this->erroresApi($respuesta)),
            );
        }
    }

}
