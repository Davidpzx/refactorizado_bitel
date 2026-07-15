<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de Evolution API (https://doc.evolution-api.com). Ningun log de esta
 * clase incluye el api_key ni el cuerpo de mensajes (puede contener datos de clientes).
 */
class EvolutionProvider implements WhatsAppProvider
{
    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.evolution.base_url'))
            ->withHeaders(['apikey' => (string) config('services.evolution.api_key')])
            ->timeout(15)
            ->connectTimeout(5);
    }

    public function crearInstancia(string $nombreInstancia): array
    {
        $response = $this->http()->post('/instance/create', [
            'instanceName' => $nombreInstancia,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]);

        if ($response->failed()) {
            Log::warning('evolution.crear_instancia_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            return [];
        }

        return $response->json() ?? [];
    }

    public function obtenerQR(string $nombreInstancia): string
    {
        $response = $this->http()->get("/instance/connect/{$nombreInstancia}");

        if ($response->failed()) {
            Log::warning('evolution.obtener_qr_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            return '';
        }

        $base64 = (string) ($response->json('base64') ?? $response->json('qrcode.base64') ?? '');

        // Evolution API ya devuelve el data URI completo (data:image/png;base64,...);
        // se retorna solo el contenido base64 para que el caller anteponga el prefijo.
        return preg_replace('/^data:image\/\w+;base64,/', '', $base64);
    }

    public function estadoInstancia(string $nombreInstancia): string
    {
        $response = $this->http()->get("/instance/connectionState/{$nombreInstancia}");

        if ($response->failed()) {
            return 'desconectada';
        }

        $estado = (string) ($response->json('instance.state') ?? $response->json('state') ?? '');

        return match ($estado) {
            'open' => 'conectada',
            'connecting' => 'qr_pendiente',
            default => 'desconectada',
        };
    }

    public function enviarTexto(string $nombreInstancia, string $jid, string $texto): array
    {
        $response = $this->http()->post("/message/sendText/{$nombreInstancia}", [
            'number' => $jid,
            'text' => $texto,
        ]);

        if ($response->failed()) {
            Log::warning('evolution.enviar_texto_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            throw new \RuntimeException('No se pudo enviar el mensaje de WhatsApp.');
        }

        return $response->json() ?? [];
    }

    public function enviarMedia(string $nombreInstancia, string $jid, string $mediaUrl, string $tipo, ?string $caption): array
    {
        $response = $this->http()->post("/message/sendMedia/{$nombreInstancia}", [
            'number' => $jid,
            'mediatype' => $tipo,
            'media' => $mediaUrl,
            'caption' => $caption,
        ]);

        if ($response->failed()) {
            Log::warning('evolution.enviar_media_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            throw new \RuntimeException('No se pudo enviar el archivo de WhatsApp.');
        }

        return $response->json() ?? [];
    }
}