<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TICKET-007 — Nota de crédito, anulación y descarga sobre `comprobantes_cola`.
 *
 * Puerto de `gerencia/emitir_nc.php`, `gerencia/anular_boleta.php` y
 * `gerencia/descargar_comprobante.php` (legacy). Lo que se protege aquí:
 *  - una NC nunca se emite sin vínculo fiscal a un comprobante ACEPTADO;
 *  - una boleta nunca se marca ANULADO en local antes de que la API confirme
 *    la baja fiscal;
 *  - las tres acciones son solo de admin.
 */
class ComprobanteColaAccionesFiscalesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://factura.test';
    private const TOKEN = 'tok_secreto_de_la_api_externa';

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create();
    }

    private function emisor(array $extra = []): FacturacionConfig
    {
        return FacturacionConfig::factory()->global()->create(array_merge([
            'base_url'  => self::BASE_URL,
            'api_token' => self::TOKEN,
        ], $extra));
    }

    private function aceptada(array $extra = []): ComprobanteCola
    {
        return ComprobanteCola::factory()->aceptada()->create(array_merge([
            'serie'       => 'B001',
            'correlativo' => 123,
            'total'       => 118.00,
        ], $extra));
    }

    // ── Nota de crédito ──────────────────────────────────────────────────────

    public function test_emite_nota_de_credito_sobre_boleta_aceptada(): void
    {
        $admin    = $this->admin();
        $original = $this->aceptada();

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$original->id}/nota-credito", [
                'cod_motivo' => '01',
                'des_motivo' => 'Anulacion de la operacion',
            ]);

        $respuesta->assertCreated()->assertJson(['ok' => true]);

        $nc = ComprobanteCola::query()->where('tipo_comprobante', ComprobanteCola::TIPO_NOTA_CREDITO)->firstOrFail();

        $this->assertSame($original->id, $nc->comprobante_afectado_id);
        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $nc->estado);
        $this->assertSame('B001-00000123', $nc->payload['num_doc_afectado']);
        $this->assertSame('03', $nc->payload['tipo_doc_afectado']);
        $this->assertEquals(118.00, $nc->payload['total']);
        $this->assertSame('01', $nc->payload['cod_motivo']);
    }

    public function test_no_se_puede_emitir_nc_sobre_comprobante_pendiente(): void
    {
        $admin    = $this->admin();
        $pendiente = ComprobanteCola::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$pendiente->id}/nota-credito")
            ->assertStatus(422);

        $this->assertSame(0, ComprobanteCola::where('tipo_comprobante', ComprobanteCola::TIPO_NOTA_CREDITO)->count());
    }

    public function test_no_se_puede_emitir_nc_sobre_comprobante_rechazado(): void
    {
        $admin     = $this->admin();
        $rechazado = ComprobanteCola::factory()->create(['estado' => ComprobanteCola::ESTADO_RECHAZADO]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$rechazado->id}/nota-credito")
            ->assertStatus(422);
    }

    public function test_pedir_nc_dos_veces_no_duplica_la_fila(): void
    {
        $admin    = $this->admin();
        $original = $this->aceptada();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$original->id}/nota-credito")
            ->assertCreated();

        $segunda = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$original->id}/nota-credito");

        $segunda->assertOk()->assertJson(['ok' => true, 'ya_existe' => true]);
        $this->assertSame(1, ComprobanteCola::where('tipo_comprobante', ComprobanteCola::TIPO_NOTA_CREDITO)->count());
    }

    public function test_solo_admin_puede_emitir_nc(): void
    {
        $vendedor = Usuario::factory()->vendedor()->create();
        $original = $this->aceptada();

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$original->id}/nota-credito")
            ->assertForbidden();
    }

    // ── Anulación ────────────────────────────────────────────────────────────

    public function test_anular_boleta_confirma_contra_la_api_antes_de_mutar_el_estado_local(): void
    {
        $admin  = $this->admin();
        $this->emisor();
        $cola   = $this->aceptada(['api_doc_id' => 77]);

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/77/anular' => Http::response([
                'success' => true,
                'data'    => ['summary_id' => 'RA-1', 'ticket' => 'TCK-9'],
            ]),
        ]);

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/anular", ['motivo' => 'Cliente devolvió el equipo']);

        $respuesta->assertOk()->assertJson(['ok' => true, 'summary_id' => 'RA-1', 'ticket' => 'TCK-9']);

        $cola->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ANULADO, $cola->estado);
        $this->assertSame('BAJA ticket=TCK-9', $cola->ultimo_error);
        $this->assertSame($admin->id, $cola->anulado_por_usuario_id);
        $this->assertSame('Cliente devolvió el equipo', $cola->anulado_motivo);
        $this->assertNotNull($cola->anulado_en);

        Http::assertSentCount(1);
    }

    public function test_si_la_api_rechaza_la_baja_el_estado_local_no_cambia(): void
    {
        $admin = $this->admin();
        $this->emisor();
        $cola  = $this->aceptada(['api_doc_id' => 77]);

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/77/anular' => Http::response(['success' => false, 'message' => 'CPE ya tiene una baja en curso'], 400),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/anular")
            ->assertStatus(502);

        $cola->refresh();
        $this->assertSame(ComprobanteCola::ESTADO_ACEPTADO, $cola->estado);
        $this->assertNull($cola->anulado_en);
    }

    public function test_no_se_puede_anular_una_factura(): void
    {
        $admin = $this->admin();
        $cola  = $this->aceptada(['tipo_comprobante' => ComprobanteCola::TIPO_FACTURA, 'api_doc_id' => 77]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/anular")
            ->assertStatus(422);
    }

    public function test_no_se_puede_anular_una_boleta_no_aceptada(): void
    {
        $admin = $this->admin();
        $cola  = ComprobanteCola::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/anular")
            ->assertStatus(422);
    }

    public function test_solo_admin_puede_anular(): void
    {
        $vendedor = Usuario::factory()->vendedor()->create();
        $cola     = $this->aceptada(['api_doc_id' => 77]);

        $this->actingAs($vendedor, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/anular")
            ->assertForbidden();
    }

    // ── Descarga ─────────────────────────────────────────────────────────────

    public function test_descarga_pdf_pide_generate_pdf_antes_de_bajar_el_archivo(): void
    {
        $admin = $this->admin();
        $this->emisor();
        $cola  = $this->aceptada(['api_doc_id' => 77]);

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/77/generate-pdf'  => Http::response(['ok' => true]),
            self::BASE_URL.'/api/v1/boletas/77/download-pdf'  => Http::response('%PDF-1.4 contenido', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/comprobantes-cola/{$cola->id}/descargar/pdf");

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('B001-00000123.pdf', $respuesta->headers->get('Content-Disposition'));

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/generate-pdf'));
    }

    public function test_descarga_xml_no_pide_generate_pdf(): void
    {
        $admin = $this->admin();
        $this->emisor();
        $cola  = $this->aceptada(['api_doc_id' => 77]);

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/77/download-xml' => Http::response('<xml/>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/comprobantes-cola/{$cola->id}/descargar/xml")
            ->assertOk();

        Http::assertSentCount(1);
    }

    public function test_no_se_puede_descargar_un_comprobante_no_aceptado(): void
    {
        $admin = $this->admin();
        $cola  = ComprobanteCola::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/comprobantes-cola/{$cola->id}/descargar/pdf")
            ->assertStatus(404);
    }

    public function test_formato_no_soportado_devuelve_404(): void
    {
        $admin = $this->admin();
        $cola  = $this->aceptada(['api_doc_id' => 77]);

        $this->actingAs($admin, 'sanctum')
            ->get("/api/v1/comprobantes-cola/{$cola->id}/descargar/docx")
            ->assertStatus(404);
    }

    public function test_solo_admin_puede_descargar(): void
    {
        $vendedor = Usuario::factory()->vendedor()->create();
        $cola     = $this->aceptada(['api_doc_id' => 77]);

        $this->actingAs($vendedor, 'sanctum')
            ->get("/api/v1/comprobantes-cola/{$cola->id}/descargar/pdf")
            ->assertForbidden();
    }
}
