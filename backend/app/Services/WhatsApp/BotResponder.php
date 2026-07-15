<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppMensaje;

class BotResponder
{
    public const TEXTO_ASESOR = 'Listo, un asesor te escribe en breve 👋';

    private const KEYWORDS_INTERES = [
        3 => ['portabilidad', 'cambiarme', 'quiero', 'me interesa', 'deseo'],
        2 => ['precio', 'cuanto', 'costo', 'plan', 'planes', 'promocion', 'donde', 'direccion', 'horario'],
    ];

    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    public static function puntuarInteres(string $texto): int
    {
        $t = self::normalizar($texto);
        $score = 0;
        foreach (self::KEYWORDS_INTERES as $puntos => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($t, $p)) {
                    $score += $puntos;
                }
            }
        }
        return $score;
    }

    /** @return WhatsAppBotRegla|string|null la regla, 'op_asesor', o null */
    public static function decidir(WhatsAppChat $chat, string $texto, ?string $opcionId, bool $esPrimerMensaje): WhatsAppBotRegla|string|null
    {
        $reglasVisibles = fn () => WhatsAppBotRegla::where('activa', true)
            ->where(fn ($q) => $q->where('cuenta_id', $chat->cuenta_id)->orWhereNull('cuenta_id'))
            ->orderByRaw('cuenta_id IS NULL ASC')
            ->orderByDesc('prioridad');

        // (a) Opcion de lista/boton por id.
        if ($opcionId !== null && $opcionId !== '') {
            if ($opcionId === 'op_asesor') {
                return 'op_asesor';
            }
            foreach ($reglasVisibles()->where('tipo', 'menu')->get() as $menu) {
                foreach ($menu->opciones ?? [] as $op) {
                    if (($op['id'] ?? null) === $opcionId && !empty($op['regla_id'])) {
                        return WhatsAppBotRegla::where('activa', true)->find($op['regla_id']);
                    }
                }
            }
            return null;
        }

        $t = self::normalizar($texto);

        // (b) Numero N respondiendo al ultimo menu del bot.
        if (preg_match('/^[1-9]$/', $t)) {
            $ultimoBot = WhatsAppMensaje::where('chat_id', $chat->id)
                ->where('direccion', 'out')->whereNull('enviado_por')
                ->orderByDesc('id')->value('contenido');
            if ($ultimoBot && str_contains($ultimoBot, '1.')) {
                $menu = $reglasVisibles()->where('tipo', 'menu')->first();
                $opciones = $menu->opciones ?? [];
                $idx = ((int) $t) - 1;
                if (isset($opciones[$idx])) {
                    return self::decidir($chat, '', $opciones[$idx]['id'], false);
                }
            }
        }

        // (c) Primer mensaje -> bienvenida.
        if ($esPrimerMensaje) {
            $regla = $reglasVisibles()->where('es_bienvenida', true)->first();
            if ($regla) {
                return $regla;
            }
        }

        // (d) Keywords por prioridad.
        foreach ($reglasVisibles()->where('es_bienvenida', false)->whereNotNull('palabras_clave')->get() as $regla) {
            foreach ($regla->palabras_clave ?? [] as $p) {
                if (str_contains($t, self::normalizar($p))) {
                    return $regla;
                }
            }
        }

        return null;
    }
}
