<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioTienda;
use App\Models\Tienda;
use App\Models\TrasladoStock;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad null===null en los guards de propiedad/confirmacion
 * por tienda de TrasladoController: store() (individual y masivo), confirmar() y
 * confirmarLote() comparaban `tienda !== tienda_id` de forma directa. Las columnas
 * involucradas (inventario_tiendas.tienda_id, traslados_stock.tienda_destino) son
 * NOT NULL, asi que el equivalente de "sin tienda" es cadena vacia: con ambos lados
 * en '' el guard no bloqueaba.
 *
 * Tambien cubre el hueco relacionado en store(): `$esGerenteDeOrigen` comparaba
 * `$agente->tienda_base === $user->tienda_id`; con un gerente sin tienda_base y un
 * usuario sin tienda_id, ambos null hacian match y el traslado se creaba en
 * PENDIENTE saltandose la aprobacion del admin.
 */
class TrasladoControllerAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgenteAutorizador(string $dni = '11112222', bool $esGerencia = false, ?string $tiendaBase = 'PUNDA50'): Agente
    {
        return Agente::create([
            'dni' => $dni, 'nombres' => 'Agente Autoriza', 'estado' => 'ACTIVO',
            'es_gerencia' => $esGerencia ? '1' : '0', 'tienda_base' => $tiendaBase,
        ]);
    }

    private function crearItem(string $tienda): InventarioTienda
    {
        return InventarioTienda::create([
            'tienda_id' => $tienda, 'producto_nombre' => 'Equipo Y', 'tipo' => 'EQUIPO',
            'precio_costo' => 100, 'precio_minimo' => 120, 'precio_normal' => 150,
            'cantidad' => 1, 'estado' => 'DISPONIBLE',
        ]);
    }

    // ── store() individual: linea 183 ───────────────────────────────────────────

    public function test_store_individual_usuario_de_su_tienda_puede_trasladar(): void
    {
        $item = $this->crearItem('PUNDA50');
        Tienda::create(['codigo' => 'DEST01', 'nombre' => 'Destino']);
        $this->crearAgenteAutorizador();
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/v1/traslados', [
            'producto_id' => $item->id, 'cantidad' => 1,
            'tienda_destino' => 'DEST01', 'auth_dni' => '11112222',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertSame('TRASLADO', $item->fresh()->estado);
    }

    public function test_store_individual_usuario_de_otra_tienda_no_puede_trasladar(): void
    {
        $item = $this->crearItem('PUNDA50');
        Tienda::create(['codigo' => 'DEST01', 'nombre' => 'Destino']);
        $this->crearAgenteAutorizador();
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/v1/traslados', [
            'producto_id' => $item->id, 'cantidad' => 1,
            'tienda_destino' => 'DEST01', 'auth_dni' => '11112222',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame('DISPONIBLE', $item->fresh()->estado);
    }

    public function test_store_individual_usuario_sin_tienda_e_item_sin_tienda_no_puede_trasladar(): void
    {
        $item = $this->crearItem('');
        Tienda::create(['codigo' => 'DEST01', 'nombre' => 'Destino']);
        $this->crearAgenteAutorizador();
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')->postJson('/api/v1/traslados', [
            'producto_id' => $item->id, 'cantidad' => 1,
            'tienda_destino' => 'DEST01', 'auth_dni' => '11112222',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame('DISPONIBLE', $item->fresh()->estado);
    }

    // ── store() masivo: linea 128 ───────────────────────────────────────────────

    public function test_store_masivo_omite_items_que_no_pertenecen_al_usuario_sin_tienda(): void
    {
        $item = $this->crearItem('');
        Tienda::create(['codigo' => 'DEST01', 'nombre' => 'Destino']);
        $this->crearAgenteAutorizador();
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')->postJson('/api/v1/traslados', [
            'equipos_ids' => json_encode([$item->id]), 'auth_dni' => '11112222',
            'tienda_destino' => 'DEST01',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', false);
        $this->assertSame('DISPONIBLE', $item->fresh()->estado);
        $this->assertSame(0, TrasladoStock::count());
    }

    // ── confirmar(): linea 275 ───────────────────────────────────────────────────

    private function crearTrasladoPendiente(string $tiendaDestino): TrasladoStock
    {
        $item = $this->crearItem($tiendaDestino === '' ? '' : 'PUNDA50');
        $item->update(['estado' => 'TRASLADO']);

        return TrasladoStock::create([
            'producto_id' => $item->id, 'tienda_origen' => 'PUNDA50', 'tienda_destino' => $tiendaDestino,
            'cantidad' => 1, 'estado' => 'PENDIENTE', 'creado_por' => 1,
        ]);
    }

    public function test_confirmar_usuario_de_tienda_destino_puede_confirmar(): void
    {
        $traslado = $this->crearTrasladoPendiente('PUNDA50');
        $this->crearAgenteAutorizador();
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/traslados/{$traslado->id}/confirmar", ['auth_dni' => '11112222']);

        $response->assertOk();
        $this->assertSame('CONFIRMADO', $traslado->fresh()->estado);
    }

    public function test_confirmar_usuario_de_otra_tienda_no_puede_confirmar(): void
    {
        $traslado = $this->crearTrasladoPendiente('PUNDA50');
        $this->crearAgenteAutorizador();
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/traslados/{$traslado->id}/confirmar", ['auth_dni' => '11112222']);

        $response->assertStatus(422);
        $this->assertSame('PENDIENTE', $traslado->fresh()->estado);
    }

    public function test_confirmar_usuario_sin_tienda_y_traslado_sin_tienda_destino_no_puede_confirmar(): void
    {
        $traslado = $this->crearTrasladoPendiente('');
        $this->crearAgenteAutorizador();
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->postJson("/api/v1/traslados/{$traslado->id}/confirmar", ['auth_dni' => '11112222']);

        $response->assertStatus(422);
        $this->assertSame('PENDIENTE', $traslado->fresh()->estado);
    }

    // ── confirmarLote(): linea 408 ───────────────────────────────────────────────

    public function test_confirmar_lote_usuario_sin_tienda_y_lote_sin_tienda_destino_no_puede_confirmar(): void
    {
        $item = $this->crearItem('');
        $item->update(['estado' => 'TRASLADO']);
        $agente = $this->crearAgenteAutorizador();
        $traslado = TrasladoStock::create([
            'producto_id' => $item->id, 'tienda_origen' => 'PUNDA50', 'tienda_destino' => '',
            'cantidad' => 1, 'estado' => 'PENDIENTE', 'creado_por' => 1, 'codigo_lote' => 'LOTE1',
        ]);
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->postJson('/api/v1/traslados/lote/LOTE1/confirmar', [
                'auth_agente_id' => $agente->id, 'auth_dni' => '11112222',
            ]);

        $response->assertStatus(422);
        $this->assertSame('PENDIENTE', $traslado->fresh()->estado);
    }
}
