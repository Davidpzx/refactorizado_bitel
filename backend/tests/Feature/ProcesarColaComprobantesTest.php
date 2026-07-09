<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Drenador de `comprobantes_cola` (TICKET-005). Paridad de
 * `cron/procesar_cola_comprobantes.php` del legacy.
 *
 * Lo que se protege aquí: que un fallo transitorio NUNCA pierda un comprobante, y
 * que un rechazo definitivo NUNCA se reintente contra SUNAT.
 */
class ProcesarColaComprobantesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://factura.test';
    private const TOKEN = 'tok_secreto_de_la_api_externa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
    }

    private function emisor(array $extra = []): FacturacionConfig
    {
        return FacturacionConfig::factory()->global()->create(array_merge([
            'base_url'  => self::BASE_URL,
            'api_token' => self::TOKEN,
        ], $extra));
    }

    private function fila(array $extra = []): ComprobanteCola
    {
        return ComprobanteCola::factory()->create($extra);
    }

    /** Respuesta de `{id}/send-sunat` cuando SUNAT acepta. */
    private static function cuerpoAceptado(): array
    {
        return [
            'data' => [
                'id'              => 77,
                'numero_completo' => 'B001-00000123',
                'estado_sunat'    => 'ACEPTADO',
                'sunat_ticket'    => 'TCK-9',
                'codigo_hash'     => 'aG4zaA==',
                'xml_path'        => 'xml/B001-00000123.xml',
                'pdf_path'        => 'pdf/B001-00000123.pdf',
                'respuesta_sunat' => ['description' => 'La Boleta B001-00000123 ha sido aceptada'],
            ],
        ];
    }

    private function apiFeliz(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v1/boletas/*/send-sunat' => Http::response(self::cuerpoAceptado()),
            self::BASE_URL.'/api/v1/boletas'              => Http::response(['data' => ['id' => 77]], 201),
        ]);
    }

    // ── Emisión feliz ────────────────────────────────────────────────────────

    public function test_emision_feliz_recorre_los_dos_pasos_y_deja_la_fila_aceptada(): void
    {
        $emisor = $this->emisor();
        $fila   = $this->fila();
        $this->apiFeliz();

        // `numero=` solo lo imprime la rama de aceptación del comando.
        $this->artisan('facturacion:procesar-cola')
            ->expectsOutputToContain('numero=B001-00000123')
            ->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $fila->estado);
        $this->assertSame('B001', $fila->serie);
        $this->assertSame(123, $fila->correlativo);
        $this->assertSame('B001-00000123', $fila->numero_completo);
        $this->assertSame(77, $fila->api_doc_id);
        $this->assertSame('TCK-9', $fila->sunat_ticket);
        $this->assertSame('ACEPTADO', $fila->cdr_estado);
        $this->assertSame('aG4zaA==', $fila->cdr_hash);
        $this->assertSame($emisor->getKey(), $fila->facturacion_config_id);
        $this->assertNull($fila->ultimo_error);
        $this->assertNull($fila->proximo_intento_at);
        $this->assertSame(0, $fila->intentos);

        Http::assertSentCount(2);
    }

    public function test_el_paso_1_manda_el_token_del_emisor_y_la_serie_de_la_config_no_la_del_payload(): void
    {
        $this->emisor(['serie_boleta' => 'B777', 'company_id' => 9, 'branch_id' => 4]);
        // El payload encolado trae una serie vieja: debe perder contra la del emisor.
        $this->fila(['payload' => ['serie' => 'B001', 'company_id' => 1, 'items' => [
            ['descripcion' => 'Chip', 'cantidad' => 1, 'precio_unitario' => 118.00],
        ]]]);
        $this->apiFeliz();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        Http::assertSent(function ($request) {
            if (!str_ends_with($request->url(), '/api/v1/boletas')) {
                return false;
            }

            $this->assertSame(['Bearer '.self::TOKEN], $request->header('Authorization'));
            $this->assertSame('B777', $request['serie']);
            $this->assertSame(9, $request['company_id']);
            $this->assertSame(4, $request['branch_id']);
            // 118.00 con IGV → 100.00 sin IGV.
            $this->assertEquals(100.0, $request['detalles'][0]['mto_valor_unitario']);

            return true;
        });
    }

    // ── Error retryable: la fila vuelve a la cola con backoff ────────────────

    public function test_error_5xx_deja_la_fila_en_error_con_backoff_exponencial(): void
    {
        $this->emisor();
        $fila = $this->fila();
        Http::fake(['*' => Http::response(['message' => 'Base de datos caída'], 500)]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ERROR, $fila->estado);
        $this->assertSame(1, $fila->intentos);
        $this->assertSame('Base de datos caída', $fila->ultimo_error);
        // 2^1 = 2 minutos. La columna es `timestamp`: sin microsegundos.
        $this->assertSame(
            now()->addMinutes(2)->format('Y-m-d H:i:s'),
            $fila->proximo_intento_at->format('Y-m-d H:i:s'),
        );

        // Solo el paso 1: nunca se llegó a `send-sunat`.
        Http::assertSentCount(1);
    }

    public function test_una_fila_en_backoff_no_se_toma_hasta_que_vence(): void
    {
        $this->emisor();
        $fila = ComprobanteCola::factory()->enBackoff()->create();
        Http::fake();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(ComprobanteCola::ESTADO_ERROR, $fila->refresh()->estado);
        $this->assertSame(2, $fila->intentos);
    }

    public function test_una_fila_con_backoff_vencido_se_reintenta_sin_gastar_un_intento_nuevo(): void
    {
        $this->emisor();
        $fila = ComprobanteCola::factory()->enBackoff()->create(['proximo_intento_at' => now()->subMinute()]);
        $this->apiFeliz();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $fila->estado);
        $this->assertSame(2, $fila->intentos, 'Un intento exitoso no incrementa el contador.');
    }

    public function test_una_fila_que_agoto_sus_intentos_no_se_vuelve_a_tomar(): void
    {
        $this->emisor();
        ComprobanteCola::factory()->agotada()->create();
        Http::fake();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        Http::assertNothingSent();
    }

    // ── Error no-retryable: rechazo definitivo ───────────────────────────────

    public function test_error_4xx_rechaza_la_fila_sin_reintentar(): void
    {
        $this->emisor();
        $fila = $this->fila();
        Http::fake(['*' => Http::response(['message' => 'La serie B001 no está autorizada'], 422)]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_RECHAZADO, $fila->estado);
        $this->assertSame('La serie B001 no está autorizada', $fila->ultimo_error);
        $this->assertNull($fila->proximo_intento_at, 'Un rechazo definitivo no programa reintento.');
        Http::assertSentCount(1);
    }

    public function test_sunat_rechaza_en_el_paso_2_y_la_fila_queda_rechazada(): void
    {
        $this->emisor();
        $fila = $this->fila();

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/*/send-sunat' => Http::response(['data' => [
                'estado_sunat'    => 'RECHAZADO',
                'respuesta_sunat' => ['description' => 'El RUC del emisor no existe'],
            ]]),
            self::BASE_URL.'/api/v1/boletas' => Http::response(['data' => ['id' => 77]], 201),
        ]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_RECHAZADO, $fila->estado);
        $this->assertSame('El RUC del emisor no existe', $fila->ultimo_error);
        $this->assertSame(77, $fila->api_doc_id);
    }

    public function test_un_estado_sunat_desconocido_se_trata_como_transitorio(): void
    {
        $this->emisor();
        $fila = $this->fila();

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/*/send-sunat' => Http::response(['data' => ['estado_sunat' => 'EN_PROCESO']]),
            self::BASE_URL.'/api/v1/boletas'              => Http::response(['data' => ['id' => 77]], 201),
        ]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $this->assertSame(ComprobanteCola::ESTADO_ERROR, $fila->refresh()->estado);
        $this->assertStringContainsString('EN_PROCESO', (string) $fila->ultimo_error);
    }

    // ── Reanudación del paso 2 ───────────────────────────────────────────────

    public function test_si_falla_el_paso_2_se_guarda_el_api_doc_id_para_reanudar(): void
    {
        $this->emisor();
        $fila = $this->fila();

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/*/send-sunat' => Http::response([], 503),
            self::BASE_URL.'/api/v1/boletas'              => Http::response(['data' => ['id' => 77]], 201),
        ]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ERROR, $fila->estado);
        $this->assertSame(77, $fila->api_doc_id, 'El documento ya existe en la API: el reintento no debe crear otro.');
    }

    public function test_una_fila_con_api_doc_id_reanuda_en_el_paso_2_sin_recrear_el_documento(): void
    {
        $this->emisor();
        ComprobanteCola::factory()->enBackoff(1)->create([
            'api_doc_id'         => 77,
            'proximo_intento_at' => now()->subMinute(),
        ]);
        $this->apiFeliz();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/boletas/77/send-sunat'));
    }

    // ── Emisor no configurado ────────────────────────────────────────────────

    public function test_sin_emisor_configurado_la_fila_vuelve_intacta_a_pendiente(): void
    {
        $fila = $this->fila();
        Http::fake();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $fila->estado);
        $this->assertSame(0, $fila->intentos, 'No hay API que culpar: no se gasta un intento.');
        $this->assertNull($fila->proximo_intento_at);
        Http::assertNothingSent();
    }

    public function test_con_el_emisor_inactivo_tampoco_se_emite(): void
    {
        $this->emisor(['activo' => false]);
        $fila = $this->fila();
        Http::fake();

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $fila->refresh()->estado);
        Http::assertNothingSent();
    }

    // ── Flags del comando ────────────────────────────────────────────────────

    public function test_dry_run_no_muta_nada_ni_llama_a_la_api(): void
    {
        $this->emisor();
        $fila = $this->fila();
        Http::fake();

        $this->artisan('facturacion:procesar-cola', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run, sin cambios')
            ->assertSuccessful();

        Http::assertNothingSent();
        $fila->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $fila->estado);
        $this->assertSame(0, $fila->intentos);
        $this->assertNull($fila->ultimo_error);
    }

    public function test_id_procesa_solo_esa_fila(): void
    {
        $this->emisor();
        $elegida = $this->fila();
        $otra    = $this->fila();
        $this->apiFeliz();

        $this->artisan('facturacion:procesar-cola', ['--id' => $elegida->getKey()])->assertSuccessful();

        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $elegida->refresh()->estado);
        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $otra->refresh()->estado);
        Http::assertSentCount(2);
    }

    public function test_limit_acota_cuantas_filas_se_drenan_por_corrida(): void
    {
        $this->emisor();
        ComprobanteCola::factory()->count(3)->create();
        $this->apiFeliz();

        $this->artisan('facturacion:procesar-cola', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(2, ComprobanteCola::where('estado', ComprobanteCola::ESTADO_ACEPTADO)->count());
        $this->assertSame(1, ComprobanteCola::where('estado', ComprobanteCola::ESTADO_PENDIENTE)->count());
        Http::assertSentCount(4);
    }

    public function test_sin_filas_candidatas_el_comando_no_falla(): void
    {
        $this->emisor();
        Http::fake();

        $this->artisan('facturacion:procesar-cola')
            ->expectsOutputToContain('Sin comprobantes pendientes')
            ->assertSuccessful();
    }

    // ── Secretos ─────────────────────────────────────────────────────────────

    public function test_los_logs_del_fallo_no_contienen_el_token_de_la_api(): void
    {
        $lineas = [];
        Event::listen(function (MessageLogged $log) use (&$lineas): void {
            $lineas[] = $log->message.' '.json_encode($log->context);
        });

        $this->emisor();
        $this->fila();
        Http::fake(['*' => Http::response(['message' => 'Base de datos caída'], 500)]);

        $this->artisan('facturacion:procesar-cola')->assertSuccessful();

        $this->assertNotEmpty($lineas, 'El fallo de la API debe dejar rastro en los logs.');

        foreach ($lineas as $linea) {
            $this->assertStringNotContainsString(self::TOKEN, $linea);
            $this->assertStringNotContainsString('Bearer', $linea);
        }
    }
}
