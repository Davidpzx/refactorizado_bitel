<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioChip;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy (procesar_traslado.php / procesar_traslado_chips.php): si el DNI que
 * autoriza el envio pertenece a un agente con es_gerencia=1, el traslado se crea directo
 * en PENDIENTE (sin aprobacion admin), aunque el rol de sesion sea "tienda"/"vendedor".
 * Ver docs/comparacion/gap_tienda_reportes_2026-07-02.md, top-5 gap #4.
 */
class TrasladoGerenciaDirectoTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(string $dni, bool $esGerencia, string $tienda = 'T01'): Agente
    {
        return Agente::create([
            'dni' => $dni, 'nombres' => 'Agente '.$dni, 'estado' => 'ACTIVO',
            'tienda_base' => $tienda, 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200, 'es_gerencia' => $esGerencia,
        ]);
    }

    private function crearProductoDisponible(string $tienda = 'T01'): int
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => $tienda, 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        return (int) InventarioTienda::create([
            'tienda_id' => $tienda, 'tipo' => 'ACCESORIO', 'producto_nombre' => 'Cargador',
            'cantidad' => 5, 'estado' => 'DISPONIBLE', 'fecha_registro' => now(),
        ])->id;
    }

    public function test_tienda_con_dni_de_gerente_crea_traslado_de_equipo_directo_en_pendiente(): void
    {
        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $gerente = $this->crearAgente('55511111', true, 'T01');
        $id = $this->crearProductoDisponible('T01');

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '55511111',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('EN TRÁNSITO', $response->json('message'));

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE', 'enviado_por_id' => $gerente->id,
        ]);
    }

    public function test_tienda_con_dni_de_gerente_de_otra_tienda_no_activa_bypass_en_traslado_de_equipo(): void
    {
        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $gerenteOtraTienda = $this->crearAgente('55566666', true, 'T02');
        $id = $this->crearProductoDisponible('T01');

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '55566666',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PENDIENTE DE APROBACIÓN', $response->json('message'));

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE_APROBACION', 'enviado_por_id' => $gerenteOtraTienda->id,
        ]);
    }

    public function test_tienda_con_dni_de_agente_normal_crea_traslado_de_equipo_pendiente_aprobacion(): void
    {
        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $agente = $this->crearAgente('55522222', false, 'T01');
        $id = $this->crearProductoDisponible('T01');

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '55522222',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PENDIENTE DE APROBACIÓN', $response->json('message'));

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE_APROBACION', 'enviado_por_id' => $agente->id,
        ]);
    }

    public function test_tienda_con_dni_de_gerente_crea_traslado_masivo_directo_en_pendiente(): void
    {
        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $gerente = $this->crearAgente('55533333', true, 'T01');
        $id = $this->crearProductoDisponible('T01');

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'equipos_ids' => json_encode([$id]), 'tienda_destino' => 'T02', 'auth_dni' => '55533333',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('EN TRÁNSITO', $response->json('message'));

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE', 'enviado_por_id' => $gerente->id,
        ]);
    }

    public function test_tienda_con_dni_de_gerente_crea_traslado_de_chips_directo_en_pendiente(): void
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T01', 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $gerente = $this->crearAgente('55544444', true, 'T01');
        $chipId = (int) InventarioChip::create([
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ])->id;

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '55544444',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('EN TRÁNSITO', $response->json('message'));

        $this->assertDatabaseHas('traslados_chips', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE', 'enviado_por_id' => $gerente->id,
        ]);
    }

    public function test_tienda_con_dni_de_agente_normal_crea_traslado_de_chips_pendiente_aprobacion(): void
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T01', 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $agente = $this->crearAgente('55555555', false, 'T01');
        $chipId = (int) InventarioChip::create([
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ])->id;

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '55555555',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PENDIENTES DE APROBACIÓN', $response->json('message'));

        $this->assertDatabaseHas('traslados_chips', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE_APROBACION', 'enviado_por_id' => $agente->id,
        ]);
    }

    public function test_tienda_con_dni_de_gerente_de_otra_tienda_no_activa_bypass_en_traslado_de_chips(): void
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T01', 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        $usuarioTienda = Usuario::factory()->vendedor('T01')->create();
        $gerenteOtraTienda = $this->crearAgente('55577777', true, 'T02');
        $chipId = (int) InventarioChip::create([
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ])->id;

        $response = $this->actingAs($usuarioTienda, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '55577777',
            ]);

        $response->assertOk()->assertJsonPath('success', true);
        $this->assertStringContainsString('PENDIENTES DE APROBACIÓN', $response->json('message'));

        $this->assertDatabaseHas('traslados_chips', [
            'tienda_destino' => 'T02', 'estado' => 'PENDIENTE_APROBACION', 'enviado_por_id' => $gerenteOtraTienda->id,
        ]);
    }
}
