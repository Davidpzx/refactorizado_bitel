<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy gerencia/control_asistencias.php: matriz mensual agente × día,
 * agrupada por tienda, con estados resueltos en servidor.
 */
class AsistenciaMatrizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-07-10 es viernes; el agente descansa domingo.
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 12, 0, 0, 'America/Lima'));

        DB::table('tiendas')->insert(['codigo' => 'T01', 'nombre' => 'Tienda Uno', 'radio_permitido' => 60]);

        DB::table('agentes')->insert([
            'id' => 1,
            'dni' => '12345678',
            'nombres' => 'Agente Uno',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
            'dia_descanso' => 'DOMINGO',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_admin_no_puede_ver_la_matriz(): void
    {
        $tienda = Usuario::factory()->create(['rol' => 'tienda']);

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/asistencias/matriz?mes=2026-07')
            ->assertStatus(403);
    }

    public function test_matriz_agrupa_por_tienda_y_resuelve_estados(): void
    {
        $admin = Usuario::factory()->admin()->create();

        // Día 5 (domingo): descanso, sin marcación → DESCANSO.
        // Día 6 (lunes): tardanza de 15 min → TARDANZA.
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-06',
            'hora_ingreso' => '08:15:00',
            'minutos_tardanza' => 15,
            'estado_asistencia' => 'TARDANZA',
        ]);
        // Día 7 (martes): falta injustificada → FALTA.
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-07',
            'estado_asistencia' => 'FALTA_INJUSTIFICADA',
        ]);
        // Día 8 (miércoles): excepción MEDIO_TIEMPO → MEDIO_TIEMPO.
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-08',
            'hora_ingreso' => '08:00:00',
            'minutos_tardanza' => 0,
            'estado_asistencia' => 'REGULAR',
        ]);
        DB::table('excepciones_jornada')->insert([
            'agente_id' => 1,
            'fecha' => '2026-07-08',
            'tipo' => 'MEDIO_TIEMPO',
            'horas_trabajadas' => 4,
        ]);
        // Día 9 (jueves): marcación regular sin tardanza → OK.
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-09',
            'hora_ingreso' => '08:00:00',
            'minutos_tardanza' => 0,
            'estado_asistencia' => 'REGULAR',
        ]);
        // Día 10 (hoy, viernes): sin marcación aún → SIN_MARCA.

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/asistencias/matriz?mes=2026-07')
            ->assertOk()
            ->assertJsonPath('mes', '2026-07')
            ->assertJsonPath('dias_mostrar', 10)
            ->assertJsonPath('tiendas.0.tienda', 'T01')
            ->assertJsonPath('tiendas.0.agentes.0.nombre', 'Agente Uno');

        $dias = $response->json('tiendas.0.agentes.0.dias');

        $this->assertSame('DESCANSO', $dias['5']['estado']);
        $this->assertSame('TARDANZA', $dias['6']['estado']);
        $this->assertSame(15, $dias['6']['minutos_tardanza']);
        $this->assertSame('FALTA', $dias['7']['estado']);
        $this->assertSame('MEDIO_TIEMPO', $dias['8']['estado']);
        $this->assertSame('MEDIO_TIEMPO', $dias['8']['excepcion_tipo']);
        $this->assertSame('OK', $dias['9']['estado']);
        $this->assertSame('SIN_MARCA', $dias['10']['estado']);
    }

    public function test_mes_actual_no_muestra_dias_futuros(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/asistencias/matriz?mes=2026-07')
            ->assertOk()
            ->assertJsonPath('dias_mostrar', 10);
    }

    public function test_mes_pasado_muestra_todos_los_dias_del_mes(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/asistencias/matriz?mes=2026-06')
            ->assertOk()
            ->assertJsonPath('dias_mostrar', 30);
    }
}
