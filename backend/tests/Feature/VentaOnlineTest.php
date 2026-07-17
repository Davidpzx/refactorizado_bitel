<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\VentaOnline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VentaOnlineTest extends TestCase
{
    use RefreshDatabase;

    private function agente(string $tienda = 'PUNDA50'): Usuario
    {
        return Usuario::factory()->agenteVentas(null, $tienda)->create();
    }

    public function test_consulta_dni_sin_token_es_401(): void
    {
        $this->getJson('/api/v1/app/consulta-dni/12345678')->assertStatus(401);
    }

    public function test_store_crea_venta_pendiente_scopeada_al_agente(): void
    {
        $agente = $this->agente('PUNDA50');

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/app/ventas', [
                'dni'             => '40404040',
                'nombres'         => 'juan perez',
                'telefono'        => '987654321',
                'operador_origen' => 'Movistar',
                'tipo'            => 'delivery_chip',
                'plan_ofrecido'   => 'Plan 39.90',
            ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('ya_contactado', false);

        $this->assertDatabaseHas('ventas_online', [
            'dni'           => '40404040',
            'nombres'       => 'JUAN PEREZ',
            'estado'        => 'pendiente',
            'tienda_codigo' => 'PUNDA50',
            'origen'        => 'app',
        ]);
    }

    public function test_store_enlaza_cliente_existente_del_crm(): void
    {
        $agente = $this->agente();
        DB::table('crm_clientes')->insert([
            'dni' => '50505050', 'nombres' => 'ANA', 'apellidos' => 'GOMEZ',
            'telefono' => '911222333', 'operadora' => 'Claro', 'fecha_registro' => now(),
        ]);
        $clienteId = (int) DB::getPdo()->lastInsertId();

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/app/ventas', [
                'dni' => '50505050', 'nombres' => 'ana gomez',
                'operador_origen' => 'Claro', 'tipo' => 'plan_online',
            ])
            ->assertStatus(201)
            ->assertJsonPath('ya_contactado', true)
            ->assertJsonPath('crm_cliente_id', $clienteId);
    }

    public function test_mias_solo_devuelve_ventas_propias(): void
    {
        $a1 = $this->agente();
        $a2 = $this->agente();

        VentaOnline::create([
            'agente_ref' => $a1->nombre, 'tienda_codigo' => 'PUNDA50', 'dni' => '11111111',
            'nombres' => 'X', 'operador_origen' => 'Entel', 'tipo' => 'delivery_chip', 'estado' => 'pendiente',
        ]);
        VentaOnline::create([
            'agente_ref' => $a2->nombre, 'tienda_codigo' => 'PUNDA50', 'dni' => '22222222',
            'nombres' => 'Y', 'operador_origen' => 'Entel', 'tipo' => 'delivery_chip', 'estado' => 'pendiente',
        ]);

        $this->actingAs($a1, 'sanctum')
            ->getJson('/api/v1/app/ventas/mias')
            ->assertOk()
            ->assertJsonCount(1, 'ventas')
            ->assertJsonPath('ventas.0.dni', '11111111');
    }

    public function test_estado_propio_ok_y_ajeno_404(): void
    {
        $dueno = $this->agente();
        $otro = $this->agente();
        $venta = VentaOnline::create([
            'agente_ref' => $dueno->nombre, 'tienda_codigo' => 'PUNDA50', 'dni' => '33333333',
            'nombres' => 'Z', 'operador_origen' => 'Bitel', 'tipo' => 'plan_online', 'estado' => 'pendiente',
        ]);

        $this->actingAs($otro, 'sanctum')
            ->patchJson("/api/v1/app/ventas/{$venta->id}/estado", ['estado' => 'exitoso'])
            ->assertStatus(404);

        $this->actingAs($dueno, 'sanctum')
            ->patchJson("/api/v1/app/ventas/{$venta->id}/estado", ['estado' => 'fallido', 'motivo_falla' => 'sin señal'])
            ->assertOk()
            ->assertJsonPath('estado', 'fallido');

        $this->assertDatabaseHas('ventas_online', ['id' => $venta->id, 'estado' => 'fallido', 'motivo_falla' => 'sin señal']);
    }

    public function test_estado_fallido_sin_motivo_es_422(): void
    {
        $agente = $this->agente();
        $venta = VentaOnline::create([
            'agente_ref' => $agente->nombre, 'tienda_codigo' => 'PUNDA50', 'dni' => '44444444',
            'nombres' => 'W', 'operador_origen' => 'Otro', 'tipo' => 'plan_online', 'estado' => 'pendiente',
        ]);

        $this->actingAs($agente, 'sanctum')
            ->patchJson("/api/v1/app/ventas/{$venta->id}/estado", ['estado' => 'fallido'])
            ->assertStatus(422);
    }

    public function test_incumplimiento_se_registra(): void
    {
        $agente = $this->agente('PUNDA50');
        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/app/incumplimiento', ['detalle' => 'abrio activa bitel directo'])
            ->assertStatus(201);

        $this->assertDatabaseHas('ventas_online_incumplimientos', [
            'agente_ref' => $agente->nombre, 'tienda_codigo' => 'PUNDA50',
        ]);
    }

    public function test_index_admin_devuelve_kpis(): void
    {
        $admin = Usuario::factory()->admin()->create();
        VentaOnline::create([
            'agente_ref' => 'A', 'tienda_codigo' => 'PUNDA50', 'dni' => '55555555',
            'nombres' => 'Q', 'operador_origen' => 'Movistar', 'tipo' => 'delivery_chip', 'estado' => 'exitoso',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ventas-online')
            ->assertOk()
            ->assertJsonPath('kpis.total', 1)
            ->assertJsonPath('kpis.exitosos', 1)
            ->assertJsonPath('kpis.pct_exito', 100);
    }

    public function test_index_jefe_tienda_scopeado_a_su_tienda(): void
    {
        $jefe = Usuario::factory()->jefeTienda('PUNDA50')->create();
        VentaOnline::create([
            'agente_ref' => 'A', 'tienda_codigo' => 'PUNDA50', 'dni' => '66666666',
            'nombres' => 'Q', 'operador_origen' => 'Movistar', 'tipo' => 'delivery_chip', 'estado' => 'pendiente',
        ]);
        VentaOnline::create([
            'agente_ref' => 'B', 'tienda_codigo' => 'OTRA99', 'dni' => '77777777',
            'nombres' => 'R', 'operador_origen' => 'Claro', 'tipo' => 'plan_online', 'estado' => 'pendiente',
        ]);

        $this->actingAs($jefe, 'sanctum')
            ->getJson('/api/v1/ventas-online')
            ->assertOk()
            ->assertJsonPath('kpis.total', 1)
            ->assertJsonCount(1, 'ventas');
    }
}
