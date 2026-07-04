<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardKpisTiendaAccesoTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tiendaId, float $total = 100): void
    {
        DB::table('reportes')->insert([
            'agente_id' => 1,
            'tienda_id' => $tiendaId,
            'fecha' => now()->toDateString(),
            'estado' => 'enviado',
            'total_calculado' => $total,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_vendedor_solo_ve_kpis_de_su_propia_tienda(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01', 100);
        $this->crearReporte('T02', 500);

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('totales.total_reportes', 1)
            ->assertJsonPath('totales.total_general', 100);
    }

    public function test_vendedor_no_ve_kpis_de_otra_tienda_aunque_lo_pida_por_parametro(): void
    {
        $vendedor = Usuario::factory()->vendedor('T01')->create();
        $this->crearReporte('T01', 100);
        $this->crearReporte('T02', 500);

        $this->actingAs($vendedor, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis?tienda=T02')
            ->assertOk()
            ->assertJsonPath('totales.total_reportes', 1);
    }

    public function test_admin_ve_kpis_de_todas_las_tiendas(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $this->crearReporte('T01', 100);
        $this->crearReporte('T02', 500);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('totales.total_reportes', 2);
    }

    public function test_vendedor_sin_tienda_id_asignada_no_ve_nada(): void
    {
        $sinTienda = Usuario::factory()->vendedor('T01')->create(['tienda_id' => null]);
        $this->crearReporte('T01', 100);
        $this->crearReporte('T02', 500);

        $this->actingAs($sinTienda, 'sanctum')
            ->getJson('/api/v1/dashboard/kpis')
            ->assertOk()
            ->assertJsonPath('totales.total_reportes', 0);
    }
}
