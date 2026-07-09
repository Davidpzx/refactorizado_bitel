<?php

namespace Tests\Feature;

use App\Models\FacturacionConfig;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Port de `gerencia/ajax_configurar_sunat.php`: subida del certificado digital,
 * conversión PFX→PEM y activación de producción contra la API externa (fake).
 */
class ConfigurarSunatTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/facturacion-config/configure-sunat';
    private const PASSWORD = 'secreto123';
    private const RUC = '20123456789';
    private const BASE_URL = 'https://facturacion.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->borrarTemporalesDeCertificados();
    }

    protected function tearDown(): void
    {
        $this->borrarTemporalesDeCertificados();

        parent::tearDown();
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    private function borrarTemporalesDeCertificados(): void
    {
        foreach (glob(sys_get_temp_dir().'/kyro_cert_*') ?: [] as $ruta) {
            @unlink($ruta);
        }
    }

    /** @return list<string> */
    private function temporalesVivos(): array
    {
        return glob(sys_get_temp_dir().'/kyro_cert_*') ?: [];
    }

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create();
    }

    private function config(array $extra = []): FacturacionConfig
    {
        return FacturacionConfig::factory()->global()->create(array_merge([
            'base_url'   => self::BASE_URL,
            'api_token'  => 'tok_de_la_api_externa',
            'ruc'        => self::RUC,
            'company_id' => 1,
            'modo'       => 'beta',
            'activo'     => false,
        ], $extra));
    }

    /** Copia el fixture a un temporal para no arriesgar el original. */
    private function certificado(string $fixture, ?string $nombreCliente = null): UploadedFile
    {
        $origen = __DIR__.'/../Fixtures/certificados/'.$fixture;
        $destino = tempnam(sys_get_temp_dir(), 'up_cert_').'_'.$fixture;
        copy($origen, $destino);

        return new UploadedFile($destino, $nombreCliente ?? $fixture, null, null, true);
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'certificado'          => $this->certificado('certificado_moderno.pfx'),
            'certificado_password' => self::PASSWORD,
            'usuario_sol'          => 'MODDATOS',
            'clave_sol'            => 'moddatos123',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private static function empresaApi(): array
    {
        return [
            'ruc'                 => self::RUC,
            'razon_social'        => 'KYRO SAC',
            'nombre_comercial'    => 'KYRO',
            'direccion'           => 'Av. Siempre Viva 742',
            'ubigeo'              => '150101',
            'distrito'            => 'Lima',
            'provincia'           => 'Lima',
            'departamento'        => 'Lima',
            'telefono'            => '999999999',
            'email'               => 'facturacion@kyro.test',
            'web'                 => 'https://kyro.test',
            'endpoint_produccion' => 'https://e-factura.sunat.gob.pe',
        ];
    }

    /** @var array<string, mixed> */
    private array $fallosApi = [];

    private bool $apiFingida = false;

    /**
     * API externa feliz. `$fallos` permite romper un paso concreto:
     * `['configure-sunat' => Http::response([...], 422)]` o una Closure que lance.
     *
     * `Http::fake()` con closure ACUMULA callbacks en vez de reemplazarlos (ver
     * `Factory::fake()` del framework), así que si esta función se llama más de
     * una vez en el mismo test, el primer closure registrado seguiría
     * respondiendo antes que el segundo. Por eso el closure se registra una
     * sola vez y lee `$fallosApi` (mutable) en cada llamada a `fakeApi()`.
     */
    private function fakeApi(array $fallos = []): void
    {
        $this->fallosApi = $fallos;

        if ($this->apiFingida) {
            return;
        }

        $this->apiFingida = true;

        Http::fake(function (ClientRequest $request) {
            $url = $request->url();

            $paso = match (true) {
                str_ends_with($url, '/api/v1/setup/configure-sunat') => 'configure-sunat',
                str_ends_with($url, '/toggle-production')            => 'toggle-production',
                str_ends_with($url, '/api/v1/companies/1')           => $request->method() === 'GET' ? 'get-company' : 'update-company',
                default                                              => 'desconocido',
            };

            if (isset($this->fallosApi[$paso])) {
                $fallo = $this->fallosApi[$paso];

                return $fallo instanceof \Closure ? $fallo() : $fallo;
            }

            return match ($paso) {
                'get-company' => Http::response(['data' => ['company' => self::empresaApi()]]),
                'desconocido' => Http::response(['message' => 'ruta no fingida'], 404),
                default       => Http::response(['ok' => true]),
            };
        });
    }

    /**
     * Aplana el cuerpo multipart de una petición a `nombre => contenido`.
     *
     * @return array<string, mixed>
     */
    private function partes(ClientRequest $request): array
    {
        $partes = [];

        foreach ($request->data() as $parte) {
            if (is_array($parte) && isset($parte['name'])) {
                $partes[$parte['name']] = $parte['contents'] ?? null;
            }
        }

        return $partes;
    }

    private function subir(array $extra = [], ?Usuario $como = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($como ?? $this->admin(), 'sanctum')
            ->post(self::RUTA, $this->payload($extra), ['Accept' => 'application/json']);
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_un_usuario_de_tienda_no_puede_activar_produccion(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir(como: Usuario::factory()->vendedor('PUNDA50')->create())->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_un_invitado_no_puede_activar_produccion(): void
    {
        $this->config();
        $this->fakeApi();

        $this->post(self::RUTA, $this->payload(), ['Accept' => 'application/json'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    // ── Validación del archivo ──────────────────────────────────────────────

    /** @return array<string, array{UploadedFile|null}> */
    public static function certificadosInvalidos(): array
    {
        return [
            'extensión no permitida'      => [fn () => UploadedFile::fake()->create('logo.png', 10, 'image/png')],
            'png renombrado a .pfx'       => [fn () => UploadedFile::fake()->create('cert.pfx', 10, 'image/png')],
            'ejecutable disfrazado'       => [fn () => UploadedFile::fake()->create('cert.exe', 10, 'application/octet-stream')],
            'archivo demasiado grande'    => [fn () => UploadedFile::fake()->create('cert.pfx', 6000, 'application/x-pkcs12')],
        ];
    }

    #[DataProvider('certificadosInvalidos')]
    public function test_rechaza_certificados_con_mime_o_extension_invalidos(\Closure $archivo): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir(['certificado' => $archivo()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('certificado');

        Http::assertNothingSent();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function camposObligatorios(): array
    {
        return [
            'sin certificado'  => [['certificado' => null], 'certificado'],
            'sin password'     => [['certificado_password' => ''], 'certificado_password'],
            'password en blanco' => [['certificado_password' => '   '], 'certificado_password'],
            'sin usuario sol'  => [['usuario_sol' => ''], 'usuario_sol'],
            'sin clave sol'    => [['clave_sol' => ''], 'clave_sol'],
        ];
    }

    #[DataProvider('camposObligatorios')]
    public function test_exige_los_campos_obligatorios(array $extra, string $campo): void
    {
        $this->config();
        $this->fakeApi();

        if ($extra === ['certificado' => null]) {
            $payload = $this->payload();
            unset($payload['certificado']);
            $response = $this->actingAs($this->admin(), 'sanctum')
                ->post(self::RUTA, $payload, ['Accept' => 'application/json']);
        } else {
            $response = $this->subir($extra);
        }

        $response->assertStatus(422)->assertJsonValidationErrors($campo);

        Http::assertNothingSent();
    }

    // ── Flujo completo ──────────────────────────────────────────────────────

    public function test_flujo_completo_con_pfx_moderno_activa_produccion(): void
    {
        $config = $this->config();
        $this->fakeApi();

        $response = $this->subir();

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('certificado_path', 'sunat/'.self::RUC.'/certificado.pem');

        $config->refresh();
        $this->assertSame('produccion', $config->modo);
        $this->assertTrue($config->activo);

        Storage::disk('local')->assertExists('sunat/'.self::RUC.'/certificado.pem');
        $this->assertStringContainsString('-----BEGIN', Storage::disk('local')->get('sunat/'.self::RUC.'/certificado.pem'));
    }

    /**
     * El caso que motiva el `-legacy`: PFX cifrado con RC2/3DES, como los que
     * SUNAT entregaba. Debe convertirse igual y llegar a la API como PEM.
     */
    public function test_flujo_completo_con_pfx_legacy_rc2_3des(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir(['certificado' => $this->certificado('certificado_legacy.pfx')])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function (ClientRequest $request) {
            if (! str_ends_with($request->url(), '/api/v1/setup/configure-sunat')) {
                return false;
            }

            // Llegó convertido: la API recibe PEM, nunca el PFX binario.
            return str_contains($request->body(), '-----BEGIN');
        });
    }

    public function test_un_pem_se_sube_tal_cual_sin_convertir(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir(['certificado' => $this->certificado('certificado.pem')])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Storage::disk('local')->assertExists('sunat/'.self::RUC.'/certificado.pem');
    }

    public function test_envia_el_certificado_y_las_credenciales_sol_a_la_api(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir()->assertOk();

        // Paso 2: certificado + password + producción.
        Http::assertSent(function (ClientRequest $request) {
            if (! str_ends_with($request->url(), '/api/v1/setup/configure-sunat')) {
                return false;
            }

            $partes = $this->partes($request);

            return $request->isMultipart()
                && $request->hasHeader('Authorization', 'Bearer tok_de_la_api_externa')
                && $partes['company_id'] === '1'
                && $partes['environment'] === 'produccion'
                && $partes['certificate_password'] === self::PASSWORD
                && $partes['force_update'] === 'true'
                && array_key_exists('certificate_file', $partes);
        });

        // Paso 3: credenciales SOL vía POST + _method=PUT.
        Http::assertSent(function (ClientRequest $request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/companies/1')) {
                return false;
            }

            $partes = $this->partes($request);

            return ($partes['_method'] ?? null) === 'PUT'
                && $partes['usuario_sol'] === 'MODDATOS'
                && $partes['clave_sol'] === 'moddatos123'
                && $partes['ruc'] === self::RUC
                && $partes['razon_social'] === 'KYRO SAC'
                && $partes['web'] === 'https://kyro.test'
                && $partes['activo'] === '1';
        });

        // Paso 4: encender producción.
        Http::assertSent(
            fn (ClientRequest $request) => str_ends_with($request->url(), '/toggle-production')
                && ($this->partes($request)['modo_produccion'] ?? null) === '1'
        );
    }

    public function test_resuelve_la_config_de_la_tienda_indicada_multi_emisor(): void
    {
        $this->config(['ruc' => '20999999999']);
        $tienda = FacturacionConfig::factory()->deTienda('PUNDA50')->create([
            'base_url'   => self::BASE_URL,
            'api_token'  => 'tok_de_la_api_externa',
            'ruc'        => self::RUC,
            'company_id' => 1,
            'modo'       => 'beta',
            'activo'     => false,
        ]);
        $this->fakeApi();

        $this->subir(['tienda_id' => 'PUNDA50'])
            ->assertOk()
            ->assertJsonPath('certificado_path', 'sunat/'.self::RUC.'/certificado.pem');

        $this->assertSame('produccion', $tienda->fresh()->modo);
        // La global no se toca.
        $this->assertSame('beta', FacturacionConfig::globalConfig()->modo);
    }

    // ── Errores de negocio ──────────────────────────────────────────────────

    public function test_password_incorrecto_no_llama_a_la_api(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir(['certificado_password' => 'password-malo'])
            ->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('msg', 'La contraseña del certificado es incorrecta o el archivo no es un .pfx válido.');

        Http::assertNothingSent();
        $this->assertSame('beta', FacturacionConfig::globalConfig()->modo);
    }

    public function test_sin_base_url_o_token_no_intenta_conectarse(): void
    {
        $this->config(['api_token' => null]);
        $this->fakeApi();

        $this->subir()
            ->assertStatus(400)
            ->assertJsonPath('msg', 'La conexión con el servicio de facturación no está configurada (falta URL o token).');

        Http::assertNothingSent();
    }

    public function test_sin_ninguna_config_registrada_responde_400(): void
    {
        $this->fakeApi();

        $this->subir()->assertStatus(400)->assertJsonPath('ok', false);

        Http::assertNothingSent();
    }

    public function test_si_no_se_puede_leer_la_empresa_responde_502_y_no_toca_la_config(): void
    {
        $config = $this->config();
        $this->fakeApi(['get-company' => Http::response(['message' => 'boom'], 500)]);

        $this->subir()
            ->assertStatus(502)
            ->assertJsonPath('msg', 'No se pudo conectar con el servicio de facturación para leer los datos de la empresa.');

        $this->assertSame('beta', $config->fresh()->modo);
        $this->assertFalse($config->fresh()->activo);
        Storage::disk('local')->assertMissing('sunat/'.self::RUC.'/certificado.pem');
    }

    public function test_una_caida_de_red_al_leer_la_empresa_responde_502(): void
    {
        $this->config();
        $this->fakeApi(['get-company' => fn () => throw new ConnectionException('sin red')]);

        $this->subir()->assertStatus(502)->assertJsonPath('ok', false);
    }

    public function test_si_la_api_rechaza_el_certificado_devuelve_sus_errores(): void
    {
        $config = $this->config();
        $this->fakeApi([
            'configure-sunat' => Http::response([
                'errors' => ['certificate_file' => ['El certificado ha expirado.']],
            ], 422),
        ]);

        $this->subir()
            ->assertStatus(400)
            ->assertJsonPath('msg', 'El servicio rechazó el certificado. El certificado ha expirado.');

        $this->assertSame('beta', $config->fresh()->modo);
    }

    public function test_si_la_api_rechaza_las_credenciales_sol_devuelve_sus_errores(): void
    {
        $this->config();
        $this->fakeApi([
            'update-company' => Http::response(['message' => 'Clave SOL inválida.'], 422),
        ]);

        $this->subir()
            ->assertStatus(400)
            ->assertJsonPath('msg', 'Revisa los datos ingresados: Clave SOL inválida.');
    }

    public function test_si_falla_el_toggle_de_produccion_avisa_que_lo_demas_si_se_guardo(): void
    {
        $config = $this->config();
        $this->fakeApi([
            'toggle-production' => Http::response(['message' => 'empresa sin certificado'], 400),
        ]);

        $this->subir()
            ->assertStatus(400)
            ->assertJsonPath(
                'msg',
                'El certificado y las credenciales se guardaron, pero no se pudo activar el modo producción: empresa sin certificado',
            );

        $this->assertSame('beta', $config->fresh()->modo);
    }

    /**
     * Paridad con el legacy: si el toggle NO llega a responder (errno de curl),
     * el legacy sigue adelante y marca producción igual. Documentado a propósito.
     */
    public function test_una_caida_de_red_en_el_toggle_no_aborta_el_flujo(): void
    {
        $config = $this->config();
        $this->fakeApi(['toggle-production' => fn () => throw new ConnectionException('sin red')]);

        $this->subir()->assertOk()->assertJsonPath('ok', true);

        $this->assertSame('produccion', $config->fresh()->modo);
    }

    // ── Seguridad ───────────────────────────────────────────────────────────

    public function test_los_temporales_del_certificado_se_borran_siempre(): void
    {
        $this->config();
        $this->fakeApi();

        $this->subir()->assertOk();
        $this->assertSame([], $this->temporalesVivos(), 'El PEM temporal debe borrarse tras el flujo feliz.');

        $this->fakeApi(['get-company' => Http::response([], 500)]);

        $this->subir()->assertStatus(502);
        $this->assertSame([], $this->temporalesVivos(), 'El PEM temporal debe borrarse también si el flujo falla.');
    }

    public function test_ningun_secreto_llega_a_los_logs(): void
    {
        $this->config();
        $this->fakeApi(['toggle-production' => fn () => throw new ConnectionException('sin red')]);

        $registrado = '';
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$registrado) {
            $registrado .= $e->message.' '.json_encode($e->context);
        });

        $this->subir()->assertOk();

        $this->assertNotSame('', $registrado, 'Se esperaba al menos el warning del toggle.');

        foreach ([self::PASSWORD, 'moddatos123', 'tok_de_la_api_externa'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $registrado);
        }
    }

    public function test_el_certificado_nunca_se_guarda_en_el_disco_publico(): void
    {
        Storage::fake('public');
        $this->config();
        $this->fakeApi();

        $this->subir()->assertOk();

        $this->assertSame([], Storage::disk('public')->allFiles());
        Storage::disk('local')->assertExists('sunat/'.self::RUC.'/certificado.pem');
    }

    public function test_la_respuesta_nunca_expone_secretos(): void
    {
        $this->config();
        $this->fakeApi();

        $contenido = $this->subir()->assertOk()->getContent();

        foreach ([self::PASSWORD, 'moddatos123', 'tok_de_la_api_externa'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $contenido);
        }
    }
}
