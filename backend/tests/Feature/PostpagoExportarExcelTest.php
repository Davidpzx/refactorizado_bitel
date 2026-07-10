<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** ticket-042: /postpago/exportar no tenía ningún test que verificara contenido real. */
class PostpagoExportarExcelTest extends TestCase
{
    use RefreshDatabase;

    private function crearVentaPostpago(string $tienda, string $fecha, string $plan): int
    {
        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1, 'tienda_id' => $tienda, 'usuario_id' => 1,
            'fecha' => $fecha, 'estado' => 'enviado',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id' => $reporteId, 'vendedor_id' => 1, 'tipo_venta' => 'POSTPAGO',
            'monto_total' => 80, 'comision_generada' => 10, 'comision_estado' => 'ACTIVA',
            'creado_en' => now(),
        ]);

        DB::table('venta_lineas')->insert([
            'venta_id' => $ventaId, 'plan_nombre_snap' => $plan, 'tipo_alta' => 'MNP',
            'cantidad' => 1, 'cobrado_unitario' => 80,
        ]);

        return $ventaId;
    }

    public function test_exporta_ventas_postpago_reales(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert(['id' => 1, 'dni' => '00000001', 'nombres' => 'Vendedor Uno', 'estado' => 'ACTIVO']);
        $this->crearVentaPostpago('PUNDA50', '2026-06-10', 'Plan Max 49');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/postpago/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $texto = '';
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $texto .= $cell->getValue() . ' ';
            }
        }

        $this->assertStringContainsString('Plan Max 49', $texto);
        $this->assertStringContainsString('Vendedor Uno', $texto);
    }

    public function test_filtro_de_fecha_excluye_ventas_fuera_de_rango(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert(['id' => 1, 'dni' => '00000001', 'nombres' => 'Vendedor Uno', 'estado' => 'ACTIVO']);
        $this->crearVentaPostpago('PUNDA50', '2026-06-10', 'PlanDentroDeRango');
        $this->crearVentaPostpago('PUNDA50', '2026-07-10', 'PlanFueraDeRango');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/postpago/exportar?desde=2026-06-01&hasta=2026-06-30')
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $texto = '';
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $texto .= $cell->getValue() . ' ';
            }
        }

        $this->assertStringContainsString('PlanDentroDeRango', $texto);
        $this->assertStringNotContainsString('PlanFueraDeRango', $texto);
    }

    public function test_sin_datos_en_el_rango_devuelve_xlsx_valido_solo_con_encabezados(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/postpago/exportar?desde=2020-01-01&hasta=2020-01-31')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertSame('#Venta', (string) $sheet->getCell('A1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/postpago/exportar')->assertStatus(401);
    }
}
