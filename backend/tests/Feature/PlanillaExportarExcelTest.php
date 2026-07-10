<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** ticket-042: /planilla/{mes}/exportar no tenía ningún test que verificara contenido real. */
class PlanillaExportarExcelTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_planilla_con_agente_real_y_totales(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Carla Ramirez',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO', 'sueldo_base' => 1500,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/planilla/2026-06/exportar')
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

        $this->assertStringContainsString('Carla Ramirez', $texto);
        $this->assertStringContainsString('12345678', $texto);
        $this->assertStringContainsString('1500', $texto);
        $this->assertStringContainsString('TOTALES', $texto);
    }

    public function test_mes_sin_agentes_devuelve_xlsx_valido_sin_filas(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/planilla/2026-06/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertStringContainsString('DNI', (string) $sheet->getCell('B2')->getValue());
        // Sin agentes: la fila 3 (primera de datos) queda vacía.
        $this->assertNull($sheet->getCell('B3')->getValue());
    }

    public function test_formato_de_mes_invalido_devuelve_422(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/planilla/junio-2026/exportar')
            ->assertStatus(422);
    }
}
