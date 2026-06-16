<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModoDiosTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_store_reporte_en_nombre_de_otra_tienda(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agenteId = $this->crearTiendaYAgente('PUNDA99');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload($agenteId, 'PUNDA99'));

        $response->assertStatus(201);
        $this->assertDatabaseHas('reportes', [
            'agente_id' => $agenteId,
            'tienda_id' => 'PUNDA99',
        ]);
    }

    public function test_tienda_no_puede_usar_modo_dios(): void
    {
        $usuario = $this->vendedorVinculado('PUNDA50');
        $this->crearTiendaYAgente('PUNDA99', 9901);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/reportes', $this->payload((int) $usuario->agente_id, 'PUNDA99'));

        $response->assertStatus(403);
    }

    private function crearTiendaYAgente(string $tienda, int $agenteId = 9900): int
    {
        DB::table('tiendas')->insertOrIgnore([
            'codigo' => $tienda,
            'nombre' => "Tienda {$tienda}",
            'activo' => true,
        ]);

        DB::table('agentes')->insert([
            'id' => $agenteId,
            'dni' => str_pad((string) $agenteId, 8, '0', STR_PAD_LEFT),
            'nombres' => "Agente {$tienda}",
            'estado' => 'ACTIVO',
            'tienda_base' => $tienda,
        ]);

        return $agenteId;
    }

    private function payload(int $agenteId, string $tienda): array
    {
        return [
            '_modo_dios' => true,
            'agente_id' => $agenteId,
            'tienda_id' => $tienda,
            'fecha' => '2026-06-15',
            'caja_inicial' => 0,
            'yape' => 0,
            'bipay' => 0,
            'transferencia' => 0,
            'recarga_bipay' => 0,
            'pago_servicio' => 0,
            'pago_krece' => 0,
            'pago_payjoy' => 0,
            'tickets_tusamy' => 0,
            'retiro_bipay' => 0,
            'efectivo_entregado' => 0,
            'total_salidas' => 0,
            'destino_efectivo' => 'EN_CAJA',
            'ventas' => [],
        ];
    }
}
