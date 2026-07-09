<?php

namespace App\Services\Facturacion;

use App\Models\FacturacionConfig;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de la API Laravel externa de facturación electrónica.
 *
 * Port de `_api_get()` / `_api_multipart()` del legacy
 * (`gerencia/ajax_configurar_sunat.php`), con los mismos timeouts.
 */
class FacturacionApiClient
{
    private const CONNECT_TIMEOUT = 8;
    private const TIMEOUT = 45;

    public function __construct(private readonly FacturacionConfig $config)
    {
    }

    /** GET `/api/v1/companies/{id}` — la API exige reenviar estos datos en el update. */
    public function obtenerEmpresa(): Response
    {
        return $this->peticion()->get($this->url());
    }

    /** POST multipart `/api/v1/setup/configure-sunat` — sube el certificado y activa producción. */
    public function configurarSunat(string $pemPath, string $nombre, string $mime, string $password): Response
    {
        return $this->peticion()
            ->attach('certificate_file', (string) file_get_contents($pemPath), $nombre, ['Content-Type' => $mime])
            ->post($this->url('/api/v1/setup/configure-sunat', global: true), [
                'company_id'           => (string) $this->config->company_id,
                'environment'          => 'produccion',
                'certificate_password' => $password,
                'force_update'         => 'true',
            ]);
    }

    /**
     * PUT `/api/v1/companies/{id}` con las credenciales SOL.
     *
     * Se manda como POST + `_method=PUT` porque en Laravel los uploads enviados
     * por PUT no llegan a `$request->file()` (misma razón que en el legacy).
     *
     * @param  array<string, string>  $campos
     * @param  array{contents: string, filename: string, mime: string}|null  $logo
     */
    public function actualizarEmpresa(array $campos, ?array $logo = null): Response
    {
        $peticion = $this->peticion()->asMultipart();

        if ($logo !== null) {
            $peticion = $peticion->attach(
                'logo_path',
                $logo['contents'],
                $logo['filename'],
                ['Content-Type' => $logo['mime']],
            );
        }

        return $peticion->post($this->url(), $campos + ['_method' => 'PUT']);
    }

    /** POST multipart `/api/v1/companies/{id}/toggle-production`. */
    public function activarProduccion(): Response
    {
        return $this->peticion()
            ->asMultipart()
            ->post($this->url('/toggle-production'), ['modo_produccion' => '1']);
    }

    private function peticion(): PendingRequest
    {
        return Http::withToken((string) $this->config->api_token)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }

    /**
     * Sin argumentos: la URL de la empresa. Con `$sufijo`: se le concatena.
     * Con `$global`: el sufijo cuelga de la base, no de la empresa.
     */
    private function url(string $sufijo = '', bool $global = false): string
    {
        $base = rtrim((string) $this->config->base_url, '/');

        if ($global) {
            return $base.$sufijo;
        }

        return $base.'/api/v1/companies/'.$this->config->company_id.$sufijo;
    }
}
