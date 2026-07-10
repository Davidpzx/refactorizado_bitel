<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** ticket-042: /asistencias/exportar-neiry no tenía ningún test que verificara contenido real. */
class AsistenciaNeiryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_exporta_plantilla_neiry_con_marcaciones_reales(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '12345678', 'nombres' => 'Pedro Luis Gomez',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO',
        ]);

        DB::table('asistencias')->insert([
            'agente_id' => 1, 'tienda_id' => 'PUNDA50', 'fecha' => '2026-06-05',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'inicio_refrigerio' => '13:00:00', 'fin_refrigerio' => '14:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/asistencias/exportar-neiry?mes=2026-06')
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

        $this->assertStringContainsString('PUNDA50', $texto);
        // "3er_nombre 1er_nombre": 'Pedro Luis Gomez' -> 'Gomez Pedro'
        $this->assertStringContainsString('Gomez Pedro', $texto);
        $this->assertStringContainsString('E:08:00', $texto);
        $this->assertStringContainsString('9h 00m', $texto);
    }

    public function test_agente_activo_sin_marcaciones_aparece_con_ausencias(): void
    {
        $admin = Usuario::factory()->admin()->create();

        DB::table('agentes')->insert([
            'id' => 1, 'dni' => '87654321', 'nombres' => 'Ana Torres',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/asistencias/exportar-neiry?mes=2026-06')
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

        $this->assertStringContainsString('Ana Torres', $texto);
        $this->assertStringContainsString('✕', $texto);
    }
}
