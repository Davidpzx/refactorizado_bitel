<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gap P0 (paridad legacy sis_bipay/gerencia/eliminar_reporte.php): el legacy permitía
 * eliminar CUALQUIER reporte, sin importar su estado. El refactor había agregado un
 * bloqueo nuevo (422) cuando estado==='aprobado'. Negocio decidió que ese bloqueo es un
 * bug y debe quitarse: un reporte aprobado SÍ se puede eliminar (con su reversión de stock).
 */
class ReporteDestroyAprobadoTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporteAprobado(string $tiendaId, int $usuarioId): int
    {
        return DB::table('reportes')->insertGetId([
            'agente_id'          => 1,
            'tienda_id'          => $tiendaId,
            'usuario_id'         => $usuarioId,
            'fecha'              => '2026-06-10',
            'estado'             => 'aprobado',
            'total_calculado'    => 350,
            'yape'               => 0,
            'bipay'              => 0,
            'transferencia'      => 0,
            'retiro_bipay'       => 0,
            'recarga_bipay'      => 0,
            'caja_inicial'       => 100,
            'total_salidas'      => 0,
            'efectivo_esperado'  => 350,
            'efectivo_entregado' => 350,
            'diferencia'         => 0,
            'destino_efectivo'   => 'BANCO',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function test_admin_puede_eliminar_un_reporte_aprobado(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $reporteId = $this->crearReporteAprobado('T01', 999);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/reportes/{$reporteId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('reportes', ['id' => $reporteId]);
    }

    public function test_eliminar_reporte_aprobado_revierte_stock_y_ventas(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $reporteId = $this->crearReporteAprobado('T01', 999);

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '00000001', 'nombres' => 'Vendedor Demo', 'estado' => 'ACTIVO',
        ]);

        // Equipo vendido: el ítem de inventario quedó VENDIDO y debe volver a DISPONIBLE.
        $invId = DB::table('inventario_tiendas')->insertGetId([
            'tienda_id' => 'T01', 'producto_nombre' => 'Redmi 12', 'tipo' => 'EQUIPO',
            'imei_serial' => '123456789012345', 'cantidad' => 0, 'estado' => 'VENDIDO',
            'reporte_venta_id' => $reporteId,
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1,
            'tipo_venta' => 'EQUIPO', 'cross_selling' => false,
            'monto_total' => 350, 'comision_generada' => 0, 'comision_estado' => 'ACTIVA',
            'creado_en' => now(),
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaId, 'producto_nombre_snap' => 'Redmi 12', 'imei_serial_snap' => '123456789012345',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 350, 'costo_snap' => 250,
            'ganancia_snap' => 100, 'inventario_tienda_id' => $invId,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/reportes/{$reporteId}")
            ->assertNoContent();

        // La venta se borró y el stock se revirtió (paridad con la reversión legacy).
        $this->assertDatabaseMissing('ventas', ['id' => $ventaId]);
        $this->assertDatabaseMissing('venta_equipos', ['venta_id' => $ventaId]);
        $this->assertDatabaseHas('inventario_tiendas', [
            'id' => $invId, 'estado' => 'DISPONIBLE', 'cantidad' => 1, 'reporte_venta_id' => null,
        ]);
    }
}
