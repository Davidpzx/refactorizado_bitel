<?php

namespace App\Jobs;

use App\Models\InventarioTienda;
use App\Models\WhatsAppBotFotoProducto;
use App\Models\WhatsAppBotPromocion;
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

        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        if ($this->esAsesor) {
            $largo = mb_strlen(BotResponder::TEXTO_ASESOR);
            $delayMs = max(3000, min(8000, $largo * 60));
            $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
            usleep($delayMs * 1000);
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, BotResponder::TEXTO_ASESOR);
            $this->registrarMensaje($chat, BotResponder::TEXTO_ASESOR);
            return;
        }

        $regla = WhatsAppBotRegla::where('activa', true)->find($this->reglaId);
        if (!$regla) {
            $this->descartar('regla_inexistente');
            return;
        }

        $largoBase = mb_strlen((string) ($regla->respuesta ?? ''));
        $delayMs = max(3000, min(8000, $largoBase * 60));
        $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
        usleep($delayMs * 1000);

        if ($regla->usa_promocion_dinamica) {
            $this->enviarPromocionDinamica($chat, $provider, (string) $regla->respuesta);
            return;
        }

        if ($regla->tipo === 'equipos') {
            $this->enviarCatalogoEquipos($chat, $provider, (string) $regla->respuesta);
            return;
        }

        $tipo = $regla->tipo;
        $contenido = (string) ($regla->respuesta ?? '');
        $menuTitulo = (string) ($regla->menu_titulo ?? '');
        $opciones = $regla->opciones ?? [];

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

        $this->registrarMensaje($chat, $textoRegistrado);
    }

    private function enviarPromocionDinamica(WhatsAppChat $chat, $provider, string $textoFallback): void
    {
        $promo = WhatsAppBotPromocion::find(1);
        if (!$promo || trim((string) $promo->texto) === '') {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoFallback);
            $this->registrarMensaje($chat, $textoFallback);
            return;
        }

        if ($promo->foto_base64) {
            $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $promo->foto_base64);
            try {
                $provider->enviarMedia($chat->cuenta->instancia, $chat->jid, $base64, 'image', $promo->texto);
            } catch (\RuntimeException $e) {
                $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $promo->texto);
            }
        } else {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $promo->texto);
        }
        $this->registrarMensaje($chat, $promo->texto);
    }

    private function enviarCatalogoEquipos(WhatsAppChat $chat, $provider, string $textoFallback): void
    {
        $tiendaId = $chat->cuenta->tienda_id;

        $modelos = InventarioTienda::query()
            ->where('tipo', 'EQUIPO')->where('estado', 'DISPONIBLE')->where('cantidad', '>', 0)
            ->when($tiendaId !== null, fn ($q) => $q->where('tienda_id', $tiendaId))
            ->selectRaw('producto_nombre, SUM(cantidad) as stock, MAX(precio_normal) as precio')
            ->groupBy('producto_nombre')->orderByDesc('stock')->limit(5)->get();

        if ($modelos->isEmpty()) {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoFallback);
            $this->registrarMensaje($chat, $textoFallback);
            return;
        }

        $sinFoto = [];
        foreach ($modelos as $m) {
            $caption = $m->producto_nombre.' — S/'.number_format((float) $m->precio, 2);
            $foto = WhatsAppBotFotoProducto::where('producto_nombre', $m->producto_nombre)->value('foto_base64');

            if ($foto) {
                $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $foto);
                try {
                    $provider->enviarMedia($chat->cuenta->instancia, $chat->jid, $base64, 'image', $caption);
                    $this->registrarMensaje($chat, $caption);
                    usleep(rand(1000, 2000) * 1000);
                } catch (\RuntimeException $e) {
                    $sinFoto[] = $caption;
                }
            } else {
                $sinFoto[] = $caption;
            }
        }

        if (!empty($sinFoto)) {
            $texto = "Otros modelos disponibles:\n".implode("\n", $sinFoto);
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $texto);
            $this->registrarMensaje($chat, $texto);
        }
    }

    private function registrarMensaje(WhatsAppChat $chat, string $texto): void
    {
        WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => 'texto',
            'contenido' => $texto,
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
