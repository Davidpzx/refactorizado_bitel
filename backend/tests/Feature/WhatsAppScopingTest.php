<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_tienda_solo_ve_cuentas_de_su_tienda(): void
    {
        WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);

        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/whatsapp/cuentas');

        $response->assertOk();
        $nombres = collect($response->json())->pluck('nombre');
        $this->assertTrue($nombres->contains('A'));
        $this->assertFalse($nombres->contains('B'));
    }

    public function test_admin_ve_todas_las_cuentas(): void
    {
        WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);

        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/whatsapp/cuentas');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    public function test_jefe_tienda_recibe_403_al_pedir_chats_de_cuenta_ajena(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/whatsapp/chats?cuenta_id={$cuenta->id}");

        $response->assertStatus(403);
    }

    public function test_no_admin_recibe_403_al_crear_cuenta(): void
    {
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/cuentas', [
            'nombre' => 'Nueva',
            'numero' => '+51900000000',
            'tienda_id' => 'T01',
        ]);

        $response->assertStatus(403);
    }
}
