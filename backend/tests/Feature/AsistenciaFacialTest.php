<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AsistenciaFacialTest extends TestCase
{
    use RefreshDatabase;

    private const DNI = '12345678';

    private const FACIAL_A = 'dasam-face-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FACIAL_B = 'dasam-face-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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
            'dni' => self::DNI,
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

    private function gpsPayload(string $tipo, string $deviceHash): array
    {
        return [
            'dni' => self::DNI,
            'tipo' => $tipo,
            'tienda_id' => 'T01',
            'lat' => -12.046374,
            'lng' => -77.042793,
            'accuracy' => 10,
            'device_id' => $deviceHash,
        ];
    }

    public function test_primer_hash_facial_se_vincula_a_hash_facial_sin_tocar_hash_dispositivo(): void
    {
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_A))
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_facial' => self::FACIAL_A,
            'hash_dispositivo' => null,
        ]);
    }

    public function test_mismo_hash_facial_ya_vinculado_permite_marcar(): void
    {
        DB::table('agentes')->where('id', 1)->update(['hash_facial' => self::FACIAL_A]);

        Carbon::setTestNow(Carbon::create(2026, 6, 11, 9, 0, 0, 'America/Lima'));
        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_A))
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');
    }

    public function test_hash_facial_distinto_bloquea_y_registra_fraude(): void
    {
        DB::table('agentes')->where('id', 1)->update(['hash_facial' => self::FACIAL_A]);

        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_B))
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_MISMATCH');

        $this->assertDatabaseHas('log_fraude_dispositivo', [
            'agente_id' => 1,
            'dni_duenio_hash' => 'HASH_DISTINTO',
        ]);
        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_facial' => self::FACIAL_A,
        ]);
    }

    public function test_hash_facial_duplicado_en_otro_agente_bloquea(): void
    {
        DB::table('agentes')->insert([
            'id' => 2,
            'dni' => '87654321',
            'nombres' => 'Otro Agente',
            'estado' => 'ACTIVO',
            'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00',
            'hora_salida' => '18:00:00',
            'sueldo_base' => 1200,
            'hash_facial' => self::FACIAL_A,
        ]);

        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_A))
            ->assertStatus(403)
            ->assertJsonPath('code', 'DEVICE_DUPLICATE');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_facial' => null,
        ]);
    }

    public function test_sentinel_dasam_sf_bypassea_el_bloqueo_sin_resescribir_el_hash(): void
    {
        DB::table('agentes')->where('id', 1)->update(['hash_facial' => 'dasam-sf-desactivado']);

        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_B))
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_facial' => 'dasam-sf-desactivado',
        ]);
        $this->assertDatabaseMissing('log_fraude_dispositivo', [
            'agente_id' => 1,
        ]);
    }

    public function test_hash_dispositivo_distinto_no_bloquea_una_marca_con_hash_facial(): void
    {
        DB::table('agentes')->where('id', 1)->update([
            'hash_dispositivo' => 'kyro-hw-cccccccccccccccccccccccccccccccccccccccc-12345678',
        ]);

        $this->postJson('/api/v1/attendance/mark', $this->gpsPayload('entrada', self::FACIAL_A))
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_facial' => self::FACIAL_A,
            'hash_dispositivo' => 'kyro-hw-cccccccccccccccccccccccccccccccccccccccc-12345678',
        ]);
    }

    public function test_token_de_emergencia_con_device_hash_facial_revincula_hash_dispositivo_no_hash_facial(): void
    {
        DB::table('agentes')->where('id', 1)->update([
            'token_emergencia' => '123456',
            'expiracion_token' => Carbon::now()->addMinutes(10),
        ]);

        $payload = $this->gpsPayload('entrada', self::FACIAL_A);
        $payload['token'] = '123456';

        $this->postJson('/api/v1/attendance/mark', $payload)
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_dispositivo' => self::FACIAL_A,
            'hash_facial' => null,
        ]);
    }
}
