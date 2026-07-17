<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ResponderBotWhatsApp;
use App\Models\InteraccionCrm;
use App\Models\Lead;
use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use App\Services\WhatsApp\BotResponder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 1024 * 1024;

    private const RATE_LIMIT = 60;

    public function recibir(Request $request): JsonResponse
    {
        $rateLimitKey = 'whatsapp:webhook:'.hash('sha256', (string) $request->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT)) {
            return response()->json(['message' => 'Demasiadas solicitudes.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($rateLimitKey));
        }
        RateLimiter::hit($rateLimitKey, 60);

        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        if ($contentLength > self::MAX_BODY_BYTES
            || strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Payload demasiado grande.'], 413);
        }

        $tokenEsperado = (string) config('services.evolution.webhook_token');
        $tokenHeader = $request->header('X-Webhook-Token');
        // Compatibilidad temporal: el token por query string está deprecado; usar X-Webhook-Token.
        $tokenRecibido = is_string($tokenHeader) && $tokenHeader !== ''
            ? $tokenHeader
            : (string) $request->query('token', '');
        if ($tokenEsperado === '' || $tokenRecibido === '' || ! hash_equals($tokenEsperado, $tokenRecibido)) {
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

        $opcionId = $data['message']['listResponseMessage']['singleSelectReply']['selectedRowId']
            ?? $data['message']['buttonsResponseMessage']['selectedButtonId']
            ?? null;
        if ($contenido === null && $opcionId !== null) {
            $contenido = $data['message']['listResponseMessage']['title'] ?? '[opción de menú]';
        }

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

        $chat->update(['ultimo_mensaje_at' => $mensaje->timestamp]);
        $chat->increment('no_leidos');

        $this->procesarBot($cuenta, $chat, (string) ($contenido ?? ''), $opcionId);

        return response()->json(['ok' => true]);
    }

    private function procesarBot(WhatsAppCuenta $cuenta, WhatsAppChat $chat, string $texto, ?string $opcionId): void
    {
        // Scoring de interes (siempre, aunque el bot este apagado).
        $puntos = BotResponder::puntuarInteres($texto);
        if ($puntos > 0) {
            $scoreAntes = (int) $chat->interes_score;
            $chat->update(['interes_score' => $scoreAntes + $puntos]);

            if ($scoreAntes < 5 && ($scoreAntes + $puntos) >= 5 && $chat->crm_cliente_id) {
                Lead::where('cliente_id', $chat->crm_cliente_id)
                    ->whereIn('estado', ['NUEVO', 'CONTACTADO'])
                    ->update(['estado' => 'INTERESADO']);
                // agente_id 0 = accion del sistema (bot), no hay FK sobre la columna.
                InteraccionCrm::create([
                    'cliente_id' => $chat->crm_cliente_id,
                    'agente_id' => 0,
                    'tipo' => 'WHATSAPP',
                    'detalle' => 'Interés detectado por bot',
                ]);
            }
        }

        if (!$cuenta->bot_activo) {
            return;
        }

        $chat->refresh();
        if ($chat->bot_silenciado_hasta && $chat->bot_silenciado_hasta->isFuture()) {
            return;
        }

        $humanoReciente = WhatsAppMensaje::where('chat_id', $chat->id)
            ->where('direccion', 'out')->whereNotNull('enviado_por')
            ->where('timestamp', '>=', now()->subHours(4))->exists();
        if ($humanoReciente) {
            return;
        }

        $esPrimerMensaje = WhatsAppMensaje::where('chat_id', $chat->id)->where('direccion', 'in')->count() === 1;
        $decision = BotResponder::decidir($chat, $texto, $opcionId, $esPrimerMensaje);

        if ($decision === 'op_asesor') {
            $chat->update([
                'bot_silenciado_hasta' => now()->addDay(),
                'interes_score' => $chat->interes_score + 3,
            ]);
            ResponderBotWhatsApp::dispatch($chat->id, null, true)->delay(now()->addSeconds(rand(25, 90)));
        } elseif ($decision instanceof WhatsAppBotRegla) {
            ResponderBotWhatsApp::dispatch($chat->id, $decision->id, false)->delay(now()->addSeconds(rand(25, 90)));
        }
    }
}
