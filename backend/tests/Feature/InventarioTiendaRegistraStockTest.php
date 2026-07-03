<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioTiendaRegistraStockTest extends TestCase
{
    use RefreshDatabase;

    private function itemBase(): array
    {
        return [
            'tienda_id' => 'T01', 'producto_nombre' => 'Cargador USB-C', 'tipo' => 'ACCESORIO',
            'precio_costo' => 10, 'precio_minimo' => 15, 'precio_normal' => 20,
            'cantidad' => 5, 'estado' => 'DISPONIBLE',
        ];
    }

    public function test_tienda_sin_dni_autoriza_falla(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', $this->itemBase())
            ->assertStatus(422);
    }

    public function test_tienda_con_dni_no_valido_falla(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'dni_autoriza' => '99999999'])
            ->assertStatus(422);
    }

    public function test_tienda_no_puede_registrar_para_otra_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'tienda_id' => 'T02', 'dni_autoriza' => '87654321'])
            ->assertStatus(403);
    }

    public function test_tienda_con_dni_valido_y_propia_tienda_registra_stock(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'dni_autoriza' => '87654321'])
            ->assertCreated();

        $this->assertDatabaseHas('inventario_tiendas', ['tienda_id' => 'T01', 'producto_nombre' => 'Cargador USB-C']);
    }

    public function test_admin_sigue_registrando_stock_sin_dni(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', $this->itemBase())
            ->assertCreated();
    }
}
