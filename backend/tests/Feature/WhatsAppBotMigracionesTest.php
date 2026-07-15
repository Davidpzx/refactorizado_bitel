<?php

namespace Tests\Feature;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotMigracionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_regla_y_campos_de_bot(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada', 'bot_activo' => true]);
        $chat = WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => 'x@s.whatsapp.net', 'no_leidos' => 0, 'interes_score' => 7]);

        $regla = WhatsAppBotRegla::create([
            'nombre' => 'Planes',
            'tipo' => 'texto',
            'palabras_clave' => ['precio', 'planes'],
            'respuesta' => 'Nuestros planes...',
            'prioridad' => 10,
        ]);

        $this->assertTrue($cuenta->fresh()->bot_activo);
        $this->assertSame(7, $chat->fresh()->interes_score);
        $this->assertSame(['precio', 'planes'], $regla->fresh()->palabras_clave);
    }
}
