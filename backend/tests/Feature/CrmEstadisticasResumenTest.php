<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmEstadisticasResumenTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_tienda_obtiene_resumen_de_su_propia_tienda(): void
    {
        $user = Usuario::factory()->jefeTienda('T01')->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/crm/estadisticas-resumen?tienda_id=T01');

        $response->assertOk()
            ->assertJsonStructure(['leads_activos', 'conversion_mes', 'ventas_mes']);
    }

    public function test_jefe_tienda_no_puede_pedir_resumen_de_otra_tienda(): void
    {
        $user = Usuario::factory()->jefeTienda('T01')->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/crm/estadisticas-resumen?tienda_id=T02');

        $response->assertStatus(403);
    }
}
