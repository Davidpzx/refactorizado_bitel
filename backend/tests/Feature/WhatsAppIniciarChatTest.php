<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppIniciarChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_chat_nuevo_con_la_cuenta_de_la_tienda_del_lead(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'T01', 'numero' => '1', 'instancia' => 'i1', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'Central', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => null, 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'nombre_contacto' => 'Joan',
            'tienda_id' => 'T01',
            'crm_cliente_id' => 5,
        ]);

        $response->assertOk();
        $response->assertJsonPath('cuenta_id', $cuenta->id);
        $this->assertDatabaseHas('whatsapp_chats', [
            'cuenta_id' => $cuenta->id,
            'jid' => '51917930560@s.whatsapp.net',
            'nombre_contacto' => 'Joan',
            'crm_cliente_id' => 5,
        ]);
    }

    public function test_reutiliza_chat_existente_en_vez_de_duplicar(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'T01', 'numero' => '1', 'instancia' => 'i1', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $chatExistente = WhatsAppChat::create([
            'cuenta_id' => $cuenta->id,
            'jid' => '51917930560@s.whatsapp.net',
            'nombre_contacto' => 'Joan Viejo',
            'numero_contacto' => '917930560',
            'no_leidos' => 3,
        ]);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('chat.id', $chatExistente->id);
        $this->assertSame(1, WhatsAppChat::where('jid', '51917930560@s.whatsapp.net')->count());
    }

    public function test_usa_cuenta_central_si_la_tienda_no_tiene_una_conectada(): void
    {
        $central = WhatsAppCuenta::create(['nombre' => 'Central', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => null, 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T99',
        ]);

        $response->assertOk();
        $response->assertJsonPath('cuenta_id', $central->id);
    }

    public function test_422_sin_cuenta_conectada_disponible(): void
    {
        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'sin_cuenta');
    }

    public function test_jefe_tienda_no_puede_forzar_cuenta_de_otra_tienda(): void
    {
        WhatsAppCuenta::create(['nombre' => 'T02', 'numero' => '2', 'instancia' => 'i2', 'tienda_id' => 'T02', 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/chats/iniciar', [
            'telefono' => '917930560',
            'tienda_id' => 'T02', // intenta forzar la tienda ajena
        ]);

        // No existe cuenta conectada para T01 (su tienda real) ni Central -> 422, nunca usa la de T02.
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'sin_cuenta');
    }
}
