<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * "Emitir ahora" (TICKET-005). Paridad de `reportes/ajax_emitir_ahora.php`.
 *
 * La invariante que se protege: emitir ahora NO es un camino paralelo. Encola y
 * drena la misma fila con el mismo procesador del cron, así que un fallo de la API
 * deja el comprobante en la cola —nunca perdido, nunca emitido dos veces—.
 */
class EmitirAhoraTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/comprobantes-cola/emitir-ahora';
    private const BASE_URL = 'https://factura.test';

    private function emisor(array $extra = []): FacturacionConfig
    {
        return FacturacionConfig::factory()->global()->create(array_merge([
            'base_url'  => self::BASE_URL,
            'api_token' => 'tok_secreto',
        ], $extra));
    }

    private function apiFeliz(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/boletas/*/send-sunat' => Http::response(['data' => [
                'numero_completo' => 'B001-00000045',
                'estado_sunat'    => 'ACEPTADO',
            ]]),
            self::BASE_URL.'/api/v1/boletas' => Http::response(['data' => ['id' => 55]], 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function cuerpo(array $extra = []): array
    {
        return array_merge([
            'venta_id'         => 900,
            'tienda_id'        => 'PUNDA50',
            'agente_id'        => 1001,
            'tipo_comprobante' => ComprobanteCola::TIPO_BOLETA,
            'tipo_doc_cliente' => '1',
            'num_doc_cliente'  => '44556677',
            'razon_social'     => 'Cliente Uno',
            'total'            => 118.00,
            'payload'          => ['items' => [
                ['descripcion' => 'Chip Bitel', 'cantidad' => 1, 'precio_unitario' => 118.00],
            ]],
        ], $extra);
    }

    private function comoCajero(): static
    {
        return $this->actingAs($this->vendedorVinculado(), 'sanctum');
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_la_ruta_exige_autenticacion(): void
    {
        $this->postJson(self::RUTA, $this->cuerpo())->assertUnauthorized();
    }

    // ── Camino feliz ─────────────────────────────────────────────────────────

    public function test_encola_y_emite_la_misma_fila_en_una_sola_llamada(): void
    {
        $this->emisor();
        $this->apiFeliz();

        $respuesta = $this->comoCajero()->postJson(self::RUTA, $this->cuerpo());

        $respuesta->assertOk()->assertJson([
            'ok'              => true,
            'estado'          => ComprobanteCola::ESTADO_ACEPTADO,
            'api_doc_id'      => 55,
            'serie'           => 'B001',
            'correlativo'     => 45,
            'numero_completo' => 'B001-00000045',
        ]);

        $this->assertSame(1, ComprobanteCola::query()->count());
        $this->assertSame('venta:900:BOLETA', ComprobanteCola::first()->clave_idempotencia);
        Http::assertSentCount(2);
    }

    public function test_emitir_dos_veces_la_misma_venta_no_duplica_ni_reemite(): void
    {
        $this->emisor();
        $this->apiFeliz();

        $this->comoCajero()->postJson(self::RUTA, $this->cuerpo())->assertOk();
        $segunda = $this->comoCajero()->postJson(self::RUTA, $this->cuerpo());

        $segunda->assertOk()->assertJson(['ok' => true, 'ya_emitido' => true]);
        // La segunda llamada no debe tocar la API: la fila ya estaba ACEPTADA.
        $this->assertSame(1, ComprobanteCola::query()->count());
        Http::assertSentCount(2);
    }

    public function test_puede_emitir_una_fila_ya_encolada_por_cola_id(): void
    {
        $this->emisor();
        $this->apiFeliz();
        $fila = ComprobanteCola::factory()->create();

        $this->comoCajero()->postJson(self::RUTA, ['cola_id' => $fila->getKey()])
            ->assertOk()
            ->assertJson(['ok' => true, 'cola_id' => $fila->getKey()]);

        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $fila->refresh()->estado);
    }

    // ── Fallos: la fila nunca se pierde ──────────────────────────────────────

    public function test_si_la_api_falla_de_forma_transitoria_la_fila_queda_en_la_cola(): void
    {
        $this->emisor();
        Http::fake(['*' => Http::response(['message' => 'Gateway timeout'], 504)]);

        $respuesta = $this->comoCajero()->postJson(self::RUTA, $this->cuerpo());

        $respuesta->assertOk()->assertJson(['ok' => false, 'encolado' => true, 'estado' => ComprobanteCola::ESTADO_ERROR]);

        $fila = ComprobanteCola::first();
        $this->assertSame(1, $fila->intentos);
        $this->assertNotNull($fila->proximo_intento_at, 'El cron debe poder recogerla más tarde.');
    }

    public function test_un_rechazo_definitivo_no_se_marca_como_encolado(): void
    {
        $this->emisor();
        Http::fake(['*' => Http::response(['message' => 'Cliente sin RUC válido'], 422)]);

        $this->comoCajero()->postJson(self::RUTA, $this->cuerpo())
            ->assertOk()
            ->assertJson([
                'ok'     => false,
                'estado' => ComprobanteCola::ESTADO_RECHAZADO,
                'msg'    => 'Cliente sin RUC válido',
            ])
            ->assertJsonMissingPath('encolado');
    }

    public function test_sin_emisor_configurado_queda_encolada_en_pendiente(): void
    {
        Http::fake();

        $this->comoCajero()->postJson(self::RUTA, $this->cuerpo())
            ->assertOk()
            ->assertJson(['ok' => false, 'encolado' => true, 'estado' => ComprobanteCola::ESTADO_PENDIENTE]);

        Http::assertNothingSent();
        $this->assertSame(1, ComprobanteCola::query()->count());
    }

    // ── Validación ───────────────────────────────────────────────────────────

    public function test_sin_cola_id_exige_tipo_de_comprobante_y_payload(): void
    {
        $this->comoCajero()->postJson(self::RUTA, ['venta_id' => 900])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tipo_comprobante', 'payload']);
    }

    public function test_un_cola_id_inexistente_es_un_error_de_validacion(): void
    {
        $this->comoCajero()->postJson(self::RUTA, ['cola_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cola_id']);
    }

    // ── DECISIÓN-001: la ruta Greenter está apagada ──────────────────────────

    public function test_la_emision_por_greenter_responde_410_mientras_la_bandera_este_apagada(): void
    {
        config(['facturacion.greenter_activo' => false]);

        $this->actingAs(Usuario::factory()->admin()->create(), 'sanctum')
            ->postJson('/api/v1/comprobantes', ['venta_id' => 1, 'tipo_comprobante' => '03', 'serie' => 'B001'])
            ->assertStatus(410);
    }
}
