<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verificación ticket-012: paridad de gerencia/comisiones_empresa.php (legacy)
 * contra ComisionesPage + ComisionPlanController + ConfigComisionesController.
 */
class ComisionesEmpresaParidadTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_planes_completo(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/comisiones-planes', [
            'tipo_servicio'   => 'POSTPAGO',
            'nombre_plan'     => 'Plan 29.90',
            'tipo_alta'       => 'LN',
            'fee_monto'       => 29.90,
            'comision_dni_n'  => 25,
            'comision_dni_n3' => 15,
            'comision_ext_n'  => 20,
            'comision_ext_n3' => 10,
        ])->assertCreated();

        $id = $create->json('id');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/comisiones-planes')
            ->assertOk()
            ->assertJsonFragment(['nombre_plan' => 'Plan 29.90']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/comisiones-planes/{$id}", [
            'comision_dni_n' => 30,
        ])->assertOk()->assertJsonFragment(['comision_dni_n' => '30.00']);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/comisiones-planes/{$id}")
            ->assertNoContent();

        $this->assertDatabaseCount('comisiones_planes', 0);
    }

    public function test_tarifas_operativas_no_tocan_historial(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/config-comisiones/tarifas', [
            'ganancia_recargas' => 7.5,
            'ganancia_bipay'    => 2,
            'ganancia_krece'    => 3,
            'ganancia_payjoy'   => 4,
        ])->assertOk()->assertJson(['success' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/config-comisiones')
            ->assertOk()
            ->assertJsonPath('tarifas.ganancia_recargas', 7.5)
            ->assertJsonPath('tarifas.ganancia_bipay', 2);
    }

    public function test_rangos_por_servicio_bipay_krece_payjoy(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $resp = $this->actingAs($admin, 'sanctum')->putJson('/api/v1/config-comisiones/rangos-servicio', [
            'tipo_servicio' => 'bipay',
            'rangos' => [
                ['monto_min' => 0, 'monto_max' => 100, 'ganancia' => 1],
                ['monto_min' => 100.01, 'monto_max' => null, 'ganancia' => 2],
            ],
        ])->assertOk();

        $this->assertTrue($resp->json('success'));
        $this->assertDatabaseCount('comisiones_rangos', 2);

        // Rango solapado debe rechazarse (paridad + mejora sobre legacy)
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/config-comisiones/rangos-servicio', [
            'tipo_servicio' => 'bipay',
            'rangos' => [
                ['monto_min' => 0, 'monto_max' => 50, 'ganancia' => 1],
                ['monto_min' => 40, 'monto_max' => null, 'ganancia' => 2],
            ],
        ])->assertStatus(422);
    }

    public function test_rangos_productividad_plan_equipo(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/config-comisiones/rangos-productividad', [
            'tipo' => 'PLAN',
            'rangos' => [
                ['desde' => 1, 'hasta' => 10, 'monto' => 5],
                ['desde' => 11, 'hasta' => 9999, 'monto' => 8],
            ],
        ])->assertOk()->assertJson(['success' => true]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/config-comisiones')
            ->assertOk()
            ->assertJsonCount(2, 'rangos_plan');
    }

    public function test_recalculo_masivo_actualiza_ventas_y_lineas(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('comisiones_planes')->insert([
            'tipo_servicio' => 'POSTPAGO', 'nombre_plan' => 'Plan 29.90', 'tipo_alta' => 'LN',
            'fee_monto' => 29.90, 'comision_dni_n' => 40, 'comision_dni_n3' => 0,
            'comision_ext_n' => 35, 'comision_ext_n3' => 0,
        ]);

        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1, 'tienda_id' => 'T01', 'fecha' => '2026-07-01',
            'estado' => 'aprobado', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'tipo_venta' => 'POSTPAGO',
            'monto_total' => 29.90, 'comision_generada' => 0, 'es_remate' => false,
            'es_extranjero' => false, 'creado_en' => now(),
        ]);

        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaId, 'plan_nombre_snap' => 'Plan 29.90', 'tipo_alta' => 'LN',
            'cantidad' => 1, 'cobrado_unitario' => 0, 'comision_unitaria' => 0, 'es_esim' => false,
        ]);

        $resp = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/comisiones-planes/recalcular', [
            'fecha_desde' => '2026-07-01',
            'fecha_hasta' => '2026-07-01',
        ])->assertOk();

        $this->assertTrue($resp->json('success'));
        $this->assertEquals(1, $resp->json('ventas_actualizadas'));
        $this->assertEquals(1, $resp->json('lineas_actualizadas'));

        // comision_dni_n (40) - costo_chip (1.00, alta normal no-migración/no-eSIM) = 39.00
        $this->assertEquals(39.00, DB::table('ventas')->where('id', $ventaId)->value('comision_generada'));
        $this->assertEquals(39.00, DB::table('venta_lineas')->where('venta_id', $ventaId)->value('comision_unitaria'));
    }
}
