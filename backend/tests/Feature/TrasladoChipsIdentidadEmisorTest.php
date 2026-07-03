<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioChip;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrasladoChipsIdentidadEmisorTest extends TestCase
{
    use RefreshDatabase;

    private function crearChipDisponible(): int
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T01', 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        // inventario_chips.tienda_id es un entero (no el codigo string) -- ver
        // migracion 2026_06_11_000001_create_traslados_tables.php. No filtra por
        // el, TrasladoChipsController::store solo compara tienda_origen (string).
        return (int) InventarioChip::create([
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ])->id;
    }

    public function test_crear_traslado_chips_sin_auth_dni_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
            ])
            ->assertStatus(422);
    }

    public function test_crear_traslado_chips_con_dni_no_valido_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '99999999',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('traslados_chips', ['tienda_destino' => 'T02']);
    }

    public function test_crear_traslado_chips_con_dni_valido_persiste_enviado_por_id(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agente = Agente::create([
            'dni' => '11223344', 'nombres' => 'Agente Chips', 'estado' => 'ACTIVO',
            'tienda_base' => 'T01', 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '11223344',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('traslados_chips', [
            'tienda_destino' => 'T02', 'enviado_por_id' => $agente->id, 'enviado_dni' => '11223344',
        ]);
    }
}
