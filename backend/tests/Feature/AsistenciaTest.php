<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 8, 0, 0, 'America/Lima'));

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
            'hora_ref_inicio' => '12:00:00',
            'hora_ref_fin' => '13:00:00',
            'dia_descanso' => 'DOMINGO',
            'sueldo_base' => 1200,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_gps_registra_entrada_con_tienda_seleccionada_y_horario_del_agente(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 11, 8, 15, 30, 'America/Lima'));

        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada'))
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada')
            ->assertJsonPath('metodo', 'GPS')
            ->assertJsonPath('siguiente_marcacion', 'inicio_refrigerio')
            ->assertJsonPath('tardanza_minutos', 15);

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'tienda_ingreso' => 'T01',
            'hora_ingreso' => '08:15:30',
            'minutos_tardanza' => 15,
            'metodo_marcacion' => 'GPS',
        ]);
    }

    public function test_no_permite_saltar_ni_sobrescribir_marcaciones(): void
    {
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada'))->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 10, 0, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('salida'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVALID_SEQUENCE')
            ->assertJsonPath('siguiente_marcacion', 'inicio_refrigerio');

        $this->assertDatabaseMissing('asistencias', [
            'agente_id' => 1,
            'hora_salida' => '10:00:00',
        ]);
    }

    public function test_ciclo_completo_calcula_tardanza_de_refrigerio_y_audita_cada_metodo(): void
    {
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada'))->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 9, 0, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('inicio_refrigerio'))->assertOk();

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 10, 10, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('fin_refrigerio'))
            ->assertOk()
            ->assertJsonPath('tardanza_minutos', 10);

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 18, 10, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('salida'))
            ->assertOk()
            ->assertJsonPath('siguiente_marcacion', 'completado');

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => 1,
            'minutos_tardanza' => 10,
            'minutos_tardanza_refrigerio' => 10,
            'metodo_salida_refrigerio' => 'GPS',
            'metodo_entrada_refrigerio' => 'GPS',
            'metodo_salida' => 'GPS',
            'minutos_extra' => 0,
        ]);
    }

    public function test_rechaza_gps_debil_y_registra_el_intento(): void
    {
        $payload = $this->gpsPayload('entrada');
        $payload['accuracy'] = 80;

        $this->postJson('/api/v1/attendance/mark', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'WEAK_GPS')
            ->assertJsonPath('qr_disponible', true);

        $this->assertDatabaseHas('asistencia_intentos_fallidos', [
            'agente_id' => 1,
            'motivo' => 'ACCURACY_DEBIL',
        ]);
    }

    public function test_token_emergencia_valido_permite_marcar_fuera_del_rango_gps(): void
    {
        DB::table('agentes')->where('id', 1)->update([
            'token_emergencia' => '123456',
            'expiracion_token' => Carbon::now()->addMinutes(10),
        ]);

        $payload = $this->gpsPayload('entrada');
        $payload['lat'] = -12.121076;
        $payload['lng'] = -77.029757;
        $payload['accuracy'] = 100;
        $payload['token'] = '123456';

        $this->postJson('/api/v1/attendance/mark', $payload)
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada')
            ->assertJsonPath('metodo', 'GPS');

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => 1,
            'hora_ingreso' => '08:00:00',
        ]);
        $this->assertDatabaseMissing('asistencia_intentos_fallidos', [
            'agente_id' => 1,
        ]);
    }

    public function test_token_emergencia_invalido_no_omite_la_validacion_gps(): void
    {
        DB::table('agentes')->where('id', 1)->update([
            'token_emergencia' => '123456',
            'expiracion_token' => Carbon::now()->addMinutes(10),
        ]);

        $payload = $this->gpsPayload('entrada');
        $payload['lat'] = -12.121076;
        $payload['lng'] = -77.029757;
        $payload['token'] = '654321';

        $this->postJson('/api/v1/attendance/mark', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'INVALID_TOKEN');

        $this->assertDatabaseMissing('asistencias', [
            'agente_id' => 1,
        ]);
    }

    public function test_qr_debe_corresponder_a_la_tienda_seleccionada(): void
    {
        DB::table('tiendas')->insert([
            'codigo' => 'T02',
            'nombre' => 'Tienda Dos',
            'radio_permitido' => 60,
        ]);

        $bloque = (int) floor(now()->timestamp / 5);
        $hmac = substr(hash_hmac('sha256', "AST|T02|{$bloque}", config('attendance.qr_secret')), 0, 16);

        $this->postJson('/api/v1/attendance/mark-qr', [
            'dni' => '12345678',
            'tipo' => 'entrada',
            'tienda_id' => 'T01',
            'qr_token' => "AST|T02|{$bloque}|{$hmac}",
            'device_id' => 'device-123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'QR_STORE_MISMATCH');
    }

    public function test_foto_valida_se_guarda_para_revision_sin_romper_payload_actual(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        $this->postJson('/api/v1/attendance/mark-photo', [
            'dni' => '12345678',
            'tipo' => 'entrada',
            'tienda_id' => 'T01',
            'foto' => $png,
            'device_id' => 'device-123',
        ])
            ->assertOk()
            ->assertJsonPath('metodo', 'FOTO');

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => 1,
            'metodo_marcacion' => 'FOTO',
            'requiere_revision' => 1,
        ]);
        // normalizarFoto comprime a JPEG (<150KB) como el legacy (zero-retention); acepta cualquier imagen.
        $this->assertStringStartsWith(
            'data:image/',
            (string) DB::table('asistencias')->value('foto_marcacion')
        );
    }

    private function gpsPayload(string $tipo): array
    {
        return [
            'dni' => '12345678',
            'tipo' => $tipo,
            'tienda_id' => 'T01',
            'lat' => -12.046374,
            'lng' => -77.042793,
            'accuracy' => 10,
            'device_id' => 'device-123',
        ];
    }
}
