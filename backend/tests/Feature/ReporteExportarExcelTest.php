<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\HistorialReporte;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ReporteExportarExcelTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tiendaId, int $usuarioId, array $overrides = []): int
    {
        return DB::table('reportes')->insertGetId(array_replace([
            'agente_id'          => 1,
            'tienda_id'          => $tiendaId,
            'usuario_id'         => $usuarioId,
            'fecha'              => '2026-06-10',
            'estado'             => 'enviado',
            'total_calculado'    => 500,
            'yape'               => 50,
            'bipay'              => 0,
            'transferencia'      => 0,
            'retiro_bipay'       => 0,
            'recarga_bipay'      => 30,
            'caja_inicial'       => 100,
            'total_salidas'      => 20,
            'efectivo_esperado'  => 430,
            'efectivo_entregado' => 430,
            'diferencia'         => 0,
            'destino_efectivo'   => 'BANCO',
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
    }

    public function test_admin_puede_exportar_reporte_de_cualquier_tienda(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $reporteId = $this->crearReporte('T01', 999);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/reportes/{$reporteId}/exportar-excel")
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_vendedor_dueno_del_reporte_puede_exportarlo(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $reporteId = $this->crearReporte('T01', $vendedor->id);

        $this->actingAs($vendedor, 'sanctum')
            ->get("/api/v1/reportes/{$reporteId}/exportar-excel")
            ->assertOk();
    }

    public function test_vendedor_no_puede_exportar_reporte_ajeno(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $otroUsuarioId = Usuario::factory()->vendedor('T01')->create()->id;
        $reporteId = $this->crearReporte('T01', $otroUsuarioId);

        $this->actingAs($vendedor, 'sanctum')
            ->get("/api/v1/reportes/{$reporteId}/exportar-excel")
            ->assertStatus(403);
    }

    public function test_export_incluye_todas_las_secciones_con_ventas_reales(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $reporteId = $this->crearReporte('T01', $admin->id);

        $cliente = Cliente::create(['dni_ruc' => '12345678', 'nombre' => 'Juan Perez', 'tipo_documento' => 'DNI']);

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '00000001', 'nombres' => 'Vendedor Demo', 'estado' => 'ACTIVO',
        ]);

        // Postpago
        $ventaPostpago = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'cliente_id' => $cliente->id,
            'tipo_venta' => 'POSTPAGO', 'cross_selling' => false,
            'monto_total' => 80, 'comision_generada' => 10, 'comision_estado' => 'ACTIVA',
            'creado_en' => now(),
        ]);
        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaPostpago, 'plan_nombre_snap' => 'Plan 49', 'tipo_alta' => 'MNP',
            'cantidad' => 1, 'cobrado_unitario' => 80, 'comision_unitaria' => 10,
        ]);

        // Equipo
        $ventaEquipo = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'cliente_id' => $cliente->id,
            'tipo_venta' => 'EQUIPO', 'cross_selling' => false,
            'monto_total' => 350, 'comision_generada' => 0, 'comision_estado' => 'ACTIVA',
            'creado_en' => now(),
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaEquipo, 'producto_nombre_snap' => 'Redmi 12', 'imei_serial_snap' => '123456789012345',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 350, 'costo_snap' => 250,
            'ganancia_snap' => 100,
        ]);

        // Apoyo inter-tienda
        $ventaApoyo = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'cliente_id' => $cliente->id,
            'tipo_venta' => 'OTROS_FLUJO', 'subtipo' => 'APOYO', 'cross_selling' => true, 'tienda_destino' => 'T02',
            'monto_total' => 40, 'comision_generada' => 5, 'comision_estado' => 'ACTIVA',
            'creado_en' => now(),
        ]);
        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaApoyo, 'plan_nombre_snap' => 'Chip Prepago', 'tipo_alta' => 'LN',
            'cantidad' => 1, 'cobrado_unitario' => 40, 'comision_unitaria' => 5,
        ]);

        DB::table('reporte_salidas')->insert([
            'reporte_id' => $reporteId, 'tipo' => 'pasaje', 'monto' => 20, 'observacion' => 'Movilidad',
        ]);

        HistorialReporte::create([
            'reporte_id' => $reporteId, 'usuario_id' => $admin->id,
            'accion' => 'edicion_reporte', 'detalle' => 'Se corrigió el monto de yape.',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/reportes/{$reporteId}/exportar-excel")
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $spreadsheet = IOFactory::load($tmp);
        $texto = '';
        foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $texto .= $cell->getValue() . ' ';
            }
        }
        unlink($tmp);

        $this->assertStringContainsString('Plan 49', $texto);
        $this->assertStringContainsString('Redmi 12', $texto);
        $this->assertStringContainsString('Chip Prepago', $texto);
        $this->assertStringContainsString('Movilidad', $texto);
        $this->assertStringContainsString('Se corrigió el monto de yape', $texto);
        $this->assertStringContainsString('12345678', $texto);
    }

    public function test_reporte_inexistente_devuelve_404(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/reportes/999999/exportar-excel')
            ->assertStatus(404);
    }
}
