<?php

namespace Tests\Feature;

use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre un segundo hueco (mismo patron null===null) encontrado en
 * InventarioController::fijarPrecioAgente(), no listado originalmente pero detectado
 * al auditar el archivo: `(string) $producto->tienda_id !== (string) $user->tienda_id`.
 * Con ambos lados en '' (equivalente a "sin tienda" en esta tabla NOT NULL), el cast a
 * string igualaba '' === '' y el guard no bloqueaba: un no-admin sin tienda podia fijar
 * el precio de venta de un producto de una tienda huerfana.
 */
class InventarioFijarPrecioAgenteAuthTest extends TestCase
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

    private function crearAgenteActivo(string $dni = '11112222'): void
    {
        \Illuminate\Support\Facades\DB::table('agentes')->insert([
            'dni'     => $dni,
            'nombres' => 'Agente Autoriza',
            'estado'  => 'ACTIVO',
        ]);
    }

    public function test_usuario_de_su_propia_tienda_puede_fijar_precio(): void
    {
        $item = $this->crearItem('PUNDA50');
        $this->crearAgenteActivo();
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/inventario/{$item->id}/precio-agente", [
                'precio_normal' => 199,
                'dni_autoriza'  => '11112222',
            ]);

        $response->assertOk();
        $this->assertSame('199.00', $item->fresh()->precio_normal);
    }

    public function test_usuario_de_otra_tienda_recibe_403_y_no_cambia_precio(): void
    {
        $item = $this->crearItem('PUNDA50');
        $this->crearAgenteActivo();
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/inventario/{$item->id}/precio-agente", [
                'precio_normal' => 199,
                'dni_autoriza'  => '11112222',
            ]);

        $response->assertForbidden();
        $this->assertSame('150.00', $item->fresh()->precio_normal);
    }

    public function test_usuario_sin_tienda_e_item_sin_tienda_no_cambia_precio(): void
    {
        $item = $this->crearItem('');
        $this->crearAgenteActivo();
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->postJson("/api/v1/inventario/{$item->id}/precio-agente", [
                'precio_normal' => 199,
                'dni_autoriza'  => '11112222',
            ]);

        $response->assertForbidden();
        $this->assertSame('150.00', $item->fresh()->precio_normal);
    }
}
