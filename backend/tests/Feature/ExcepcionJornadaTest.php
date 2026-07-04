<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy gerencia/ajax_excepcion_jornada.php: toggle de excepciones_jornada
 * (MEDIO_TIEMPO/TURNO_LIBRE/OTRO) por agente/fecha.
 */
class ExcepcionJornadaTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(): int
    {
        return (int) DB::table('agentes')->insertGetId([
            'dni' => '12345678',
            'nombres' => 'Agente Prueba',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
        ]);
    }

    public function test_primer_toggle_crea_la_excepcion(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-10',
            ])
            ->assertCreated()
            ->assertJsonPath('estado', 'on');

        $this->assertDatabaseHas('excepciones_jornada', [
            'agente_id' => $agenteId,
            'fecha' => '2026-07-10',
            'tipo' => 'MEDIO_TIEMPO',
        ]);
    }

    public function test_segundo_toggle_elimina_la_excepcion(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', ['agente_id' => $agenteId, 'fecha' => '2026-07-10'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', ['agente_id' => $agenteId, 'fecha' => '2026-07-10'])
            ->assertOk()
            ->assertJsonPath('estado', 'off');

        $this->assertDatabaseMissing('excepciones_jornada', [
            'agente_id' => $agenteId,
            'fecha' => '2026-07-10',
        ]);
    }

    public function test_no_admin_no_puede_alternar_excepcion(): void
    {
        $tienda = Usuario::factory()->create(['rol' => 'tienda']);
        $agenteId = $this->crearAgente();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', ['agente_id' => $agenteId, 'fecha' => '2026-07-10'])
            ->assertStatus(403);

        $this->assertDatabaseMissing('excepciones_jornada', ['agente_id' => $agenteId]);
    }

    public function test_valida_tipo_y_fecha(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', [
                'agente_id' => $agenteId,
                'fecha' => '2026-07-10',
                'tipo' => 'INVALIDO',
            ])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/asistencias/excepcion-jornada', [
                'agente_id' => $agenteId,
                'fecha' => 'no-es-fecha',
            ])
            ->assertStatus(422);
    }
}
