<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function recibir(Request $request): JsonResponse
    {
        $tokenEsperado = (string) config('services.evolution.webhook_token');
        if ($tokenEsperado === '' || $request->query('token') !== $tokenEsperado) {
            return response()->json(['message' => 'Token invalido.'], 403);
        }

        $instancia = (string) $request->input('instance');
        $cuenta = WhatsAppCuenta::where('instancia', $instancia)->first();
        if (!$cuenta) {
            Log::warning('whatsapp.webhook_instancia_desconocida', ['instancia' => $instancia]);
            return response()->json(['ok' => true]);
        }

        $data = $request->input('data', []);
        $key = $data['key'] ?? [];
        if (($key['fromMe'] ?? false) === true) {
            return response()->json(['ok' => true]);
        }

        $jid = (string) ($key['remoteJid'] ?? '');
        if ($jid === '') {
            return response()->json(['ok' => true]);
        }

        $chat = WhatsAppChat::firstOrCreate(
            ['cuenta_id' => $cuenta->id, 'jid' => $jid],
            ['nombre_contacto' => $data['pushName'] ?? null, 'numero_contacto' => explode('@', $jid)[0] ?? null]
        );

        $contenido = $data['message']['conversation']
            ?? $data['message']['extendedTextMessage']['text']
            ?? null;

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'in',
            'tipo' => 'texto',
            'contenido' => $contenido,
            'wa_message_id' => $key['id'] ?? null,
            'timestamp' => isset($data['messageTimestamp'])
                ? Carbon::createFromTimestamp((int) $data['messageTimestamp'])
                : now(),
        ]);

        $chat->update(['ultimo_mensaje_at' => $mensaje->timestamp, 'no_leidos' => $chat->no_leidos + 1]);

        return response()->json(['ok' => true]);
    }
}