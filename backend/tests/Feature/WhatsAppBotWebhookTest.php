<?php

namespace Tests\Feature;

use App\Jobs\ResponderBotWhatsApp;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Database\Seeders\WhatsAppBotReglasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppBotWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(array $overrides = []): array
    {
        return array_replace_recursive([
            'instance' => 'inst-bot',
            'data' => [
                'key' => ['remoteJid' => '51999000111@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG1'],
                'pushName' => 'Cliente Prueba',
                'message' => ['conversation' => 'hola'],
                'messageTimestamp' => 1700000000,
            ],
        ], $overrides);
    }

    private function cuenta(bool $botActivo = true): WhatsAppCuenta
    {
        config(['services.evolution.webhook_token' => 'tok']);
        return WhatsAppCuenta::create(['nombre' => 'Bot', 'numero' => '1', 'instancia' => 'inst-bot', 'tienda_id' => 'T01', 'estado' => 'conectada', 'bot_activo' => $botActivo]);
    }

    public function test_primer_mensaje_despacha_job_del_bot(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->cuenta();

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertPushed(ResponderBotWhatsApp::class, 1);
    }

    public function test_bot_apagado_no_despacha(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->cuenta(false);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_humano_reciente_silencia_al_bot(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $cuenta = $this->cuenta();
        $chat = WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => '51999000111@s.whatsapp.net', 'no_leidos' => 0]);
        $humano = Usuario::factory()->create();
        WhatsAppMensaje::create(['chat_id' => $chat->id, 'direccion' => 'out', 'tipo' => 'texto', 'contenido' => 'hola soy humano', 'enviado_por' => $humano->id, 'timestamp' => now()]);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_cruce_de_umbral_mueve_lead_a_interesado(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $cuenta = $this->cuenta();
        $cliente = Cliente::create(['dni_ruc' => '12345678', 'nombre' => 'Joan', 'telefono' => '999000111', 'tipo_documento' => 'DNI']);
        $lead = Lead::create(['cliente_id' => $cliente->id, 'agente_id' => Usuario::factory()->create()->id, 'tienda_id' => 'T01', 'estado' => 'NUEVO', 'fuente' => 'WHATSAPP']);
        WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => '51999000111@s.whatsapp.net', 'crm_cliente_id' => $cliente->id, 'no_leidos' => 0]);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook([
            'data' => ['message' => ['conversation' => 'quiero portabilidad, cuanto cuesta el plan']],
        ]))->assertOk();

        $this->assertSame('INTERESADO', $lead->fresh()->estado);
    }
}
