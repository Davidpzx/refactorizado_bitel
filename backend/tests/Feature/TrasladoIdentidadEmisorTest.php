<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrasladoIdentidadEmisorTest extends TestCase
{
    use RefreshDatabase;

    private function crearProductoDisponible(string $tienda = 'T01'): int
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => $tienda, 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        return (int) InventarioTienda::create([
            'tienda_id' => $tienda, 'tipo' => 'ACCESORIO', 'producto_nombre' => 'Cargador',
            'cantidad' => 5, 'estado' => 'DISPONIBLE', 'fecha_registro' => now(),
        ])->id;
    }

    public function test_crear_traslado_sin_auth_dni_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02',
            ])
            ->assertStatus(422);
    }

    public function test_crear_traslado_con_dni_no_valido_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '99999999',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('traslados_stock', ['tienda_destino' => 'T02']);
    }

    public function test_crear_traslado_con_dni_valido_persiste_enviado_por_id(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agente = Agente::create([
            'dni' => '87654321', 'nombres' => 'Agente Emisor', 'estado' => 'ACTIVO',
            'tienda_base' => 'T01', 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '87654321',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'enviado_por_id' => $agente->id, 'enviado_dni' => '87654321',
        ]);
    }
}
