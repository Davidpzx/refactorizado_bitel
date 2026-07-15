<?php

namespace App\Jobs;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppMensaje;
use App\Services\WhatsApp\BotResponder;
use App\Services\WhatsApp\WhatsAppProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ResponderBotWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Un bot que reintenta es un bot que spamea. */
    public int $tries = 1;

    public function __construct(
        public int $chatId,
        public ?int $reglaId,
        public bool $esAsesor = false,
    ) {}

    public function handle(): void
    {
        $chat = WhatsAppChat::with('cuenta')->find($this->chatId);
        if (!$chat || !$chat->cuenta) {
            return;
        }

        // Re-verificaciones al momento de ejecutar.
        if (!$chat->cuenta->bot_activo) {
            $this->descartar('bot_apagado');
            return;
        }
        if (!$this->esAsesor && $chat->bot_silenciado_hasta && $chat->bot_silenciado_hasta->isFuture()) {
            $this->descartar('silenciado');
            return;
        }

        $humanoReciente = WhatsAppMensaje::where('chat_id', $chat->id)
            ->where('direccion', 'out')->whereNotNull('enviado_por')
            ->where('timestamp', '>=', now()->subHours(4))->exists();
        if ($humanoReciente) {
            $this->descartar('humano_respondio');
            return;
        }

        // Limites: 1/chat/minuto, 20/cuenta/hora.
        $porChat = WhatsAppMensaje::where('chat_id', $chat->id)
            ->where('direccion', 'out')->whereNull('enviado_por')
            ->where('timestamp', '>=', now()->subMinute())->count();
        if ($porChat >= 1) {
            $this->descartar('limite_chat');
            return;
        }

        $porCuenta = WhatsAppMensaje::whereIn('chat_id', WhatsAppChat::where('cuenta_id', $chat->cuenta_id)->pluck('id'))
            ->where('direccion', 'out')->whereNull('enviado_por')
            ->where('timestamp', '>=', now()->subHour())->count();
        if ($porCuenta >= 20) {
            $this->descartar('limite_cuenta');
            return;
        }

        // Resolver contenido.
        if ($this->esAsesor) {
            $tipo = 'texto';
            $contenido = BotResponder::TEXTO_ASESOR;
            $menuTitulo = '';
            $opciones = [];
        } else {
            $regla = WhatsAppBotRegla::where('activa', true)->find($this->reglaId);
            if (!$regla) {
                $this->descartar('regla_inexistente');
                return;
            }
            $tipo = $regla->tipo;
            $contenido = (string) ($regla->respuesta ?? '');
            $menuTitulo = (string) ($regla->menu_titulo ?? '');
            $opciones = $regla->opciones ?? [];
        }

        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        // Presencia "escribiendo..." proporcional al largo (3-8s).
        $largo = $tipo === 'menu' ? mb_strlen($menuTitulo) + 60 : mb_strlen($contenido);
        $delayMs = max(3000, min(8000, $largo * 60));
        $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
        usleep($delayMs * 1000);

        // Enviar (lista nativa con fallback a texto numerado).
        $textoRegistrado = $contenido;
        if ($tipo === 'menu') {
            $resultado = $provider->enviarLista($chat->cuenta->instancia, $chat->jid, $menuTitulo, $opciones);
            $lineas = [$menuTitulo];
            foreach (array_values($opciones) as $i => $op) {
                $lineas[] = ($i + 1) . '. ' . ($op['texto'] ?? '');
            }
            if ($resultado === []) {
                $lineas[] = '';
                $lineas[] = 'Responde con el número de la opción.';
                $textoRegistrado = implode("\n", $lineas);
                $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoRegistrado);
            } else {
                $textoRegistrado = implode("\n", $lineas);
            }
        } else {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $contenido);
        }

        WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => 'texto',
            'contenido' => $textoRegistrado,
            'enviado_por' => null,
            'timestamp' => now(),
        ]);
        $chat->update(['ultimo_mensaje_at' => now()]);
    }

    private function descartar(string $motivo): void
    {
        Log::info('whatsapp.bot_descartado', ['chat_id' => $this->chatId, 'motivo' => $motivo]);
    }
}
