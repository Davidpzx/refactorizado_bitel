<?php

namespace Tests\Feature;

use App\Models\InventarioChip;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioChipStoreTest extends TestCase
{
    use RefreshDatabase;

    private function chipPayload(array $overrides = []): array
    {
        return [
            'tienda_id' => 'T01', 'producto_nombre' => 'Chip Claro Prepago', 'tipo' => 'CHIP',
            'precio_costo' => 0, 'precio_minimo' => 0, 'precio_normal' => 0,
            'cantidad' => 10, 'estado' => 'DISPONIBLE',
            ...$overrides,
        ];
    }

    public function test_admin_registra_chip_va_a_inventario_chips_no_a_inventario_tiendas(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', $this->chipPayload())
            ->assertCreated();

        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ]);
        $this->assertDatabaseMissing('inventario_tiendas', ['tienda_id' => 'T01', 'tipo' => 'CHIP']);
    }

    public function test_registrar_chip_dos_veces_incrementa_stock_en_vez_de_duplicar_fila(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventario', $this->chipPayload(['cantidad' => 10]))->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/inventario', $this->chipPayload(['cantidad' => 5]))->assertCreated();

        $this->assertSame(1, InventarioChip::where('tienda_id', 1)->where('tienda_origen', 'T01')->count());
        $this->assertDatabaseHas('inventario_chips', [
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 15,
        ]);
    }

    public function test_tienda_con_dni_valido_registra_chip_para_su_propia_tienda(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', $this->chipPayload(['dni_autoriza' => '87654321']))
            ->assertCreated();

        $this->assertDatabaseHas('inventario_chips', ['tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10]);
    }

    public function test_registrar_chip_para_tienda_inexistente_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', $this->chipPayload(['tienda_id' => 'NOEXISTE']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('inventario_chips', ['tienda_origen' => 'NOEXISTE']);
    }

    public function test_registrar_equipo_sigue_yendo_a_inventario_tiendas(): void
    {
        DB::table('tiendas')->insert(['id' => 1, 'codigo' => 'T01', 'nombre' => 'Tienda Uno']);
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', [
                'tienda_id' => 'T01', 'producto_nombre' => 'Samsung A15', 'tipo' => 'EQUIPO',
                'imei_serial' => '358461082345678',
                'precio_costo' => 100, 'precio_minimo' => 120, 'precio_normal' => 150,
                'cantidad' => 1, 'estado' => 'DISPONIBLE',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('inventario_tiendas', ['tienda_id' => 'T01', 'tipo' => 'EQUIPO', 'imei_serial' => '358461082345678']);
        $this->assertDatabaseCount('inventario_chips', 0);
    }
}
