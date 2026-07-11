<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DetectarAsistenciasSinSenalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 11, 10, 0, 0, 'America/Lima'));

        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Agente Prueba',
            'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'hash_dispositivo' => 'device-abc',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function turno(array $overrides = []): void
    {
        DB::table('asistencias')->insert(array_merge([
            'agente_id' => 1, 'tienda_id' => 'T01', 'fecha' => '2026-07-11',
            'hora_ingreso' => '08:00:00', 'hora_salida' => null,
        ], $overrides));
    }

    private function presencia(string $capturadoEn): void
    {
        DB::table('asistencia_presencia')->insert([
            'agente_id' => 1, 'tienda_id' => 1, 'device_hash' => 'device-abc',
            'lat' => -12.046374, 'lng' => -77.042793, 'estado' => 'ok',
            'capturado_en' => $capturadoEn, 'recibido_en' => $capturadoEn,
        ]);
    }

    public function test_turno_sin_ping_reciente_crea_incidencia_sin_senal(): void
    {
        $this->turno();
        $this->artisan('bitel:detectar-sin-senal')->assertSuccessful();

        $this->assertDatabaseHas('asistencia_incidencias_ubicacion', [
            'agente_id' => 1, 'tienda_id' => 1, 'device_hash' => 'device-abc', 'tipo' => 'sin_senal',
        ]);
    }

    public function test_no_duplica_incidencia_sin_senal_de_los_ultimos_45_minutos(): void
    {
        $this->turno();
        DB::table('asistencia_incidencias_ubicacion')->insert([
            'agente_id' => 1, 'tienda_id' => 1, 'device_hash' => 'device-abc',
            'tipo' => 'sin_senal', 'capturado_en' => '2026-07-11 09:40:00',
            'created_at' => '2026-07-11 09:40:00',
        ]);

        $this->artisan('bitel:detectar-sin-senal')->assertSuccessful();
        $this->assertDatabaseCount('asistencia_incidencias_ubicacion', 1);
    }

    public function test_turno_con_ping_reciente_no_genera_incidencia(): void
    {
        $this->turno();
        $this->presencia('2026-07-11 09:30:00');
        $this->artisan('bitel:detectar-sin-senal')->assertSuccessful();
        $this->assertDatabaseCount('asistencia_incidencias_ubicacion', 0);
    }

    public function test_turno_cerrado_no_genera_incidencia(): void
    {
        $this->turno(['hora_salida' => '09:00:00']);
        $this->artisan('bitel:detectar-sin-senal')->assertSuccessful();
        $this->assertDatabaseCount('asistencia_incidencias_ubicacion', 0);
    }
}
