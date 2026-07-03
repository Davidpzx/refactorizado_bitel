<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgenteBajaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(array $overrides = []): int
    {
        DB::table('agentes')->insert(array_merge([
            'id'           => 1,
            'dni'          => '12345678',
            'nombres'      => 'Agente Prueba',
            'estado'       => 'ACTIVO',
            'tienda_base'  => 'T01',
            'sueldo_base'  => 1200,
            'hora_ingreso' => '08:00:00',
            'hora_salida'  => '17:00:00',
            'dia_descanso' => 'DOMINGO',
            'telefono'     => '987654321',
            'correo'       => 'agente@empresa.pe',
        ], $overrides));

        return 1;
    }

    public function test_baja_con_clasificacion_y_motivo_persiste_y_escribe_cese(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", [
                'estado'             => 'INACTIVO',
                'clasificacion_baja' => 'LISTA_NEGRA',
                'motivo_baja'        => 'Abandono de puesto',
                'fecha_baja'         => '2026-07-03',
                'observacion'        => 'No responde llamadas',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'INACTIVO')
            ->assertJsonPath('clasificacion_baja', 'LISTA_NEGRA')
            ->assertJsonPath('motivo_baja', 'Abandono de puesto');

        $this->assertDatabaseHas('agentes', [
            'id'                 => $id,
            'estado'             => 'INACTIVO',
            'clasificacion_baja' => 'LISTA_NEGRA',
            'motivo_baja'        => 'Abandono de puesto',
        ]);

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'CESE')->first();
        $this->assertNotNull($fila);
        $this->assertSame('LISTA_NEGRA', $fila->clasificacion_baja);
        $this->assertSame('Abandono de puesto | No responde llamadas', $fila->observacion);
        $this->assertSame($admin->id, (int) $fila->registrado_por);
    }

    public function test_baja_sin_clasificacion_ni_motivo_tambien_funciona(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", ['estado' => 'INACTIVO'])
            ->assertOk()
            ->assertJsonPath('estado', 'INACTIVO');

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'CESE')->first();
        $this->assertNotNull($fila);
        $this->assertNull($fila->clasificacion_baja);
    }

    public function test_reingreso_escribe_reingreso(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente(['estado' => 'INACTIVO', 'clasificacion_baja' => 'LISTA_BLANCA']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", ['estado' => 'ACTIVO'])
            ->assertOk();

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'REINGRESO')->first();
        $this->assertNotNull($fila);
        $this->assertSame('ACTIVO', $fila->estado);
        $this->assertNull($fila->clasificacion_baja);
    }

    public function test_cambio_de_tienda_escribe_tienda_con_anterior_y_nuevo(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente(['tienda_base' => 'T01']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", ['tienda_base' => 'T99'])
            ->assertOk();

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'TIENDA')->first();
        $this->assertNotNull($fila);
        $this->assertSame('T01', $fila->campo_anterior);
        $this->assertSame('T99', $fila->campo_nuevo);
    }

    public function test_cambio_de_horario_escribe_horario(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente(['hora_ingreso' => '08:00:00']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", ['hora_ingreso' => '09:30:00'])
            ->assertOk();

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'HORARIO')->first();
        $this->assertNotNull($fila);
        $this->assertStringContainsString('E:08:00:00', $fila->campo_anterior);
        $this->assertStringContainsString('E:09:30:00', $fila->campo_nuevo);
    }

    public function test_edicion_ficha_escribe_ficha_solo_si_hubo_diferencia(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente(['telefono' => '111111111']);

        // Sin cambio → no escribe FICHA
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}/perfil-rrhh", ['telefono' => '111111111'])
            ->assertOk();
        $this->assertSame(0, DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'FICHA')->count());

        // Con cambio → escribe FICHA
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}/perfil-rrhh", ['telefono' => '222222222'])
            ->assertOk();

        $fila = DB::table('historial_agentes')->where('id_agente', $id)->where('tipo_cambio', 'FICHA')->first();
        $this->assertNotNull($fila);
        $this->assertStringContainsString('111111111', $fila->campo_anterior);
        $this->assertStringContainsString('222222222', $fila->campo_nuevo);
    }

    public function test_get_historial_devuelve_ordenado_desc_y_registrado_por(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/agentes/{$id}", ['estado' => 'INACTIVO'])->assertOk();
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/agentes/{$id}", ['estado' => 'ACTIVO'])->assertOk();

        $resp = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/agentes/{$id}/historial")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $resp);
        // Más reciente primero: el reingreso (segundo evento) encabeza la lista
        $this->assertSame('REINGRESO', $resp[0]['tipo_cambio']);
        $this->assertSame('CESE', $resp[1]['tipo_cambio']);
        $this->assertSame($admin->id, (int) $resp[0]['registrado_por']);
    }

    public function test_editar_agente_activo_con_campos_de_baja_vacios_no_rompe(): void
    {
        // El frontend envía cadenas vacías para clasificacion_baja/fecha_baja al editar un agente
        // activo; deben tratarse como null (ConvertEmptyStringsToNull) y no fallar la validación.
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearAgente();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/agentes/{$id}", [
                'sueldo_base'        => 1500,
                'clasificacion_baja' => '',
                'motivo_baja'        => '',
                'fecha_baja'         => '',
                'observacion'        => '',
            ])
            ->assertOk()
            ->assertJsonPath('sueldo_base', '1500.00');
    }

    public function test_historial_es_solo_admin(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $id = $this->crearAgente();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson("/api/v1/agentes/{$id}/historial")
            ->assertStatus(403);
    }
}
