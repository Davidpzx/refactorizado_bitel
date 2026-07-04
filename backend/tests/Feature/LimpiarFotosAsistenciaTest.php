<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paridad legacy cron/limpiar_fotos_asistencia.php — Sección A: auto-aprobación
 * de fotos FOTO pendientes de revisión de días anteriores.
 */
class LimpiarFotosAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 10, 8, 0, 0, 'America/Lima'));
        Storage::fake('public');

        DB::table('tiendas')->insert(['codigo' => 'T01', 'nombre' => 'Tienda Uno', 'radio_permitido' => 60]);
        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_auto_aprueba_foto_pendiente_de_dia_anterior_y_borra_archivo(): void
    {
        Storage::disk('public')->put('fotos_asistencia/vieja.jpg', 'contenido');

        $id = DB::table('asistencias')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-08',
            'metodo_marcacion' => 'FOTO',
            'requiere_revision' => 1,
            'foto_marcacion' => 'fotos_asistencia/vieja.jpg',
        ]);

        Artisan::call('bitel:limpiar-fotos', ['--dias' => 7]);

        $this->assertDatabaseHas('asistencias', [
            'id' => $id,
            'requiere_revision' => 0,
            'foto_marcacion' => null,
        ]);
        Storage::disk('public')->assertMissing('fotos_asistencia/vieja.jpg');
    }

    public function test_no_auto_aprueba_foto_pendiente_de_hoy(): void
    {
        $id = DB::table('asistencias')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-10',
            'metodo_marcacion' => 'FOTO',
            'requiere_revision' => 1,
            'foto_marcacion' => 'fotos_asistencia/hoy.jpg',
        ]);

        Artisan::call('bitel:limpiar-fotos', ['--dias' => 7]);

        $this->assertDatabaseHas('asistencias', ['id' => $id, 'requiere_revision' => 1]);
    }

    public function test_no_auto_aprueba_marcacion_gps_pendiente_de_dia_anterior(): void
    {
        $id = DB::table('asistencias')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => 'T01',
            'fecha' => '2026-07-08',
            'metodo_marcacion' => 'GPS',
            'requiere_revision' => 1,
        ]);

        Artisan::call('bitel:limpiar-fotos', ['--dias' => 7]);

        $this->assertDatabaseHas('asistencias', ['id' => $id, 'requiere_revision' => 1]);
    }
}
