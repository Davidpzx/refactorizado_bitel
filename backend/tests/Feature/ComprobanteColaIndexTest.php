<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de `comprobantes_cola` (TICKET-010). Port de
 * `gerencia/comprobantes_emitidos.php`, que era admin-only.
 */
class ComprobanteColaIndexTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/comprobantes-cola';

    private function comoAdmin(): static
    {
        return $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum');
    }

    public function test_la_ruta_exige_autenticacion(): void
    {
        $this->getJson(self::RUTA)->assertUnauthorized();
    }

    public function test_un_rol_no_admin_no_puede_listar(): void
    {
        $this->actingAs($this->vendedorVinculado(), 'sanctum')
            ->getJson(self::RUTA)
            ->assertForbidden();
    }

    public function test_lista_paginada_con_numero_completo(): void
    {
        ComprobanteCola::factory()->aceptada()->create(['serie' => 'B001', 'correlativo' => 45]);
        ComprobanteCola::factory()->count(2)->create();

        $respuesta = $this->comoAdmin()->getJson(self::RUTA)->assertOk();

        $respuesta->assertJsonCount(3, 'data');
        $aceptada = collect($respuesta->json('data'))->firstWhere('estado', ComprobanteCola::ESTADO_ACEPTADO);
        $this->assertSame('B001-00000045', $aceptada['numero_completo']);
    }

    public function test_filtra_por_estado(): void
    {
        ComprobanteCola::factory()->aceptada()->create();
        ComprobanteCola::factory()->create();

        $respuesta = $this->comoAdmin()
            ->getJson(self::RUTA.'?estado='.ComprobanteCola::ESTADO_ACEPTADO)
            ->assertOk();

        $respuesta->assertJsonCount(1, 'data');
        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $respuesta->json('data.0.estado'));
    }

    public function test_filtra_por_tipo_comprobante(): void
    {
        ComprobanteCola::factory()->factura()->create();
        ComprobanteCola::factory()->create();

        $respuesta = $this->comoAdmin()
            ->getJson(self::RUTA.'?tipo_comprobante='.ComprobanteCola::TIPO_FACTURA)
            ->assertOk();

        $respuesta->assertJsonCount(1, 'data');
        $this->assertSame(ComprobanteCola::TIPO_FACTURA, $respuesta->json('data.0.tipo_comprobante'));
    }

    public function test_filtra_por_tienda(): void
    {
        ComprobanteCola::factory()->create(['tienda_id' => 'PUNDA50']);
        ComprobanteCola::factory()->create(['tienda_id' => 'OTRA01']);

        $respuesta = $this->comoAdmin()->getJson(self::RUTA.'?tienda_id=OTRA01')->assertOk();

        $respuesta->assertJsonCount(1, 'data');
        $this->assertSame('OTRA01', $respuesta->json('data.0.tienda_id'));
    }

    public function test_filtra_por_rango_de_fechas(): void
    {
        $vieja = ComprobanteCola::factory()->create();
        $vieja->forceFill(['creado_en' => now()->subDays(10)])->saveQuietly();

        ComprobanteCola::factory()->create();

        $respuesta = $this->comoAdmin()
            ->getJson(self::RUTA.'?desde='.now()->subDay()->toDateString())
            ->assertOk();

        $respuesta->assertJsonCount(1, 'data');
    }
}
