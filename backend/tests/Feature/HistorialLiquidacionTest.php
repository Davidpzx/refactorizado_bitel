<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistorialLiquidacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_obtiene_liquidacion_asistencias(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearAgente();

        DB::table('asistencias')->insert([
            'agente_id' => $agenteId,
            'tienda_id' => 'T01',
            'fecha' => '2026-06-15',
            'hora_ingreso' => '08:10:00',
            'hora_salida' => '17:30:00',
            'minutos_tardanza' => 10,
            'minutos_deuda' => 30,
            'comodin_usado' => false,
            'omitio_refrigerio' => true,
            'estado_asistencia' => 'TARDANZA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/agentes/{$agenteId}/liquidacion-asistencias?mes=2026-06")
            ->assertOk()
            ->assertJsonStructure([
                'agente',
                'mes',
                'dias',
                'resumen' => [
                    'total_tardanzas_min',
                    'deuda_acumulada_min',
                    'comodines_usados',
                    'total_descuento_soles',
                ],
            ])
            ->assertJsonPath('resumen.total_tardanzas_min', 10)
            ->assertJsonPath('resumen.deuda_acumulada_min', 30)
            ->assertJsonPath('resumen.total_descuento_soles', 3);
    }

    public function test_tienda_no_puede_ver_liquidacion(): void
    {
        $usuario = Usuario::factory()->create(['rol' => 'tienda', 'tienda_id' => 'T01']);
        $agenteId = $this->crearAgente();

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/v1/agentes/{$agenteId}/liquidacion-asistencias?mes=2026-06")
            ->assertStatus(403);
    }

    private function crearAgente(): int
    {
        DB::table('tiendas')->insertOrIgnore([
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
        ]);

        return 1;
    }
}
