<?php

namespace Tests\Feature;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_crea_chat_y_mensaje_entrante(): void
    {
        config(['services.evolution.webhook_token' => 'secreto-webhook']);
        $cuenta = WhatsAppCuenta::create([
            'nombre' => 'A',
            'numero' => '1',
            'instancia' => 'mi-instancia',
            'estado' => 'conectada',
        ]);

        $payload = [
            'instance' => 'mi-instancia',
            'data' => [
                'key' => ['id' => 'WA999', 'remoteJid' => '51988888888@s.whatsapp.net', 'fromMe' => false],
                'pushName' => 'Juan Perez',
                'message' => ['conversation' => 'Hola, necesito info'],
                'messageTimestamp' => now()->timestamp,
            ],
        ];

        $response = $this->withHeader('X-Webhook-Token', 'secreto-webhook')
            ->postJson('/api/v1/whatsapp/webhook', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_chats', ['cuenta_id' => $cuenta->id, 'jid' => '51988888888@s.whatsapp.net']);
        $chat = WhatsAppChat::first();
        $this->assertDatabaseHas('whatsapp_mensajes', [
            'chat_id' => $chat->id,
            'direccion' => 'in',
            'contenido' => 'Hola, necesito info',
        ]);
    }

    public function test_webhook_rechaza_token_invalido(): void
    {
        config(['services.evolution.webhook_token' => 'secreto-webhook']);

        $response = $this->postJson('/api/v1/whatsapp/webhook?token=incorrecto', ['instance' => 'x']);

        $response->assertStatus(403);
    }
}
