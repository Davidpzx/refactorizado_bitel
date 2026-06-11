<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DispositivoTest extends TestCase
{
    use RefreshDatabase;

    private const DNI = '12345678';

    private const DISPOSITIVO_ANTERIOR = 'kyro-hw-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-12345678';

    private const DISPOSITIVO_NUEVO = 'kyro-hw-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-12345678';

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
            'pin_seguridad' => Hash::make('0427'),
            'hash_dispositivo' => self::DISPOSITIVO_ANTERIOR,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dispositivo_distinto_solicita_pin(): void
    {
        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'require_pin');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_dispositivo' => self::DISPOSITIVO_ANTERIOR,
        ]);
    }

    public function test_pin_hasheado_autoriza_y_reemplaza_el_dispositivo(): void
    {
        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'pin' => '0427',
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_dispositivo' => self::DISPOSITIVO_NUEVO,
        ]);
    }

    public function test_pin_incorrecto_no_reemplaza_el_dispositivo(): void
    {
        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'pin' => '9999',
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_dispositivo' => self::DISPOSITIVO_ANTERIOR,
        ]);
    }

    public function test_mantiene_compatibilidad_con_pin_legacy_en_texto_plano(): void
    {
        DB::table('agentes')->where('id', 1)->update(['pin_seguridad' => '0427']);

        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'pin' => '0427',
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_rechaza_huella_generada_para_otro_dni_aunque_el_pin_sea_correcto(): void
    {
        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'pin' => '0427',
            'device_hash' => 'kyro-hw-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb-87654321',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'error');

        $this->assertDatabaseHas('agentes', [
            'id' => 1,
            'hash_dispositivo' => self::DISPOSITIVO_ANTERIOR,
        ]);
    }

    public function test_el_nuevo_dispositivo_puede_marcar_asistencia_despues_de_autorizarse(): void
    {
        $this->postJson('/api/v1/autorizar-dispositivo', [
            'dni' => self::DNI,
            'pin' => '0427',
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->postJson('/api/v1/attendance/mark', [
            'dni' => self::DNI,
            'tipo' => 'entrada',
            'tienda_id' => 'T01',
            'lat' => -12.046374,
            'lng' => -77.042793,
            'accuracy' => 10,
            'device_hash' => self::DISPOSITIVO_NUEVO,
        ])
            ->assertOk()
            ->assertJsonPath('tipo', 'entrada');
    }
}
