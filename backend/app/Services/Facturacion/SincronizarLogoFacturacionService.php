<?php

namespace App\Services\Facturacion;

use App\Exceptions\SunatSetupException;
use App\Models\FacturacionConfig;
use App\Services\Facturacion\Concerns\InteractuaConApiEmpresa;
use Illuminate\Http\Client\ConnectionException;

/**
 * Sincroniza el logo del Perfil de Empresa con la company de la API externa
 * de facturación, sin tocar el certificado ni el modo producción.
 *
 * Port de `gerencia/ajax_sync_logo_facturacion.php` (legacy): lee los datos
 * actuales de la company (la API exige reenviarlos completos en el update),
 * conserva el `usuario_sol` ya configurado y adjunta el logo actual.
 *
 * Nota conocida: la API tiene el logo del PDF oficial hardcodeado — este sync
 * actualiza los datos de la company, no cambia el logo del PDF de los
 * comprobantes (se lo advierte también al admin en la respuesta del endpoint).
 */
class SincronizarLogoFacturacionService
{
    use InteractuaConApiEmpresa;

    /** @throws SunatSetupException */
    public function ejecutar(FacturacionConfig $config, string $claveSol): void
    {
        $cliente = new FacturacionApiClient($config);

        $mensajeConexion = 'No se pudo conectar con el servicio de facturación para leer los datos de la empresa.';

        try {
            $respuesta = $cliente->obtenerEmpresa();
        } catch (ConnectionException $e) {
            throw new SunatSetupException($mensajeConexion, 502, $e);
        }

        $empresa = $respuesta->json('data.company');

        if ($respuesta->failed() || ! is_array($empresa) || $empresa === []) {
            throw new SunatSetupException($mensajeConexion, 502);
        }

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
            'usuario_sol'      => (string) ($empresa['usuario_sol'] ?? ''),
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
            throw new SunatSetupException('No se pudo sincronizar el logo con el servicio de facturación.', 502, $e);
        }

        if ($respuesta->failed()) {
            throw new SunatSetupException(
                trim('El servicio rechazó la sincronización: '.$this->erroresApi($respuesta)),
            );
        }
    }
}
