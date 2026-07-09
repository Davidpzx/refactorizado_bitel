<?php

namespace Tests\Feature;

use App\Models\FacturacionConfig;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Port de `gerencia/ajax_sync_logo_facturacion.php`: reenvía el logo del
 * Perfil de Empresa a la company de la API externa de facturación.
 */
class SincronizarLogoFacturacionTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/configuracion/sync-logo-facturacion';
    private const RUC = '20123456789';

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create();
    }

    private function config(): FacturacionConfig
    {
        return FacturacionConfig::factory()->global()->create([
            'base_url'   => 'https://facturacion.example.test',
            'api_token'  => 'tok_de_la_api_externa',
            'ruc'        => self::RUC,
            'company_id' => 1,
        ]);
    }

    private function asegurarTablaConfiguracionEmpresa(): void
    {
        if (Schema::hasTable('configuracion_empresa')) {
            return;
        }

        Schema::create('configuracion_empresa', function ($table) {
            $table->increments('id');
            $table->string('razon_social', 200)->default('');
            $table->longText('logo_base64')->nullable();
        });
    }

    private function guardarLogo(string $dataUrl): void
    {
        $this->asegurarTablaConfiguracionEmpresa();

        DB::table('configuracion_empresa')->upsert(
            ['id' => 1, 'logo_base64' => $dataUrl],
            ['id'],
            ['logo_base64'],
        );
    }

    /** Un PNG 1x1 transparente válido, en base64. */
    private function logoPngDataUrl(): string
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );

        return 'data:image/png;base64,'.base64_encode($png);
    }

    private function fakeApi(mixed $fallo = null): void
    {
        Http::fake(function (ClientRequest $request) use ($fallo) {
            $url = $request->url();

            if (str_ends_with($url, '/api/v1/companies/1') && $request->method() === 'GET') {
                return Http::response(['data' => ['company' => [
                    'ruc'          => self::RUC,
                    'razon_social' => 'KYRO SAC',
                    'usuario_sol'  => 'MODDATOS',
                ]]]);
            }

            if (str_ends_with($url, '/api/v1/companies/1') && $request->method() === 'POST') {
                return $fallo ?? Http::response(['ok' => true]);
            }

            return Http::response(['message' => 'ruta no fingida'], 404);
        });
    }

    public function test_sincroniza_el_logo_actual_con_la_api_via_multipart(): void
    {
        $this->config();
        $this->guardarLogo($this->logoPngDataUrl());
        $this->fakeApi();

        $respuesta = $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'moddatos123']);

        $respuesta->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function (ClientRequest $request) {
            if (! str_ends_with($request->url(), '/api/v1/companies/1') || $request->method() !== 'POST') {
                return false;
            }

            $nombresAdjuntos = array_map(
                static fn (array $parte) => $parte['name'] ?? null,
                $request->data(),
            );

            return in_array('logo_path', $nombresAdjuntos, true);
        });
    }

    public function test_conserva_el_usuario_sol_existente_de_la_api(): void
    {
        $this->config();
        $this->guardarLogo($this->logoPngDataUrl());
        $this->fakeApi();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'nueva-clave'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/companies/1')) {
                return false;
            }

            $campos = [];
            foreach ($request->data() as $parte) {
                if (is_array($parte) && isset($parte['name']) && ! isset($parte['filename'])) {
                    $campos[$parte['name']] = $parte['contents'];
                }
            }

            return ($campos['usuario_sol'] ?? null) === 'MODDATOS'
                && ($campos['clave_sol'] ?? null) === 'nueva-clave';
        });
    }

    public function test_sin_logo_guardado_sincroniza_sin_adjuntar_archivo(): void
    {
        $this->config();
        $this->asegurarTablaConfiguracionEmpresa();
        $this->fakeApi();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'moddatos123'])
            ->assertOk();

        Http::assertSent(function (ClientRequest $request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/companies/1')) {
                return false;
            }

            $nombresAdjuntos = array_map(
                static fn (array $parte) => $parte['name'] ?? null,
                $request->data(),
            );

            return ! in_array('logo_path', $nombresAdjuntos, true);
        });
    }

    public function test_sin_config_registrada_responde_400(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'x'])
            ->assertStatus(400);

        Http::assertNothingSent();
    }

    public function test_si_la_api_rechaza_devuelve_sus_errores(): void
    {
        $this->config();
        $this->guardarLogo($this->logoPngDataUrl());
        $this->fakeApi(fallo: Http::response(['errors' => ['ruc' => ['El RUC no es válido.']]], 422));

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'moddatos123'])
            ->assertStatus(400)
            ->assertJsonFragment(['ok' => false]);
    }

    public function test_un_usuario_de_tienda_no_puede_sincronizar(): void
    {
        $this->config();
        $this->fakeApi();

        $this->actingAs(Usuario::factory()->vendedor('PUNDA50')->create(), 'sanctum')
            ->postJson(self::RUTA, ['clave_sol' => 'x'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_clave_sol_es_obligatoria(): void
    {
        $this->config();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, [])
            ->assertUnprocessable();
    }
}
