<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReporteActualizarDestinoTest extends TestCase
{
    use RefreshDatabase;

    private function crearReporte(string $tiendaId): int
    {
        return DB::table('reportes')->insertGetId([
            'agente_id' => 1,
            'tienda_id' => $tiendaId,
            'fecha' => '2026-06-10',
            'estado' => 'enviado',
            'destino_efectivo' => 'TIENDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_tienda_puede_marcar_efectivo_de_su_propio_reporte(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $reporteId = $this->crearReporte('T01');

        $this->actingAs($tienda, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporteId}/destino-efectivo", [
                'destino_efectivo' => 'ENTREGADO',
            ])
            ->assertOk()
            ->assertJsonPath('destino_efectivo', 'ENTREGADO');

        $this->assertDatabaseHas('reportes', ['id' => $reporteId, 'destino_efectivo' => 'ENTREGADO']);
        $this->assertDatabaseHas('historial_reportes', ['reporte_id' => $reporteId, 'accion' => 'cambio_destino']);
    }

    public function test_tienda_puede_revertir_a_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $reporteId = $this->crearReporte('T01');

        $this->actingAs($tienda, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporteId}/destino-efectivo", ['destino_efectivo' => 'ENTREGADO'])
            ->assertOk();

        $this->actingAs($tienda, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporteId}/destino-efectivo", ['destino_efectivo' => 'TIENDA'])
            ->assertOk()
            ->assertJsonPath('destino_efectivo', 'TIENDA');
    }

    public function test_tienda_no_puede_marcar_efectivo_de_reporte_de_otra_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        $reporteId = $this->crearReporte('T02');

        $this->actingAs($tienda, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporteId}/destino-efectivo", ['destino_efectivo' => 'ENTREGADO'])
            ->assertStatus(403);

        $this->assertDatabaseHas('reportes', ['id' => $reporteId, 'destino_efectivo' => 'TIENDA']);
    }

    public function test_admin_sigue_pudiendo_marcar_cualquier_reporte(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $reporteId = $this->crearReporte('T02');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/reportes/{$reporteId}/destino-efectivo", ['destino_efectivo' => 'BANCO'])
            ->assertOk()
            ->assertJsonPath('destino_efectivo', 'BANCO');
    }
}
