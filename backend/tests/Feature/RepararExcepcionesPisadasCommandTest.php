<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad con el cron legacy `cron/reparar_excepciones_pisadas.php`: repara
 * filas PERMISO/FALTA_INJUSTIFICADA que el auto-cierre pisó (CIERRE_AUTO +
 * hora_salida falsa) antes de que SalidaAutomaticaAsistencias excluyera estas
 * filas de su consulta.
 */
class RepararExcepcionesPisadasCommandTest extends TestCase
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

    private function filaPisada(int $agenteId, string $fecha, int $minutosDeuda): int
    {
        // Sin columna latitud_ingreso (no está en el esquema de migraciones, solo
        // en instalaciones que adoptaron la tabla legacy): el comando cae al
        // sentinel hora_ingreso='00:00:00' + estado_asistencia='CIERRE_AUTO'.
        return (int) DB::table('asistencias')->insertGetId([
            'agente_id' => $agenteId,
            'fecha' => $fecha,
            'tienda_id' => 'T01',
            'hora_ingreso' => '00:00:00',
            'hora_salida' => '18:00:00',
            'estado_asistencia' => 'CIERRE_AUTO',
            'minutos_deuda' => $minutosDeuda,
        ]);
    }

    public function test_dry_run_no_modifica_nada(): void
    {
        $agenteId = $this->crearAgente();
        $id = $this->filaPisada($agenteId, '2026-06-10', 540);

        $this->artisan('bitel:reparar-excepciones-pisadas')->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'estado_asistencia' => 'CIERRE_AUTO',
            'hora_salida' => '18:00:00',
        ]);
    }

    public function test_apply_repara_permiso_por_deuda_alta(): void
    {
        $agenteId = $this->crearAgente();
        $id = $this->filaPisada($agenteId, '2026-06-10', 540);

        $this->artisan('bitel:reparar-excepciones-pisadas', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'estado_asistencia' => 'PERMISO',
            'hora_salida' => null,
        ]);
    }

    public function test_apply_repara_falta_injustificada_por_deuda_baja(): void
    {
        $agenteId = $this->crearAgente();
        $id = $this->filaPisada($agenteId, '2026-06-10', 0);

        $this->artisan('bitel:reparar-excepciones-pisadas', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'estado_asistencia' => 'FALTA_INJUSTIFICADA',
            'hora_salida' => null,
        ]);
    }

    public function test_no_toca_filas_normales_de_cierre_auto(): void
    {
        $agenteId = $this->crearAgente();
        $id = (int) DB::table('asistencias')->insertGetId([
            'agente_id' => $agenteId,
            'fecha' => '2026-06-10',
            'tienda_id' => 'T01',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '18:00:00',
            'estado_asistencia' => 'CIERRE_AUTO',
        ]);

        $this->artisan('bitel:reparar-excepciones-pisadas', ['--apply' => true])->assertExitCode(0);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'estado_asistencia' => 'CIERRE_AUTO',
            'hora_salida' => '18:00:00',
        ]);
    }

    public function test_sin_candidatas_no_falla(): void
    {
        $this->artisan('bitel:reparar-excepciones-pisadas')->assertExitCode(0);
    }
}
