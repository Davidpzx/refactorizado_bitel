<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Paridad con el cron legacy `cron/cron_salida_automatica.php`:
 *   - espera 90 min tras la hora de salida programada (turnos de hoy),
 *   - resguardo de horario inválido (salida_prog <= hora_ingreso): no cierra,
 *   - alcance a días anteriores (fecha <= hoy).
 */
class SalidaAutomaticaAsistenciasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hora base: 2026-06-11 08:00 hora Lima (misma zona que usa el módulo de asistencia).
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 8, 0, 0, 'America/Lima'));

        DB::table('tiendas')->insert([
            'codigo' => 'T01',
            'nombre' => 'Tienda Uno',
            'radio_permitido' => 60,
        ]);

        DB::table('agentes')->insert([
            'id' => 1,
            'dni' => '12345678',
            'nombres' => 'Agente Prueba',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '18:00:00',
            'hora_ref_inicio' => '12:00:00',
            'hora_ref_fin' => '13:00:00',
            'dia_descanso' => 'DOMINGO',
            'sueldo_base' => 1200,
        ]);

        // El comando crea sys_notificaciones con DDL de MySQL; en sqlite la pre-creamos
        // para que el comando la detecte y omita ese DDL no portable.
        Schema::create('sys_notificaciones', function ($table) {
            $table->increments('id');
            $table->string('tipo', 50)->default('alerta_asistencia');
            $table->text('mensaje');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->string('estado', 20)->default('pendiente');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function abrirAsistencia(string $fecha, string $horaIngreso, array $extra = []): int
    {
        return DB::table('asistencias')->insertGetId(array_merge([
            'agente_id' => 1,
            'fecha' => $fecha,
            'tienda_id' => 'T01',
            'hora_ingreso' => $horaIngreso,
            'hora_salida' => null,
        ], $extra));
    }

    /** (a) No cierra antes de hora_salida programada + 90 min. */
    public function test_no_cierra_antes_de_salida_mas_90_minutos(): void
    {
        // Salida programada 18:00 → límite 19:30. Ahora 19:00 (aún dentro de la espera).
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 19, 0, 0, 'America/Lima'));
        $id = $this->abrirAsistencia('2026-06-11', '08:00:00');

        $this->artisan('bitel:salida-automatica')->assertExitCode(0);

        $this->assertNull(DB::table('asistencias')->where('id', $id)->value('hora_salida'));
    }

    /** (b) Sí cierra pasados 90 min desde la hora_salida programada. */
    public function test_cierra_pasados_90_minutos_desde_salida(): void
    {
        // Salida programada 18:00 → límite 19:30. Ahora 19:31 (ya pasó el límite).
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 19, 31, 0, 'America/Lima'));
        $id = $this->abrirAsistencia('2026-06-11', '08:00:00');

        $this->artisan('bitel:salida-automatica')->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'hora_salida' => '18:00:00',
            'estado_asistencia' => 'CIERRE_AUTO',
        ]);
        $this->assertDatabaseHas('sys_notificaciones', ['tipo' => 'alerta_asistencia']);
    }

    /** (c) Horario inválido (salida_prog <= hora_ingreso): no cierra, notifica a gerencia. */
    public function test_horario_invalido_no_se_usa_para_cerrar(): void
    {
        DB::table('agentes')->where('id', 1)->update(['hora_salida' => '07:00:00']);
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 23, 0, 0, 'America/Lima'));
        $id = $this->abrirAsistencia('2026-06-11', '08:00:00');

        $this->artisan('bitel:salida-automatica')->assertExitCode(0);

        $this->assertNull(DB::table('asistencias')->where('id', $id)->value('hora_salida'));
        $this->assertDatabaseHas('sys_notificaciones', ['tipo' => 'horario_invalido']);
    }

    /** (d) Asistencia abierta de un día anterior sí se cierra (sin espera de 90 min). */
    public function test_cierra_asistencia_de_dia_anterior(): void
    {
        // Ahora 2026-06-11 08:00; la asistencia quedó abierta ayer.
        $id = $this->abrirAsistencia('2026-06-10', '08:00:00');

        $this->artisan('bitel:salida-automatica')->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'hora_salida' => '18:00:00',
            'estado_asistencia' => 'CIERRE_AUTO',
        ]);
    }

    /** (e) Turno nocturno (salida < ingreso) no se cierra prematuramente. */
    public function test_turno_nocturno_no_se_cierra_prematuramente(): void
    {
        // Turno nocturno: ingresa 22:00, sale 06:00 del día siguiente.
        DB::table('agentes')->where('id', 1)->update(['hora_salida' => '06:00:00']);
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 23, 0, 0, 'America/Lima'));
        $id = $this->abrirAsistencia('2026-06-11', '22:00:00');

        $this->artisan('bitel:salida-automatica')->assertExitCode(0);

        // Como en el legacy: salida (06:00) <= ingreso (22:00) → no cierra, avisa a gerencia.
        $this->assertNull(DB::table('asistencias')->where('id', $id)->value('hora_salida'));
        $this->assertDatabaseHas('sys_notificaciones', ['tipo' => 'horario_invalido']);
    }
}
