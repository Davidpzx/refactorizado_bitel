<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class DashboardExportarExcelTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tiendaId, string $fecha, array $overrides = []): int
    {
        return DB::table('reportes')->insertGetId(array_replace([
            'agente_id' => 1,
            'tienda_id' => $tiendaId,
            'usuario_id' => 1,
            'fecha' => $fecha,
            'estado' => 'enviado',
            'total_calculado' => 100,
            'destino_efectivo' => 'TIENDA',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_admin_puede_exportar_dashboard(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearReporte('T01', '2026-06-10');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/dashboard/exportar?fecha_desde=2026-06-01&fecha_hasta=2026-06-30')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_vendedor_no_puede_exportar_dashboard(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01', '2026-06-10');

        $this->actingAs($vendedor, 'sanctum')
            ->get('/api/v1/dashboard/exportar')
            ->assertStatus(403);
    }

    public function test_filtro_de_fecha_excluye_reportes_fuera_de_rango(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '00000001', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO',
        ]);

        $dentroId = $this->crearReporte('T01', '2026-06-15');
        DB::table('ventas')->insert([
            'reporte_id' => $dentroId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'cross_selling' => false, 'monto_total' => 300, 'comision_generada' => 0,
            'comision_estado' => 'ACTIVA', 'creado_en' => now(),
        ]);
        $ventaEquipoId = DB::table('ventas')->where('reporte_id', $dentroId)->value('id');
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaEquipoId, 'producto_nombre_snap' => 'ProductoDentroDeRango',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 300,
        ]);

        $fueraId = $this->crearReporte('T01', '2026-07-15');
        DB::table('ventas')->insert([
            'reporte_id' => $fueraId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'cross_selling' => false, 'monto_total' => 300, 'comision_generada' => 0,
            'comision_estado' => 'ACTIVA', 'creado_en' => now(),
        ]);
        $ventaFueraId = DB::table('ventas')->where('reporte_id', $fueraId)->value('id');
        DB::table('venta_equipos')->insert([
            'venta_id' => $ventaFueraId, 'producto_nombre_snap' => 'ProductoFueraDeRango',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 300,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/dashboard/exportar?fecha_desde=2026-06-01&fecha_hasta=2026-06-30')
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

        $this->assertStringContainsString('ProductoDentroDeRango', $texto);
        $this->assertStringNotContainsString('ProductoFueraDeRango', $texto);
    }

    public function test_sin_filtro_de_fecha_acota_a_los_ultimos_90_dias(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '00000001', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO',
        ]);

        $recienteId = $this->crearReporte('T01', now()->subDays(10)->toDateString());
        DB::table('ventas')->insert([
            'reporte_id' => $recienteId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'cross_selling' => false, 'monto_total' => 300, 'comision_generada' => 0,
            'comision_estado' => 'ACTIVA', 'creado_en' => now(),
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => DB::table('ventas')->where('reporte_id', $recienteId)->value('id'),
            'producto_nombre_snap' => 'ProductoReciente',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 300,
        ]);

        $antiguoId = $this->crearReporte('T01', now()->subDays(200)->toDateString());
        DB::table('ventas')->insert([
            'reporte_id' => $antiguoId, 'vendedor_id' => 1, 'tipo_venta' => 'EQUIPO',
            'cross_selling' => false, 'monto_total' => 300, 'comision_generada' => 0,
            'comision_estado' => 'ACTIVA', 'creado_en' => now(),
        ]);
        DB::table('venta_equipos')->insert([
            'venta_id' => DB::table('ventas')->where('reporte_id', $antiguoId)->value('id'),
            'producto_nombre_snap' => 'ProductoAntiguoFueraDeVentana',
            'tipo_item' => 'EQUIPO', 'tipo_pago' => 'CONTADO', 'precio_venta' => 300,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/dashboard/exportar')
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

        $this->assertStringContainsString('ProductoReciente', $texto);
        $this->assertStringNotContainsString('ProductoAntiguoFueraDeVentana', $texto);
    }
}
