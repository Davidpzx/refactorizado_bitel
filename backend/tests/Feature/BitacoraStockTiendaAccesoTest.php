<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BitacoraStockTiendaAccesoTest extends TestCase
{
    use RefreshDatabase;

    private function crearTienda(string $codigo): int
    {
        return DB::table('tiendas')->insertGetId([
            'codigo' => $codigo,
            'nombre' => "Tienda {$codigo}",
        ]);
    }

    private function crearMovimiento(int $tiendaIntId): void
    {
        DB::table('historial_inventario')->insert([
            'tienda_id' => $tiendaIntId,
            'accion' => 'SUMA',
            'cantidad' => 5,
            'fecha_hora' => now(),
        ]);
    }

    public function test_vendedor_solo_ve_movimientos_de_su_propia_tienda_en_index(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $vendedor = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/bitacora-stock')
            ->assertOk()
            ->assertJsonPath('kpis.total_mov', 1)
            ->assertJsonPath('movimientos.total', 1);
    }

    public function test_vendedor_no_ve_movimientos_de_otra_tienda_aunque_lo_pida_por_parametro(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $vendedor = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/bitacora-stock?tienda=T02')
            ->assertOk()
            ->assertJsonPath('movimientos.total', 1);
    }

    public function test_vendedor_solo_ve_kpis_de_su_propia_tienda(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $vendedor = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/bitacora-stock/kpis')
            ->assertOk()
            ->assertJsonPath('total_mov', 1);
    }

    public function test_vendedor_solo_exporta_movimientos_de_su_propia_tienda(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $vendedor = Usuario::factory()->vendedor('T01')->create();

        $response = $this->actingAs($vendedor, 'sanctum')
            ->get('/api/v1/bitacora-stock/exportar')
            ->assertOk();

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($tmp, $response->streamedContent());
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
        $filas = $spreadsheet->getActiveSheet()->getHighestRow();
        unlink($tmp);

        // 1 fila de encabezado + 1 movimiento de T01 (no el de T02).
        $this->assertSame(2, $filas);
    }

    public function test_admin_ve_movimientos_de_todas_las_tiendas(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bitacora-stock')
            ->assertOk()
            ->assertJsonPath('kpis.total_mov', 2)
            ->assertJsonPath('movimientos.total', 2);
    }

    public function test_vendedor_sin_tienda_id_asignada_no_ve_nada(): void
    {
        $t01 = $this->crearTienda('T01');
        $t02 = $this->crearTienda('T02');
        $this->crearMovimiento($t01);
        $this->crearMovimiento($t02);

        $sinTienda = Usuario::factory()->vendedor('T01')->create(['tienda_id' => null]);

        $this->actingAs($sinTienda, 'sanctum')
            ->getJson('/api/v1/bitacora-stock')
            ->assertOk()
            ->assertJsonPath('kpis.total_mov', 0)
            ->assertJsonPath('movimientos.total', 0);

        $this->actingAs($sinTienda, 'sanctum')
            ->getJson('/api/v1/bitacora-stock/kpis')
            ->assertOk()
            ->assertJsonPath('total_mov', 0);
    }
}
