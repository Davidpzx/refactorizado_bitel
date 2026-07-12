<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET /api/v1/asistencias/fraude-dispositivos — paridad legacy
 * gerencia/panel_asistencias.php (últimas 50 alertas, DESC, solo admin).
 */
class FraudeDispositivosTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/asistencias/fraude-dispositivos';

    private function alerta(array $overrides = []): void
    {
        DB::table('log_fraude_dispositivo')->insert(array_merge([
            'fecha_hora' => '2026-07-01 08:00:00',
            'agente_id' => 1,
            'nombre_agente' => 'Agente Prueba',
            'dni_ingresado' => '12345678',
            'dni_duenio_hash' => '87654321',
            'tienda_intento' => 'T01',
        ], $overrides));
    }

    public function test_admin_ve_las_alertas_con_las_columnas_del_legacy(): void
    {
        $this->alerta();

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.nombre_agente', 'Agente Prueba')
            ->assertJsonPath('data.0.dni_ingresado', '12345678')
            ->assertJsonPath('data.0.dni_duenio_hash', '87654321')
            ->assertJsonPath('data.0.tienda_intento', 'T01')
            ->assertJsonStructure(['total', 'data' => [['id', 'fecha_hora', 'nombre_agente', 'dni_ingresado', 'dni_duenio_hash', 'tienda_intento']]]);
    }

    public function test_ordena_por_fecha_hora_descendente(): void
    {
        $this->alerta(['fecha_hora' => '2026-07-01 08:00:00', 'nombre_agente' => 'Vieja']);
        $this->alerta(['fecha_hora' => '2026-07-03 20:00:00', 'nombre_agente' => 'Reciente']);
        $this->alerta(['fecha_hora' => '2026-07-02 12:00:00', 'nombre_agente' => 'Media']);

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.nombre_agente', 'Reciente')
            ->assertJsonPath('data.1.nombre_agente', 'Media')
            ->assertJsonPath('data.2.nombre_agente', 'Vieja');
    }

    public function test_limita_a_50_alertas_como_el_legacy(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->alerta(['fecha_hora' => '2026-07-01 '.sprintf('%02d:00:00', $i % 24)]);
        }

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('total', 50)
            ->assertJsonCount(50, 'data');
    }

    public function test_dueno_no_identificado_se_devuelve_como_null(): void
    {
        $this->alerta(['dni_duenio_hash' => null]);

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.dni_duenio_hash', null);
    }

    public function test_sin_alertas_responde_estado_vacio(): void
    {
        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_incidencias_de_ubicacion_aparecen_en_el_mismo_monitor(): void
    {
        DB::table('agentes')->insert([
            'id' => 7, 'dni' => '70123456', 'nombres' => 'Agente GPS', 'estado' => 'ACTIVO',
        ]);
        DB::table('asistencia_incidencias_ubicacion')->insert([
            'agente_id' => 7, 'device_hash' => 'device-gps', 'tipo' => 'mock_gps',
            'capturado_en' => '2026-07-04 11:00:00', 'created_at' => '2026-07-04 11:00:00',
        ]);

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.fuente', 'ubicacion')
            ->assertJsonPath('data.0.tipo_ubicacion', 'mock_gps')
            ->assertJsonPath('data.0.dni_ingresado', '70123456')
            ->assertJsonPath('data.0.nombre_agente', 'Agente GPS')
            ->assertJsonPath('data.0.dni_duenio_hash', null);
    }

    public function test_agente_recibe_403(): void
    {
        // Plan 16 (R4): el monitor de fraude es SOLO LECTURA para
        // admin/gerente/jefe_tienda; el agente de ventas no accede.
        $this->alerta();

        $this->actingAs(Usuario::factory()->agenteVentas()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertStatus(403);
    }

    public function test_jefe_tienda_puede_ver_fraude(): void
    {
        // Plan 16 (R4): reetiquetado de lectura — el jefe_tienda VE el monitor.
        $this->alerta();

        $this->actingAs(Usuario::factory()->jefeTienda()->create(), 'sanctum')
            ->getJson(self::URL)
            ->assertOk();
    }

    public function test_invitado_recibe_401(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }
}
