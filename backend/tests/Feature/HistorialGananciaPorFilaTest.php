<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistorialGananciaPorFilaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_ve_ganancia_por_fila_y_tienda_no(): void
    {
        $admin  = Usuario::factory()->admin()->create();
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1, 'tienda_id' => 'T01', 'fecha' => '2026-06-10', 'estado' => 'enviado',
            'total_calculado' => 500, 'efectivo_esperado' => 500, 'efectivo_entregado' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ventaEquipoId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'monto_total' => 300, 'comision_generada' => 0, 'comision_estado' => 'ACTIVA',
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaEquipoId, 'inventario_tienda_id' => 1,
            'producto_nombre_snap' => 'Equipo X', 'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO',
            'precio_venta' => 300, 'costo_snap' => 250, 'ganancia_snap' => 50, 'por_cobrar_financiera' => 0,
        ]);

        $ventaLineaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'tipo_venta' => 'POSTPAGO',
            'monto_total' => 0, 'comision_generada' => 20, 'comision_estado' => 'ACTIVA',
        ]);
        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaLineaId, 'plan_nombre_snap' => 'Plan Y', 'tipo_alta' => 'NUEVA',
            'cantidad' => 1, 'cobrado_unitario' => 0, 'comision_unitaria' => 20,
        ]);

        // Venta anulada: no debe sumar a la ganancia.
        $ventaAnuladaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'monto_total' => 999, 'comision_generada' => 0, 'comision_estado' => 'ANULADA',
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaAnuladaId, 'inventario_tienda_id' => 1,
            'producto_nombre_snap' => 'Equipo Anulado', 'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO',
            'precio_venta' => 999, 'costo_snap' => 900, 'ganancia_snap' => 999, 'por_cobrar_financiera' => 0,
        ]);

        $respAdmin = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/historial')->assertOk();
        $this->assertEquals(70, (float) $respAdmin->json('data.0.ganancia'));

        $respTienda = $this->actingAs($tienda, 'sanctum')->getJson('/api/v1/historial')->assertOk();
        $this->assertArrayNotHasKey('ganancia', $respTienda->json('data.0'));
    }
}
