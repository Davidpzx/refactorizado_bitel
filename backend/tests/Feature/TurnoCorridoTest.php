<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TurnoCorridoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 11, 0, 0, 'America/Lima'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_marcar_turno_corrido_salta_refrigerio(): void
    {
        DB::table('tiendas')->insert([
            'codigo' => 'T01',
            'nombre' => 'Tienda Uno',
            'activo' => true,
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
        ]);

        DB::table('asistencias')->insert([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => now()->toDateString(),
            'hora_ingreso' => now()->subHours(3)->toTimeString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/asistencias/turno-corrido', [
            'dni' => '12345678',
            'huella' => 'kyro-hw-test',
        ])
            ->assertOk()
            ->assertJsonFragment(['omitio_refrigerio' => true])
            ->assertJsonPath('siguiente_marcacion', 'salida');

        $this->assertDatabaseHas('asistencias', [
            'agente_id' => 1,
            'omitio_refrigerio' => true,
        ]);

        $this->getJson('/api/v1/attendance/status/12345678')
            ->assertOk()
            ->assertJsonPath('siguiente_marcacion', 'salida');
    }
}
