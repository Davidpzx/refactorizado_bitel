<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * APP-04 — ping de presencia por ubicación + semáforo admin.
 */
class AsistenciaPresenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 2026-07-10 es viernes (el agente descansa domingo).
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 10, 0, 0, 'America/Lima'));

        DB::table('tiendas')->insert([
            'codigo' => 'T01',
            'nombre' => 'Tienda Uno',
            'radio_permitido' => 60,
            'lat_centro' => -12.046374,
            'lng_centro' => -77.042793,
        ]);

        DB::table('agentes')->insert([
            'id' => 1,
            'dni' => '12345678',
            'nombres' => 'Agente Prueba',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO',
            'hash_dispositivo' => 'device-abc',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function abrirTurno(bool $omitirRefrigerio = false): void
    {
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-10',
            'hora_ingreso' => '08:00:00',
            'omitio_refrigerio' => $omitirRefrigerio,
        ]);
    }

    private function pingPayload(array $overrides = []): array
    {
        return array_merge([
            'dni' => '12345678',
            'device_hash' => 'device-abc',
            'lat' => -12.046374,
            'lng' => -77.042793,
            'accuracy' => 10,
            'battery_pct' => 80,
            'capturado_en' => '2026-07-10 10:00:00',
        ], $overrides);
    }

    public function test_ping_dentro_de_rango_upsertea_presencia_sin_incidencia(): void
    {
        $this->abrirTurno();

        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload())
            ->assertOk()
            ->assertJsonPath('estado', 'ok');

        $this->assertDatabaseHas('asistencia_presencia', [
            'agente_id' => 1,
            'estado' => 'ok',
            'battery_pct' => 80,
        ]);
        $this->assertDatabaseCount('asistencia_incidencias_ubicacion', 0);

        // Segundo ping: se sobreescribe la misma fila (una sola por agente).
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 10, 30, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload([
            'battery_pct' => 55,
            'capturado_en' => '2026-07-10 10:30:00',
        ]))->assertOk();

        $this->assertDatabaseCount('asistencia_presencia', 1);
        $this->assertDatabaseHas('asistencia_presencia', [
            'agente_id' => 1,
            'battery_pct' => 55,
        ]);
    }

    public function test_ping_fuera_de_rango_crea_incidencia(): void
    {
        $this->abrirTurno();

        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload([
            'lat' => -12.121076,
            'lng' => -77.029757,
        ]))
            ->assertOk()
            ->assertJsonPath('estado', 'fuera_de_rango');

        $this->assertDatabaseHas('asistencia_presencia', [
            'agente_id' => 1,
            'estado' => 'fuera_de_rango',
        ]);
        $this->assertDatabaseHas('asistencia_incidencias_ubicacion', [
            'agente_id' => 1,
            'tipo' => 'fuera_de_rango',
        ]);
    }

    public function test_ping_mock_gps_crea_incidencia(): void
    {
        $this->abrirTurno();

        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload([
            'mock_gps' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('estado', 'mock_gps');

        $this->assertDatabaseHas('asistencia_incidencias_ubicacion', [
            'agente_id' => 1,
            'tipo' => 'mock_gps',
        ]);
    }

    public function test_ping_con_device_hash_incorrecto_es_403(): void
    {
        $this->abrirTurno();

        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload([
            'device_hash' => 'device-otro',
        ]))
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_MISMATCH');

        $this->assertDatabaseCount('asistencia_presencia', 0);
    }

    public function test_ping_sin_turno_abierto_es_422(): void
    {
        // Sin insertar asistencia: no hay turno abierto.
        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_OPEN_SHIFT');
    }

    public function test_ping_con_turno_ya_cerrado_es_422(): void
    {
        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-10',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '17:00:00',
        ]);

        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_OPEN_SHIFT');
    }

    public function test_admin_lista_presencia_de_agentes_en_turno(): void
    {
        $this->abrirTurno();
        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload())->assertOk();

        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/asistencias-admin/presencia')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.agente_id', 1)
            ->assertJsonPath('data.0.estado', 'ok')
            ->assertJsonPath('data.0.tienda', 'T01')
            ->assertJsonPath('data.0.battery_pct', 80)
            ->assertJsonPath('data.0.incidencias_dia', 0);
    }

    public function test_presencia_incluye_conteo_de_incidencias_del_dia(): void
    {
        $this->abrirTurno();
        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload([
            'lat' => -12.121076,
            'lng' => -77.029757,
        ]))->assertOk();

        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/asistencias-admin/presencia')
            ->assertOk()
            ->assertJsonPath('data.0.estado', 'fuera_de_rango')
            ->assertJsonPath('data.0.incidencias_dia', 1);
    }

    public function test_presencia_exige_rol_admin(): void
    {
        $tienda = Usuario::factory()->create(['rol' => 'tienda']);

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/asistencias-admin/presencia')
            ->assertStatus(403);
    }

    public function test_marcar_salida_borra_la_fila_de_presencia(): void
    {
        $this->abrirTurno(omitirRefrigerio: true);
        $this->postJson('/api/v1/attendance/ping-ubicacion', $this->pingPayload())->assertOk();
        $this->assertDatabaseCount('asistencia_presencia', 1);

        $this->postJson('/api/v1/attendance/mark', [
            'dni' => '12345678',
            'tipo' => 'salida',
            'tienda_id' => 'T01',
            'lat' => -12.046374,
            'lng' => -77.042793,
            'accuracy' => 10,
            'device_id' => 'device-abc',
        ])
            ->assertOk()
            ->assertJsonPath('tipo', 'salida');

        // Presencia borrada; las incidencias (si hubiera) permanecerían.
        $this->assertDatabaseCount('asistencia_presencia', 0);
    }
}
