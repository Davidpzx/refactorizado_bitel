<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_activar_bot(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/whatsapp/cuentas/{$cuenta->id}/bot", ['bot_activo' => true])
            ->assertOk();

        $this->assertTrue($cuenta->fresh()->bot_activo);
    }

    public function test_no_admin_recibe_403_en_toggle_y_crud(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $jefe = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $this->actingAs($jefe, 'sanctum')->patchJson("/api/v1/whatsapp/cuentas/{$cuenta->id}/bot", ['bot_activo' => true])->assertStatus(403);
        $this->actingAs($jefe, 'sanctum')->postJson('/api/v1/whatsapp/bot-reglas', ['nombre' => 'X', 'tipo' => 'texto', 'respuesta' => 'y'])->assertStatus(403);
    }

    public function test_admin_crud_de_reglas(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $crear = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/whatsapp/bot-reglas', [
            'nombre' => 'Regla X', 'tipo' => 'texto', 'palabras_clave' => ['hola'], 'respuesta' => 'Hola!', 'prioridad' => 5, 'activa' => true,
        ]);
        $crear->assertOk();
        $id = $crear->json('id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/whatsapp/bot-reglas/{$id}", [
            'nombre' => 'Regla X2', 'tipo' => 'texto', 'palabras_clave' => ['hola'], 'respuesta' => 'Hola!!', 'prioridad' => 5, 'activa' => false,
        ])->assertOk();
        $this->assertSame('Regla X2', WhatsAppBotRegla::find($id)->nombre);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/whatsapp/bot-reglas')->assertOk()->assertJsonCount(1);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/whatsapp/bot-reglas/{$id}")->assertOk();
        $this->assertNull(WhatsAppBotRegla::find($id));
    }
}
