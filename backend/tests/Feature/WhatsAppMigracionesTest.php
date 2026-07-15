<?php

namespace Tests\Feature;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMigracionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_cuenta_chat_y_mensaje_encadenados(): void
    {
        $cuenta = WhatsAppCuenta::create([
            'nombre' => 'Tienda Centro',
            'numero' => '+51999999999',
            'instancia' => 'tienda-centro',
            'provider' => 'evolution',
            'tienda_id' => 'T01',
            'estado' => 'qr_pendiente',
        ]);

        $chat = WhatsAppChat::create([
            'cuenta_id' => $cuenta->id,
            'jid' => '51988888888@s.whatsapp.net',
            'nombre_contacto' => 'Juan Pérez',
            'numero_contacto' => '+51988888888',
            'no_leidos' => 0,
        ]);

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'in',
            'tipo' => 'texto',
            'contenido' => 'Hola, quiero un plan',
            'wa_message_id' => 'ABC123',
            'timestamp' => now(),
        ]);

        $this->assertTrue($cuenta->chats->contains($chat));
        $this->assertTrue($chat->mensajes->contains($mensaje));
        $this->assertSame('Tienda Centro', $mensaje->chat->cuenta->nombre);
    }
}
