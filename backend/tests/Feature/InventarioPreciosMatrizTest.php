<?php

namespace Tests\Feature;

use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /inventario/precios-matriz — matriz COMPLETA de precios (paridad legacy
 * gerencia/revisar_stock.php sin el filtro de "solo pendientes"). A diferencia de
 * precios-pendientes, aquí sí aparecen los items que YA tienen precio.
 */
class InventarioPreciosMatrizTest extends TestCase
{
    use RefreshDatabase;

    private function crearItem(array $overrides = []): InventarioTienda
    {
        return InventarioTienda::create(array_merge([
            'tienda_id'       => 'PUNDA50',
            'producto_nombre' => 'Equipo X',
            'tipo'            => 'EQUIPO',
            'imei_serial'     => null,
            'precio_costo'    => 100,
            'precio_minimo'   => 120,
            'precio_normal'   => 150,
            'cantidad'        => 1,
            'estado'          => 'DISPONIBLE',
        ], $overrides));
    }

    public function test_matriz_devuelve_200_con_la_forma_esperada_e_incluye_items_con_precio(): void
    {
        $conPrecio = $this->crearItem(['producto_nombre' => 'Con Precio']);
        $sinPrecio = $this->crearItem([
            'producto_nombre' => 'Sin Precio',
            'precio_minimo'   => 0,
            'precio_normal'   => 0,
        ]);
        // El CHIP nunca debe aparecer en la matriz de precios.
        $this->crearItem(['producto_nombre' => 'Chip Y', 'tipo' => 'CHIP', 'imei_serial' => null]);

        $admin = Usuario::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/precios-matriz');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'tienda_id', 'producto_nombre', 'tipo', 'imei_serial', 'cantidad', 'precio_costo', 'precio_minimo', 'precio_normal', 'fecha_registro']],
                'total',
                'tiendas',
            ]);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($conPrecio->id), 'La matriz debe incluir items con precio ya fijado.');
        $this->assertTrue($ids->contains($sinPrecio->id), 'La matriz debe incluir items sin precio.');
        $this->assertSame(2, $response->json('total'), 'El CHIP no debe contarse en la matriz.');
    }

    public function test_matriz_filtra_por_tienda_tipo_y_busqueda(): void
    {
        $this->crearItem(['tienda_id' => 'PUNDA50', 'producto_nombre' => 'iPhone 15', 'tipo' => 'EQUIPO']);
        $this->crearItem(['tienda_id' => 'PUNDA11', 'producto_nombre' => 'Funda Azul', 'tipo' => 'ACCESORIO']);

        $admin = Usuario::factory()->admin()->create();

        $porTienda = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/precios-matriz?tienda=PUNDA50');
        $porTienda->assertOk();
        $this->assertSame(1, $porTienda->json('total'));
        $this->assertSame('PUNDA50', $porTienda->json('data.0.tienda_id'));

        $porBusqueda = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/inventario/precios-matriz?q=Funda');
        $porBusqueda->assertOk();
        $this->assertSame(1, $porBusqueda->json('total'));
        $this->assertSame('Funda Azul', $porBusqueda->json('data.0.producto_nombre'));
    }

    public function test_matriz_requiere_admin(): void
    {
        $vendedor = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/inventario/precios-matriz')
            ->assertForbidden();
    }
}
