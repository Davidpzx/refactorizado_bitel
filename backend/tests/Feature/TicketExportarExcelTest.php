<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** ticket-042: /tickets/exportar no tenía ningún test que verificara contenido real. */
class TicketExportarExcelTest extends TestCase
{
    use RefreshDatabase;

    private function crearTicket(string $tienda, string $descripcion, array $overrides = []): void
    {
        DB::table('tickets_emitidos')->insert(array_replace([
            'tienda_id' => $tienda, 'agente_id' => 1, 'vendedor' => 'Vendedor Uno',
            'nombre_cliente' => 'Cliente Uno', 'dni_cliente' => '11112222',
            'descripcion' => $descripcion, 'monto' => 50, 'cantidad' => 1,
            'efectivo' => 50, 'creado_en' => now(),
        ], $overrides));
    }

    public function test_exporta_tickets_reales_con_datos_del_cliente(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearTicket('PUNDA50', 'Recarga Bipay S/50.00');

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/tickets/exportar')
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

        $this->assertStringContainsString('Recarga Bipay S/50.00', $texto);
        $this->assertStringContainsString('Cliente Uno', $texto);
        $this->assertStringContainsString('11112222', $texto);
    }

    public function test_vendedor_de_tienda_solo_exporta_sus_propios_tickets(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $this->crearTicket('PUNDA50', 'TicketPropio');
        $this->crearTicket('TACDA13', 'TicketAjeno');

        $response = $this->actingAs($vendedor, 'sanctum')
            ->get('/api/v1/tickets/exportar')
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

        $this->assertStringContainsString('TicketPropio', $texto);
        $this->assertStringNotContainsString('TicketAjeno', $texto);
    }

    public function test_sin_datos_devuelve_xlsx_valido_solo_con_encabezados(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/tickets/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $response->streamedContent());
        $sheet = IOFactory::load($tmp)->getActiveSheet();
        unlink($tmp);

        $this->assertSame('Ticket #', (string) $sheet->getCell('A1')->getValue());
        $this->assertNull($sheet->getCell('A2')->getValue());
    }
}
