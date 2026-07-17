<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteBcpAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_agente_no_puede_insertar_reportes_bcp(): void
    {
        $agente = Usuario::factory()->agenteVentas()->create();

        $this->actingAs($agente, 'sanctum')
            ->postJson('/api/v1/reporte-bcp', [
                'fecha' => now()->toDateString(),
                'sucursal_id' => 1,
                'turno_hora' => '09:00-18:00',
                'cantidad_operaciones' => 9999,
                'queda_efectivo' => 999999,
                'queda_tarjeta' => 999999,
            ])
            ->assertForbidden();
    }
}
