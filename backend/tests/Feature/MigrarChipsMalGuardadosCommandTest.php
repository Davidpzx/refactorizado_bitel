<?php

namespace Tests\Feature;

use App\Models\InventarioChip;
use App\Models\InventarioTienda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrarChipsMalGuardadosCommandTest extends TestCase
{
    use RefreshDatabase;

    private function chipMalGuardado(array $overrides = []): InventarioTienda
    {
        return InventarioTienda::create([
            'tienda_id' => 'T01', 'producto_nombre' => 'Chip Claro Prepago', 'tipo' => 'CHIP',
            'precio_costo' => 0, 'precio_minimo' => 0, 'precio_normal' => 0,
            'cantidad' => 10, 'estado' => 'DISPONIBLE', 'fecha_registro' => now(),
            ...$overrides,
        ]);
    }

    public function test_dry_run_no_mueve_nada(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $this->chipMalGuardado();

        $this->artisan('inventario:migrar-chips-mal-guardados')->assertExitCode(0);

        $this->assertDatabaseCount('inventario_tiendas', 1);
        $this->assertDatabaseCount('inventario_chips', 0);
    }

    public function test_force_mueve_filas_de_inventario_tiendas_a_inventario_chips(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $this->chipMalGuardado(['cantidad' => 10]);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('inventario_tiendas', 0);
        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ]);
    }

    public function test_force_suma_varias_filas_de_la_misma_tienda_en_un_solo_registro(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $this->chipMalGuardado(['cantidad' => 10]);
        $this->chipMalGuardado(['cantidad' => 5]);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertSame(1, InventarioChip::where('tienda_id', 1)->where('tienda_origen', 'T01')->count());
        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 15,
        ]);
    }

    public function test_force_es_idempotente(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $this->chipMalGuardado(['cantidad' => 10]);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);
        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ]);
        $this->assertSame(1, InventarioChip::count());
    }

    public function test_force_acumula_sobre_stock_existente_en_inventario_chips(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        InventarioChip::create(['tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 3]);
        $this->chipMalGuardado(['cantidad' => 10]);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 13,
        ]);
    }

    public function test_force_omite_filas_de_tienda_sin_mapeo_y_las_deja_intactas(): void
    {
        $this->chipMalGuardado(['tienda_id' => 'NOEXISTE']);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('inventario_tiendas', 1);
        $this->assertDatabaseCount('inventario_chips', 0);
    }

    public function test_no_toca_filas_equipo_o_accesorio(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        InventarioTienda::create([
            'tienda_id' => 'T01', 'producto_nombre' => 'Cargador', 'tipo' => 'ACCESORIO',
            'precio_costo' => 5, 'precio_minimo' => 8, 'precio_normal' => 10,
            'cantidad' => 3, 'estado' => 'DISPONIBLE', 'fecha_registro' => now(),
        ]);

        $this->artisan('inventario:migrar-chips-mal-guardados', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseCount('inventario_tiendas', 1);
        $this->assertDatabaseCount('inventario_chips', 0);
    }
}
