<?php

namespace App\Services\Facturacion\Concerns;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers compartidos entre los servicios que actualizan la company de la API
 * externa (`PUT /api/v1/companies/{id}`): leer el logo actual del Perfil de
 * Empresa listo para adjuntar, y traducir los errores de validación de la API
 * a un mensaje legible para el admin.
 */
trait InteractuaConApiEmpresa
{
    /**
     * Logo de la empresa listo para el multipart. Se lee en memoria desde
     * `configuracion_empresa.logo_base64`: no hace falta un temporal como en
     * PHP puro (paridad con `ajax_sync_logo_facturacion.php` del legacy).
     *
     * @return array{contents: string, filename: string, mime: string}|null
     */
    private function logoEmpresaActual(): ?array
    {
        if (! Schema::hasTable('configuracion_empresa')) {
            return null;
        }

        $dataUrl = DB::table('configuracion_empresa')->where('id', 1)->value('logo_base64');

        if (! is_string($dataUrl) || ! preg_match('#^data:image/(png|jpe?g);base64,(.+)$#s', $dataUrl, $m)) {
            return null;
        }

        $binario = base64_decode($m[2], true);

        if ($binario === false) {
            return null;
        }

        $extension = $m[1] === 'png' ? 'png' : 'jpg';

        return [
            'contents' => $binario,
            'filename' => 'logo.'.$extension,
            'mime'     => 'image/'.$m[1],
        ];
    }

    /** Concatena los mensajes de validación 422 de Laravel en una frase legible. */
    private function erroresApi(Response $respuesta): string
    {
        $json = $respuesta->json();

        if (! is_array($json)) {
            return '';
        }

        $errores = $json['errors'] ?? null;

        if (is_array($errores) && $errores !== []) {
            $lineas = array_map(
                static fn ($mensajes) => is_array($mensajes) ? implode(' ', $mensajes) : (string) $mensajes,
                $errores,
            );

            return trim(implode(' ', $lineas));
        }

        return empty($json['message']) ? '' : (string) $json['message'];
    }
}
