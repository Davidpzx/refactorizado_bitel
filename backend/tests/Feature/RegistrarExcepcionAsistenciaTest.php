<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy (gerencia/control_asistencias.php + confirmación 540 min): promueve a test
 * permanente el hallazgo OBS-027B-02 del QA funcional 027B — la ruta POST /asistencias/excepcion
 * (AsistenciaController::registrarExcepcion, routes/api.php:431) solo se había verificado con un
 * test efímero. PERMISO ⇒ minutos_deuda=540 (9h, fuerza recuperación); FALTA_INJUSTIFICADA ⇒ 0;
 * PERDONAR borra el registro negativo del día; no se admite duplicar el día; ruta admin-only.
 */
class RegistrarExcepcionAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(string $tienda = 'T01'): int
    {
        return (int) DB::table('agentes')->insertGetId([
            'dni' => '87654321',
            'nombres' => 'Agente Excepcion',
            'estado' => 'ACTIVO',
            'tienda_base' => $tienda,
        ]);
    }

    public function test_permiso_registra_deuda_de_540_minutos(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERMISO',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => $agenteId,
            'fecha' => '2026-07-01',
            'estado_asistencia' => 'PERMISO',
            'minutos_deuda' => 540,
        ]);
    }

    public function test_falta_injustificada_no_genera_deuda_de_minutos(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'FALTA_INJUSTIFICADA',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => $agenteId,
            'fecha' => '2026-07-01',
            'estado_asistencia' => 'FALTA_INJUSTIFICADA',
            'minutos_deuda' => 0,
        ]);
    }

    public function test_no_duplica_excepcion_el_mismo_dia(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERMISO',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'FALTA_INJUSTIFICADA',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(1, DB::table('asistencias')
            ->where('agente_id', $agenteId)->whereDate('fecha', '2026-07-01')->count());
    }

    public function test_perdonar_borra_el_registro_negativo_del_dia(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERMISO',
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERDONAR',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('asistencias', [
            'agente_id' => $agenteId,
            'fecha' => '2026-07-01',
        ]);
    }

    public function test_perdonar_sin_registro_previo_no_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERDONAR',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_solo_admin_puede_registrar_excepcion(): void
    {
        $tienda = Usuario::factory()->create(['rol' => 'tienda']);
        $agenteId = $this->crearAgente();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'PERMISO',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('asistencias', ['agente_id' => $agenteId]);
    }

    public function test_estado_invalido_devuelve_422(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-01',
                'estado' => 'INVALIDO',
            ])
            ->assertStatus(422);
    }
}
