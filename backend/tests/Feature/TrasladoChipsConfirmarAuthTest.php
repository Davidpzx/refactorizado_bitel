<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioChip;
use App\Models\Tienda;
use App\Models\TrasladoChip;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de TrasladoChipsController::confirmar(): el guard
 * comparaba `$traslado->tienda_destino !== $user->tienda_id` de forma directa. La
 * columna traslados_chips.tienda_destino es NOT NULL, asi que el equivalente de
 * "sin tienda" es cadena vacia: con ambos lados en '' el guard no bloqueaba y un
 * no-admin sin tienda podia confirmar la recepcion de chips de cualquier traslado
 * huerfano.
 *
 * No se cubre aqui el caso exitoso (200): el codigo posterior al guard usa
 * `whereRaw('... COLLATE utf8mb4_general_ci ...')`, sintaxis MySQL que SQLite (motor
 * de tests) no soporta; ningun test existente en el repo ejerce ese tramo de
 * confirmar(). Es una limitacion preexistente del entorno de tests, no relacionada
 * con este fix.
 */
class TrasladoChipsConfirmarAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgenteAutorizador(string $dni = '11112222'): Agente
    {
        return Agente::create([
            'dni' => $dni, 'nombres' => 'Agente Autoriza', 'estado' => 'ACTIVO', 'es_gerencia' => '0',
        ]);
    }

    private function crearTrasladoPendiente(string $tiendaDestino): TrasladoChip
    {
        $tiendaOrigen = Tienda::create(['codigo' => 'PUNDA50', 'nombre' => 'Origen']);
        $chip = InventarioChip::create([
            'tienda_id' => $tiendaOrigen->id, 'tienda_origen' => 'PUNDA50',
            'tipo_chip' => 'FÍSICO', 'stock_actual' => 10,
        ]);

        return TrasladoChip::create([
            'chip_id_origen' => $chip->id, 'tienda_origen' => 'PUNDA50', 'tienda_destino' => $tiendaDestino,
            'cantidad' => 2, 'estado' => 'PENDIENTE', 'creado_por' => 1,
        ]);
    }

    public function test_usuario_de_otra_tienda_no_puede_confirmar(): void
    {
        Tienda::create(['codigo' => 'PUNDA11', 'nombre' => 'Destino']);
        $traslado = $this->crearTrasladoPendiente('PUNDA11');
        $this->crearAgenteAutorizador();
        $usuario = Usuario::factory()->vendedor('PUNDA99')->create();

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/v1/traslados-chips/{$traslado->id}/confirmar", ['auth_dni' => '11112222']);

        $response->assertStatus(422);
        $this->assertSame('PENDIENTE', $traslado->fresh()->estado);
    }

    public function test_usuario_sin_tienda_y_traslado_sin_tienda_destino_no_puede_confirmar(): void
    {
        // Crea tambien la tienda destino '' para que, si el guard fallara (bug),
        // la ejecucion avance a codigo posterior en vez de fallar por una razon
        // no relacionada ("tienda destino no encontrada"), lo que enmascararia el hueco.
        Tienda::create(['codigo' => '', 'nombre' => 'Huerfana']);
        $traslado = $this->crearTrasladoPendiente('');
        $this->crearAgenteAutorizador();
        $usuarioSinTienda = Usuario::factory()->vendedor('')->create(['tienda_id' => '']);

        $response = $this->actingAs($usuarioSinTienda, 'sanctum')
            ->postJson("/api/v1/traslados-chips/{$traslado->id}/confirmar", ['auth_dni' => '11112222']);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Solo la tienda destino puede confirmar este traslado.');
        $this->assertSame('PENDIENTE', $traslado->fresh()->estado);
    }
}
