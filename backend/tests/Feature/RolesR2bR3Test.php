<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Reporte;
use App\Models\Tienda;
use App\Models\TrasladoStock;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Plan 16 — R2b (checks hardcodeados de rol reemplazados por el mecanismo
 * canónico) + R3 (scoping "solo lo mío" del rol agente).
 *
 * R2b: verifica que los checks que antes comparaban literalmente contra
 * `'admin'` en los controladores (no en las rutas, eso ya lo cubrió R2) ahora
 * dejan pasar también al gerente donde la matriz de plan/16 lo exige —
 * concretamente TrasladoController::gestionar (aprobar traslado) y
 * AgenteController::tokenSeguridad (token de emergencia).
 *
 * R3: el rol agente sólo ve/crea lo suyo por `usuarios.agente_id`, nunca por
 * nombre de otro agente, y recibe 403 claro si su usuario no está vinculado.
 */
class RolesR2bR3Test extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(string $dni = '40404040', ?string $tiendaBase = 'PUNDA50'): Agente
    {
        return Agente::create([
            'dni' => $dni, 'nombres' => 'Agente Prueba', 'estado' => 'ACTIVO',
            'es_gerencia' => '0', 'tienda_base' => $tiendaBase,
        ]);
    }

    // ── R2b: TrasladoController::gestionar (aprobar_traslados) ──────────────

    public function test_gerente_aprueba_traslado_200(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $traslado = TrasladoStock::create([
            'producto_id' => 1,
            'tienda_origen' => 'PUNDA50',
            'tienda_destino' => 'DEST01',
            'cantidad' => 1,
            'estado' => 'PENDIENTE_APROBACION',
            'creado_por' => $gerente->id,
        ]);

        $this->actingAs($gerente, 'sanctum')
            ->postJson("/api/v1/traslados/{$traslado->id}/gestionar", ['action' => 'aprobar'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ── R2b: AgenteController::tokenSeguridad (modificar_asistencias) ──────

    public function test_gerente_genera_token_de_emergencia_200(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $agente = $this->crearAgente();

        $this->actingAs($gerente, 'sanctum')
            ->postJson("/api/v1/agentes/{$agente->id}/token-seguridad", ['tipo' => 'diario'])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_jefe_tienda_403_generando_token_de_emergencia(): void
    {
        $jefeTienda = Usuario::factory()->jefeTienda()->create();
        $agente = $this->crearAgente();

        $this->actingAs($jefeTienda, 'sanctum')
            ->postJson("/api/v1/agentes/{$agente->id}/token-seguridad", ['tipo' => 'diario'])
            ->assertForbidden();
    }

    // ── R3: mis-reportes filtra SIEMPRE por agente_id, no por usuario_id ────

    public function test_agente_ve_solo_sus_reportes_en_mis_reportes(): void
    {
        $agenteMio = $this->crearAgente('11111111');
        $agenteOtro = $this->crearAgente('22222222', 'PUNDA51');
        $usuarioAgente = Usuario::factory()->agenteVentas($agenteMio->id)->create();

        Reporte::create([
            'agente_id' => $agenteMio->id, 'tienda_id' => 'PUNDA50',
            'usuario_id' => $usuarioAgente->id, 'fecha' => now()->toDateString(),
            'estado' => 'enviado',
        ]);
        // Reporte de otro agente, registrado por OTRO usuario (usuario_id distinto):
        // si el scoping siguiera usando usuario_id no se filtraría por este caso,
        // pero agente_id sí debe excluirlo igual.
        Reporte::create([
            'agente_id' => $agenteOtro->id, 'tienda_id' => 'PUNDA51',
            'usuario_id' => Usuario::factory()->jefeTienda('PUNDA51')->create()->id,
            'fecha' => now()->toDateString(), 'estado' => 'enviado',
        ]);

        $response = $this->actingAs($usuarioAgente, 'sanctum')
            ->getJson('/api/v1/reportes/mis-reportes')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($agenteMio->id, $data[0]['agente_id']);
    }

    public function test_agente_no_puede_listar_reportes_globales(): void
    {
        $agente = $this->crearAgente();
        $usuarioAgente = Usuario::factory()->agenteVentas($agente->id)->create();

        $this->actingAs($usuarioAgente, 'sanctum')
            ->getJson('/api/v1/reportes')
            ->assertForbidden();
    }

    public function test_agente_sin_agente_id_recibe_403_en_mis_reportes(): void
    {
        $usuarioAgente = Usuario::factory()->agenteVentas(null)->create();

        $this->actingAs($usuarioAgente, 'sanctum')
            ->getJson('/api/v1/reportes/mis-reportes')
            ->assertForbidden();
    }

    // ── R3: borrador de reporte scoping por agente_id ───────────────────────

    public function test_agente_crea_su_borrador(): void
    {
        $agente = $this->crearAgente();
        $usuarioAgente = Usuario::factory()->agenteVentas($agente->id)->create();
        DB::table('asistencias')->insert([
            'agente_id' => $agente->id,
            'fecha' => now()->toDateString(),
            'hora_ingreso' => now()->toTimeString(),
        ]);

        $this->actingAs($usuarioAgente, 'sanctum')
            ->postJson('/api/v1/reportes/borrador', ['foo' => 'bar'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('reportes_borradores', [
            'agente_id' => $agente->id,
            'tienda_id' => 'PUNDA50',
        ]);
    }

    public function test_agente_sin_agente_id_recibe_403_en_borrador(): void
    {
        $usuarioAgente = Usuario::factory()->agenteVentas(null)->create();

        $this->actingAs($usuarioAgente, 'sanctum')
            ->postJson('/api/v1/reportes/borrador', ['foo' => 'bar'])
            ->assertForbidden();
    }
}
