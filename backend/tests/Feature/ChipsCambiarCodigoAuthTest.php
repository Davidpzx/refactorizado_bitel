<?php

namespace Tests\Feature;

use App\Models\InventarioChip;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de ChipsController::cambiarCodigo(): la propiedad
 * se valida resolviendo el codigo de tienda del registro origen via
 * DB::table('tiendas')->value('codigo'), que devuelve null si la tienda ya no
 * existe (FK huerfana). Si ademas el usuario no-admin no tiene tienda_id,
 * null !== null era false y el chequeo se colaba.
 */
class ChipsCambiarCodigoAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_sin_tienda_id_no_mueve_chips_de_tienda_huerfana(): void
    {
        $chip = InventarioChip::create([
            'tienda_id'     => 999999, // no existe en `tiendas`
            'tienda_origen' => 'HUERFANA',
            'tipo_chip'     => 'FÍSICO',
            'stock_actual'  => 10,
        ]);

        $usuarioSinTienda = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->postJson("/api/v1/chips/{$chip->id}/cambiar-codigo", [
                'codigo_destino' => 'PUNDA50',
                'cantidad'       => 5,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(10, $chip->fresh()->stock_actual);
    }

    public function test_usuario_de_la_misma_tienda_mueve_chips(): void
    {
        $tienda = Tienda::create(['codigo' => 'PUNDA50', 'nombre' => 'Tienda 50']);
        $chip = InventarioChip::create([
            'tienda_id'     => $tienda->id,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip'     => 'FÍSICO',
            'stock_actual'  => 10,
        ]);

        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/chips/{$chip->id}/cambiar-codigo", [
                'codigo_destino' => 'DEST01',
                'cantidad'       => 5,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame(5, $chip->fresh()->stock_actual);
    }

    public function test_usuario_de_otra_tienda_no_mueve_chips(): void
    {
        $tienda = Tienda::create(['codigo' => 'PUNDA50', 'nombre' => 'Tienda 50']);
        $chip = InventarioChip::create([
            'tienda_id'     => $tienda->id,
            'tienda_origen' => 'PUNDA50',
            'tipo_chip'     => 'FÍSICO',
            'stock_actual'  => 10,
        ]);

        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/chips/{$chip->id}/cambiar-codigo", [
                'codigo_destino' => 'DEST01',
                'cantidad'       => 5,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(10, $chip->fresh()->stock_actual);
    }
}
