<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy `comprobantes_cola` (`config/facturacion_cola.php` +
 * `cron/procesar_cola_comprobantes.php`): encolado idempotente, snapshot fiscal
 * congelado y backoff exponencial.
 */
class ComprobanteColaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(float $total = 118.00): array
    {
        return [
            'items' => [['descripcion' => 'Chip Bitel', 'cantidad' => 1, 'precio_unitario' => $total - 18]],
            'igv'   => 18.00,
            'total' => $total,
        ];
    }

    private function atributos(array $extra = []): array
    {
        return array_merge([
            'venta_id'         => 500,
            'tienda_id'        => 'PUNDA50',
            'agente_id'        => 1001,
            'tipo_comprobante' => ComprobanteCola::TIPO_BOLETA,
            'num_doc_cliente'  => '44556677',
            'razon_social'     => 'Cliente Uno',
            'total'            => 118.00,
            'payload'          => $this->payload(),
        ], $extra);
    }

    // ── Encolado idempotente ────────────────────────────────────────────────

    public function test_encolar_dos_veces_la_misma_venta_no_duplica_fila(): void
    {
        $primera = ComprobanteCola::encolar($this->atributos());
        $segunda = ComprobanteCola::encolar($this->atributos());

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, ComprobanteCola::query()->count());
        $this->assertSame('venta:500:BOLETA', $primera->clave_idempotencia);
    }

    public function test_encolar_la_misma_venta_con_otro_tipo_si_crea_una_fila(): void
    {
        ComprobanteCola::encolar($this->atributos());
        ComprobanteCola::encolar($this->atributos(['tipo_comprobante' => ComprobanteCola::TIPO_NOTA_CREDITO]));

        $this->assertSame(2, ComprobanteCola::query()->count());
    }

    public function test_encolar_sin_origen_no_deduplica(): void
    {
        $atributos = $this->atributos(['venta_id' => null]);

        ComprobanteCola::encolar($atributos);
        ComprobanteCola::encolar($atributos);

        $this->assertSame(2, ComprobanteCola::query()->count());
        $this->assertNull(ComprobanteCola::query()->first()->clave_idempotencia);
    }

    public function test_el_reencolado_conserva_el_payload_de_la_primera_vez(): void
    {
        $original = ComprobanteCola::encolar($this->atributos());

        // El precio cambió entre el primer y el segundo intento de encolar.
        $reencolada = ComprobanteCola::encolar($this->atributos([
            'total'   => 999.00,
            'payload' => $this->payload(999.00),
        ]));

        $this->assertSame($original->id, $reencolada->id);
        $this->assertEquals(118.00, $reencolada->payload['total']);
        $this->assertSame('118.00', $reencolada->total);
    }

    /** El UNIQUE es la garantía dura, no el `first()` de `firstOrCreate`. */
    public function test_la_bd_rechaza_dos_filas_con_la_misma_clave_de_idempotencia(): void
    {
        ComprobanteCola::encolar($this->atributos());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ComprobanteCola::query()->create($this->atributos(['clave_idempotencia' => 'venta:500:BOLETA']));
    }

    // ── Snapshot fiscal congelado ───────────────────────────────────────────

    public function test_el_snapshot_fiscal_es_inmutable_una_vez_encolado(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('snapshot fiscal');

        $cola->update(['payload' => $this->payload(999.00)]);
    }

    public function test_los_reintentos_no_recalculan_el_payload(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());

        $jsonEncolado = DB::table('comprobantes_cola')->where('id', $cola->id)->value('payload');

        $cola->reclamar();
        $cola->registrarError('API caída');
        $cola->refresh();
        $cola->reclamar();
        $cola->registrarError('API caída otra vez');

        // Byte a byte: el snapshot que se envía en el reintento es el que se congeló.
        $this->assertSame(
            $jsonEncolado,
            DB::table('comprobantes_cola')->where('id', $cola->id)->value('payload')
        );
        $this->assertSame(2, $cola->fresh()->intentos);
    }

    // ── Backoff y máquina de estados ────────────────────────────────────────

    public function test_backoff_exponencial_con_techo_de_60_minutos(): void
    {
        $this->assertSame(2, ComprobanteCola::backoffMinutos(1));
        $this->assertSame(4, ComprobanteCola::backoffMinutos(2));
        $this->assertSame(32, ComprobanteCola::backoffMinutos(5));
        $this->assertSame(60, ComprobanteCola::backoffMinutos(6));
        $this->assertSame(60, ComprobanteCola::backoffMinutos(20));
    }

    public function test_registrar_error_reintentable_reprograma_con_backoff(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());

        $cola->registrarError('502 Bad Gateway');

        $this->assertSame(ComprobanteCola::ESTADO_ERROR, $cola->estado);
        $this->assertSame(1, $cola->intentos);
        $this->assertSame('502 Bad Gateway', $cola->ultimo_error);
        $this->assertEqualsWithDelta(2, now()->diffInMinutes($cola->proximo_intento_at), 0.1);
    }

    public function test_registrar_error_no_reintentable_rechaza_definitivamente(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());

        $cola->registrarError('RUC del cliente inválido', reintentable: false);

        $this->assertSame(ComprobanteCola::ESTADO_RECHAZADO, $cola->estado);
        $this->assertSame(0, $cola->intentos);
        $this->assertNull($cola->proximo_intento_at);
    }

    public function test_reclamar_es_un_lock_optimista(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());

        $workerA = $cola->fresh();
        $workerB = $cola->fresh();

        $this->assertTrue($workerA->reclamar());
        $this->assertFalse($workerB->reclamar(), 'La fila ya la tomó otro worker.');
        $this->assertSame(ComprobanteCola::ESTADO_ENVIADO, $cola->fresh()->estado);
    }

    public function test_marcar_aceptado_guarda_el_api_doc_id_y_limpia_el_error(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());
        $cola->registrarError('timeout');

        $cola->marcarAceptado([
            'api_doc_id' => 4321,
            'cdr_estado' => 'ACEPTADO',
            'xml_path'   => 'sunat/xml/B001-1.xml',
            'ignorado'   => 'no está en el whitelist',
        ]);

        $cola->refresh();
        $this->assertTrue($cola->estaAceptado());
        $this->assertSame(4321, $cola->api_doc_id);
        $this->assertSame('sunat/xml/B001-1.xml', $cola->xml_path);
        $this->assertNull($cola->ultimo_error);
        $this->assertNull($cola->proximo_intento_at);
    }

    public function test_scope_pendientes_replica_la_seleccion_del_cron_legacy(): void
    {
        $lista       = ComprobanteCola::factory()->create(['ticket_id' => 1]);
        $reintentoOk = ComprobanteCola::factory()->create([
            'ticket_id'          => 2,
            'estado'             => ComprobanteCola::ESTADO_ERROR,
            'intentos'           => 3,
            'proximo_intento_at' => now()->subMinute(),
        ]);

        ComprobanteCola::factory()->enBackoff()->create(['ticket_id' => 3]);
        ComprobanteCola::factory()->agotada()->create(['ticket_id' => 4]);
        ComprobanteCola::factory()->aceptada()->create(['ticket_id' => 5]);

        $ids = ComprobanteCola::pendientes()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lista->id, $reintentoOk->id], $ids);
    }

    // ── Relaciones y presentación ───────────────────────────────────────────

    public function test_relaciona_venta_reporte_y_config_de_facturacion(): void
    {
        $config = FacturacionConfig::factory()->deTienda('PUNDA50')->create();

        $reporteId = DB::table('reportes')->insertGetId([
            'agente_id' => 1001,
            'tienda_id' => 'PUNDA50',
            'fecha'     => now()->toDateString(),
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'reporte_id'  => $reporteId,
            'vendedor_id' => 1001,
            'tipo_venta'  => 'CHIP',
            'monto_total' => 118.00,
        ]);

        $cola = ComprobanteCola::encolar($this->atributos([
            'venta_id'              => $ventaId,
            'reporte_id'            => $reporteId,
            'facturacion_config_id' => $config->id,
        ]));

        $this->assertSame($ventaId, $cola->venta->id);
        $this->assertSame($reporteId, $cola->reporte->id);
        $this->assertSame($config->id, $cola->facturacionConfig->id);
    }

    public function test_resolver_config_cae_a_la_de_la_tienda_si_la_fila_aun_no_la_fijo(): void
    {
        FacturacionConfig::factory()->global()->create(['ruc' => '20100000001']);
        FacturacionConfig::factory()->deTienda('PUNDA50')->create(['ruc' => '20200000002']);

        $cola = ComprobanteCola::encolar($this->atributos());

        $this->assertNull($cola->facturacion_config_id);
        $this->assertSame('20200000002', $cola->resolverConfig()->ruc);
    }

    public function test_numero_completo_solo_existe_tras_reservar_el_correlativo(): void
    {
        $cola = ComprobanteCola::encolar($this->atributos());
        $this->assertNull($cola->numero_completo);

        $cola->forceFill(['serie' => 'B001', 'correlativo' => 123])->save();

        $this->assertSame('B001-00000123', $cola->numero_completo);
    }

    public function test_la_serie_sale_del_emisor_segun_el_tipo(): void
    {
        $config = FacturacionConfig::factory()->create([
            'serie_boleta'       => 'B002',
            'serie_factura'      => 'F007',
            'serie_nota_credito' => 'FC09',
        ]);

        $boleta = ComprobanteCola::factory()->make();
        $factura = ComprobanteCola::factory()->factura()->make();
        $nota = ComprobanteCola::factory()->notaCredito()->make();

        $this->assertSame('B002', $boleta->serieSegunTipo($config));
        $this->assertSame('F007', $factura->serieSegunTipo($config));
        $this->assertSame('FC09', $nota->serieSegunTipo($config));
    }
}
