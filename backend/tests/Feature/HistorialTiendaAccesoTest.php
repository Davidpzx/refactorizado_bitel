<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistorialTiendaAccesoTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tiendaId, array $overrides = []): int
    {
        return DB::table('reportes')->insertGetId(array_replace([
            'agente_id' => 1,
            'tienda_id' => $tiendaId,
            'fecha' => '2026-06-10',
            'estado' => 'enviado',
            'total_calculado' => 100,
            'efectivo_esperado' => 100,
            'efectivo_entregado' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_tienda_puede_ver_historial_de_su_propia_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01');
        $this->crearReporte('T01');
        $this->crearReporte('T02');

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/historial')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_tienda_no_ve_reportes_de_otra_tienda_aunque_lo_pida_por_parametro(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01');
        $this->crearReporte('T02');

        $response = $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/historial?tienda=T02')
            ->assertOk();

        $tiendas = collect($response->json('data'))->pluck('tienda_id')->unique();
        $this->assertSame(['T01'], $tiendas->values()->all());
    }

    public function test_historial_kpis_scoped_a_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01', ['total_calculado' => 50]);
        $this->crearReporte('T02', ['total_calculado' => 999]);

        $response = $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/historial/kpis')
            ->assertOk()
            ->assertJsonPath('totales.total_reportes', 1)
            ->assertJsonPath('ganancia_total', null);

        $this->assertEquals(50, (float) $response->json('totales.total_general'));
    }

    public function test_historial_exportar_accesible_a_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01');

        $this->actingAs($tienda, 'sanctum')
            ->getJson('/api/v1/historial/exportar')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_admin_sigue_viendo_todas_las_tiendas(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearReporte('T01');
        $this->crearReporte('T02');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/historial')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
