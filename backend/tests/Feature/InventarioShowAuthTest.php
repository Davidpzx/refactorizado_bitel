<?php

namespace Tests\Feature;

use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de InventarioController::show(): antes del fix el
 * guard comparaba `$inventario->tienda_id !== $request->user()->tienda_id` de forma
 * directa. La columna inventario_tiendas.tienda_id es NOT NULL, asi que el equivalente
 * de "sin tienda" en este esquema es cadena vacia: si ambos lados eran '' el guard no
 * bloqueaba y un no-admin sin tienda podia ver inventario de una tienda huerfana.
 */
class InventarioShowAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearItem(string $tienda): InventarioTienda
    {
        return InventarioTienda::create([
            'tienda_id'       => $tienda,
            'producto_nombre' => 'Equipo X',
            'tipo'            => 'EQUIPO',
            'precio_costo'    => 100,
            'precio_minimo'   => 120,
            'precio_normal'   => 150,
            'cantidad'        => 1,
            'estado'          => 'DISPONIBLE',
        ]);
    }

    public function test_admin_puede_ver_cualquier_item(): void
    {
        $item = $this->crearItem('PUNDA50');
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/inventario/{$item->id}")
            ->assertOk();
    }

    public function test_usuario_puede_ver_item_de_su_propia_tienda(): void
    {
        $item = $this->crearItem('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/v1/inventario/{$item->id}")
            ->assertOk();
    }

    public function test_usuario_de_otra_tienda_recibe_403(): void
    {
        $item = $this->crearItem('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/v1/inventario/{$item->id}")
            ->assertForbidden();
    }

    public function test_usuario_sin_tienda_e_item_sin_tienda_recibe_403(): void
    {
        $item = $this->crearItem('');
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $this->actingAs($usuarioSinTienda, 'sanctum')
            ->getJson("/api/v1/inventario/{$item->id}")
            ->assertForbidden();
    }
}
