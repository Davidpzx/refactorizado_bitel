<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use App\Models\Usuario;
use App\Services\Facturacion\CpeLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TICKET-008 — Link público CPE (HMAC) + descarga multi-formato.
 *
 * Port de `reportes/cpe_publico.php` + `reportes/imprimir_comprobante.php` +
 * `reportes/ajax_link_cpe.php` (legacy). Lo que se protege aquí:
 *  - la vista pública NUNCA exige sesión, pero SIEMPRE exige firma válida;
 *  - una firma inválida o expirada se rechaza con 403, nunca con datos parciales;
 *  - solo un usuario autenticado puede pedir que se genere el link.
 */
class ComprobanteColaPublicoTest extends TestCase
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

    private function firmaValida(int $colaId): array
    {
        $links = app(CpeLinkService::class);
        $exp   = now()->addHours(48)->getTimestamp();

        return ['exp' => $exp, 'firma' => $links->token($colaId, $exp)];
    }

    // ── Vista pública (show) ────────────────────────────────────────────────

    public function test_firma_valida_muestra_el_comprobante_sin_sesion(): void
    {
        $cola = $this->aceptada();
        $qs   = $this->firmaValida($cola->id);

        $respuesta = $this->getJson("/api/v1/cpe/{$cola->id}?exp={$qs['exp']}&firma={$qs['firma']}");

        $respuesta->assertOk()->assertJson([
            'id'              => $cola->id,
            'estado'          => ComprobanteCola::ESTADO_ACEPTADO,
            'numero_completo' => 'B001-00000123',
            'puede_descargar' => true,
        ]);
    }

    public function test_firma_invalida_se_rechaza_con_403(): void
    {
        $cola = $this->aceptada();
        $exp  = now()->addHours(48)->getTimestamp();

        $this->getJson("/api/v1/cpe/{$cola->id}?exp={$exp}&firma=firma-inventada")
            ->assertStatus(403);
    }

    public function test_firma_expirada_se_rechaza_con_403(): void
    {
        $cola = $this->aceptada();
        $links = app(CpeLinkService::class);
        $expVencido = now()->subHour()->getTimestamp();
        $firma = $links->token($cola->id, $expVencido);

        $this->getJson("/api/v1/cpe/{$cola->id}?exp={$expVencido}&firma={$firma}")
            ->assertStatus(403);
    }

    public function test_firma_de_otro_comprobante_no_sirve_para_este(): void
    {
        $cola  = $this->aceptada();
        $otro  = $this->aceptada();
        $qsOtro = $this->firmaValida($otro->id);

        $this->getJson("/api/v1/cpe/{$cola->id}?exp={$qsOtro['exp']}&firma={$qsOtro['firma']}")
            ->assertStatus(403);
    }

    public function test_sin_parametros_de_firma_devuelve_422(): void
    {
        $cola = $this->aceptada();

        $this->getJson("/api/v1/cpe/{$cola->id}")->assertStatus(422);
    }

    // ── Descarga pública ─────────────────────────────────────────────────────

    public function test_descarga_pdf_publica_con_firma_valida_pide_el_formato_indicado(): void
    {
        $this->emisor();
        $cola = $this->aceptada(['api_doc_id' => 77]);
        $qs   = $this->firmaValida($cola->id);

        Http::fake([
            self::BASE_URL.'/api/v1/boletas/77/generate-pdf*' => Http::response(['ok' => true]),
            self::BASE_URL.'/api/v1/boletas/77/download-pdf'  => Http::response('%PDF-1.4 contenido', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $respuesta = $this->get("/api/v1/cpe/{$cola->id}/descargar/pdf?exp={$qs['exp']}&firma={$qs['firma']}&formato=80mm");

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', $respuesta->headers->get('Content-Disposition'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generate-pdf') && str_contains($request->url(), 'format=80mm'));
    }

    public function test_descarga_publica_con_firma_invalida_se_rechaza(): void
    {
        $cola = $this->aceptada(['api_doc_id' => 77]);
        $exp  = now()->addHours(48)->getTimestamp();

        $this->get("/api/v1/cpe/{$cola->id}/descargar/pdf?exp={$exp}&firma=x")
            ->assertStatus(403);
    }

    public function test_descarga_publica_de_comprobante_pendiente_devuelve_404(): void
    {
        $cola = ComprobanteCola::factory()->create();
        $qs   = $this->firmaValida($cola->id);

        $this->get("/api/v1/cpe/{$cola->id}/descargar/pdf?exp={$qs['exp']}&firma={$qs['firma']}")
            ->assertStatus(404);
    }

    public function test_formato_no_soportado_en_descarga_publica_devuelve_404(): void
    {
        $cola = $this->aceptada(['api_doc_id' => 77]);
        $qs   = $this->firmaValida($cola->id);

        $this->get("/api/v1/cpe/{$cola->id}/descargar/docx?exp={$qs['exp']}&firma={$qs['firma']}")
            ->assertStatus(404);
    }

    // ── Generación autenticada del link ──────────────────────────────────────

    public function test_usuario_autenticado_genera_el_link_publico(): void
    {
        $admin = $this->admin();
        $cola  = $this->aceptada();

        $respuesta = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/link");

        $respuesta->assertOk()->assertJsonStructure(['url', 'exp', 'whatsapp_url', 'cola_id']);

        $url = $respuesta->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        $links = app(CpeLinkService::class);
        $this->assertTrue($links->verificar($cola->id, (int) $params['exp'], (string) $params['firma']));
    }

    public function test_no_se_puede_generar_link_de_un_comprobante_pendiente(): void
    {
        $admin = $this->admin();
        $cola  = ComprobanteCola::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/comprobantes-cola/{$cola->id}/link")
            ->assertStatus(422);
    }

    public function test_generar_link_requiere_sesion(): void
    {
        $cola = $this->aceptada();

        $this->postJson("/api/v1/comprobantes-cola/{$cola->id}/link")
            ->assertUnauthorized();
    }
}
