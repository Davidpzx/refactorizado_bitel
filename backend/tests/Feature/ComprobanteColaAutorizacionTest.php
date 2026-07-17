<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComprobanteColaAutorizacionTest extends TestCase
{
    use RefreshDatabase;

    private const EMITIR = '/api/v1/comprobantes-cola/emitir-ahora';

    public function test_agente_no_puede_emitir_comprobantes_ni_generar_links(): void
    {
        $agente = Usuario::factory()->agenteVentas(null, 'T01')->create();
        $cola = ComprobanteCola::factory()->aceptada()->create(['tienda_id' => 'T01']);

        $this->actingAs($agente, 'sanctum')
            ->postJson(self::EMITIR, ['cola_id' => $cola->id])
            ->assertForbidden();

        $this->actingAs($agente, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/link")
            ->assertForbidden();
    }

    public function test_jefe_no_puede_emitir_una_fila_de_otra_tienda(): void
    {
        Http::fake();
        $jefe = Usuario::factory()->jefeTienda('T01')->create();
        $cola = ComprobanteCola::factory()->create(['tienda_id' => 'T02']);

        $this->actingAs($jefe, 'sanctum')
            ->postJson(self::EMITIR, ['cola_id' => $cola->id])
            ->assertForbidden();

        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $cola->refresh()->estado);
        Http::assertNothingSent();
    }

    public function test_jefe_no_puede_encolar_un_comprobante_para_otra_tienda(): void
    {
        $jefe = Usuario::factory()->jefeTienda('T01')->create();

        $this->actingAs($jefe, 'sanctum')
            ->postJson(self::EMITIR, [
                'tienda_id' => 'T02',
                'tipo_comprobante' => ComprobanteCola::TIPO_BOLETA,
                'payload' => ['items' => []],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('comprobantes_cola', 0);
    }

    public function test_jefe_no_puede_generar_link_de_otra_tienda(): void
    {
        $jefe = Usuario::factory()->jefeTienda('T01')->create();
        $cola = ComprobanteCola::factory()->aceptada()->create(['tienda_id' => 'T02']);

        $this->actingAs($jefe, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/link")
            ->assertForbidden();
    }

    public function test_gerente_puede_generar_link_de_cualquier_tienda(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $cola = ComprobanteCola::factory()->aceptada()->create(['tienda_id' => 'T02']);

        $this->actingAs($gerente, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/link")
            ->assertOk();
    }
}
