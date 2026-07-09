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

    // ── Flujo legacy de 2 pasos: tienda ingresa sin precio → gerencia lo fija ──

    private function agenteT01(): void
    {
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
    }

    /** Paridad tienda/guardar_stock.php: el form de la tienda no tiene campo de precio. */
    public function test_tienda_registra_stock_sin_precios_y_cae_en_precios_pendientes(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->agenteT01();

        $sinPrecios = $this->itemBase();
        unset($sinPrecios['precio_costo'], $sinPrecios['precio_minimo'], $sinPrecios['precio_normal']);

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$sinPrecios, 'dni_autoriza' => '87654321'])
            ->assertCreated();

        $this->assertDatabaseHas('inventario_tiendas', [
            'producto_nombre' => 'Cargador USB-C',
            'precio_costo' => 0, 'precio_minimo' => 0, 'precio_normal' => 0,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/inventario/precios-pendientes')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.producto_nombre', 'Cargador USB-C');
    }

    /** Precios explícitamente null equivalen a omitirlos (no revientan la columna NOT NULL). */
    public function test_precios_null_se_tratan_como_pendientes(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', [
                ...$this->itemBase(),
                'precio_costo' => null, 'precio_minimo' => null, 'precio_normal' => null,
            ])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/precios-pendientes')
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    /** Paso 2: con precios completos el item NO debe seguir apareciendo como pendiente. */
    public function test_stock_con_precios_completos_no_aparece_en_precios_pendientes(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', $this->itemBase())
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/precios-pendientes')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_equipos_con_imeis_se_registran_sin_precios(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->agenteT01();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [
                'tienda_id' => 'T01', 'producto_nombre' => 'Galaxy A15', 'tipo' => 'EQUIPO',
                'imei_seriales' => ['358461082345678', '358461082345679'],
                'cantidad' => 1, 'estado' => 'DISPONIBLE', 'dni_autoriza' => '87654321',
            ])
            ->assertCreated()
            ->assertJsonPath('registrados', 2);

        $this->assertDatabaseHas('inventario_tiendas', [
            'imei_serial' => '358461082345678', 'precio_normal' => 0,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/inventario/precios-pendientes')
            ->assertOk()
            ->assertJsonPath('total', 2);
    }
}
