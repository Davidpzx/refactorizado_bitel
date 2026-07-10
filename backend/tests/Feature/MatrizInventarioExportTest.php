<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * ticket-042: /inventario/exportar devolvía CSV pero el frontend siempre lo
 * descargaba con extensión .xlsx (Excel lo rechaza por "formato inválido"), e
 * ignoraba el filtro estado/tienda/q ya aplicado en pantalla — filtrar a
 * "Vendido" y exportar devolvía un archivo vacío. /agentes/exportar sumaba
 * comisiones con reportes.estado='cerrado', un estado que nunca existe en el
 * sistema (solo 'borrador'|'enviado'), así que la columna de comisiones
 * siempre daba 0.
 */
class MatrizInventarioExportTest extends TestCase
{
    use RefreshDatabase;

    private function crearItem(string $tienda, string $estado, array $overrides = []): int
    {
        return DB::table('inventario_tiendas')->insertGetId(array_replace([
            'tienda_id'       => $tienda,
            'tipo'            => 'EQUIPO',
            'producto_nombre' => 'Redmi 12',
            'imei_serial'     => '123456789012345',
            'cantidad'        => 1,
            'precio_costo'    => 500,
            'precio_minimo'   => 550,
            'precio_normal'   => 600,
            'estado'          => $estado,
            'fecha_registro'  => now(),
        ], $overrides));
    }

    private function leerTextoXlsx(string $contenido): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
        file_put_contents($tmp, $contenido);
        $spreadsheet = IOFactory::load($tmp);
        $texto = '';
        foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $texto .= $cell->getValue() . ' ';
            }
        }
        unlink($tmp);
        return $texto;
    }

    // ── GET /inventario/exportar ─────────────────────────────────────────────

    public function test_exporta_inventario_como_xlsx_real_no_csv(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearItem('PUNDA50', 'DISPONIBLE', ['producto_nombre' => 'Redmi Note 13']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/inventario/exportar?tipo=EQUIPO')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $texto = $this->leerTextoXlsx($response->streamedContent());
        $this->assertStringContainsString('Redmi Note 13', $texto);
    }

    public function test_filtro_estado_vendido_se_respeta_y_no_devuelve_vacio(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearItem('PUNDA50', 'DISPONIBLE', ['producto_nombre' => 'ProductoDisponible']);
        $this->crearItem('PUNDA50', 'VENDIDO', ['producto_nombre' => 'ProductoVendido']);

        // Antes del fix: el estado quedaba fijo a DISPONIBLE/TRASLADO y este filtro
        // devolvía un archivo con solo encabezados aunque sí había vendidos.
        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/inventario/exportar?tipo=EQUIPO&estado=VENDIDO')
            ->assertOk();

        $texto = $this->leerTextoXlsx($response->streamedContent());
        $this->assertStringContainsString('ProductoVendido', $texto);
        $this->assertStringNotContainsString('ProductoDisponible', $texto);
    }

    public function test_sin_filtro_de_estado_excluye_vendidos_por_defecto(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearItem('PUNDA50', 'DISPONIBLE', ['producto_nombre' => 'ProductoDisponible']);
        $this->crearItem('PUNDA50', 'VENDIDO', ['producto_nombre' => 'ProductoVendido']);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/inventario/exportar?tipo=EQUIPO')
            ->assertOk();

        $texto = $this->leerTextoXlsx($response->streamedContent());
        $this->assertStringContainsString('ProductoDisponible', $texto);
        $this->assertStringNotContainsString('ProductoVendido', $texto);
    }

    public function test_vendedor_de_tienda_solo_exporta_su_propia_tienda(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();
        $this->crearItem('PUNDA50', 'DISPONIBLE', ['producto_nombre' => 'ProductoPropio']);
        $this->crearItem('TACDA13', 'DISPONIBLE', ['producto_nombre' => 'ProductoAjeno']);

        $response = $this->actingAs($vendedor, 'sanctum')
            ->get('/api/v1/inventario/exportar?tipo=EQUIPO')
            ->assertOk();

        $texto = $this->leerTextoXlsx($response->streamedContent());
        $this->assertStringContainsString('ProductoPropio', $texto);
        $this->assertStringNotContainsString('ProductoAjeno', $texto);
    }

    public function test_sin_datos_devuelve_xlsx_valido_solo_con_encabezados(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/inventario/exportar?tipo=ACCESORIO')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $texto = $this->leerTextoXlsx($response->streamedContent());
        $this->assertStringContainsString('Producto', $texto);
        $this->assertStringContainsString('IMEI/Serial', $texto);
    }

    // ── GET /agentes/exportar ────────────────────────────────────────────────

    public function test_exporta_agentes_con_comisiones_del_mes_estado_enviado(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $agenteId = DB::table('agentes')->insertGetId([
            'dni' => '12345678', 'nombres' => 'Juan Perez',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO',
        ]);

        DB::table('reportes')->insert([
            'agente_id' => $agenteId, 'tienda_id' => 'PUNDA50', 'usuario_id' => $admin->id,
            'fecha' => '2026-06-10', 'estado' => 'enviado', 'total_calculado' => 250,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Borrador del mismo mes: no debe sumar.
        DB::table('reportes')->insert([
            'agente_id' => $agenteId, 'tienda_id' => 'PUNDA50', 'usuario_id' => $admin->id,
            'fecha' => '2026-06-12', 'estado' => 'borrador', 'total_calculado' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Enviado pero de otro mes: no debe sumar.
        DB::table('reportes')->insert([
            'agente_id' => $agenteId, 'tienda_id' => 'PUNDA50', 'usuario_id' => $admin->id,
            'fecha' => '2026-05-10', 'estado' => 'enviado', 'total_calculado' => 999,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/agentes/exportar?mes=2026-06')
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Juan Perez', $csv);
        $this->assertStringContainsString('250.00', $csv);
        $this->assertStringNotContainsString('999.00', $csv);
    }

    public function test_agente_sin_reportes_enviados_sale_con_comision_cero(): void
    {
        $admin = Usuario::factory()->admin()->create();
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Ana Torres',
            'tienda_base' => 'PUNDA50', 'estado' => 'ACTIVO',
        ]);

        $csv = $this->actingAs($admin, 'sanctum')
            ->get('/api/v1/agentes/exportar?mes=2026-06')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Ana Torres', $csv);
        $this->assertStringContainsString('0.00', $csv);
    }
}
