<?php

namespace Tests\Unit;

use App\Exceptions\SunatSetupException;
use App\Services\Facturacion\CertificadoDigitalService;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Port de `_cert_es_pem()` / `_convertir_pfx_a_pem()` del legacy
 * (`gerencia/ajax_configurar_sunat.php`).
 *
 * Los fixtures de `tests/Fixtures/certificados` son certificados autofirmados
 * de juguete (no son de SUNAT). `certificado_legacy.pfx` está cifrado con
 * RC2-40/3DES a propósito: es lo que usan los PFX viejos de SUNAT y lo que
 * OpenSSL 3.x rechaza si no se le pasa `-legacy`.
 */
class CertificadoDigitalServiceTest extends TestCase
{
    private const PASSWORD = 'secreto123';

    private CertificadoDigitalService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servicio = new CertificadoDigitalService();
    }

    private function fixture(string $nombre): string
    {
        return __DIR__.'/../Fixtures/certificados/'.$nombre;
    }

    private function exigirOpenssl(): void
    {
        if (! $this->servicio->opensslDisponible()) {
            $this->markTestSkipped('OpenSSL CLI no está disponible en este entorno.');
        }
    }

    // ── Detección PEM vs PFX ────────────────────────────────────────────────

    public function test_reconoce_un_pem_por_el_marcador_begin(): void
    {
        $this->assertTrue($this->servicio->esPem($this->fixture('certificado.pem')));
    }

    #[DataProvider('archivosPfx')]
    public function test_un_pfx_binario_no_es_pem(string $fixture): void
    {
        $this->assertFalse($this->servicio->esPem($this->fixture($fixture)));
    }

    /** @return array<string, array{string}> */
    public static function archivosPfx(): array
    {
        return [
            'pfx moderno (AES)'      => ['certificado_moderno.pfx'],
            'pfx legacy (RC2/3DES)'  => ['certificado_legacy.pfx'],
        ];
    }

    public function test_el_marcador_begin_se_busca_en_toda_la_cabecera_no_solo_al_inicio(): void
    {
        // Es exactamente la forma del PEM que devuelve `openssl pkcs12`: primero
        // un bloque `Bag Attributes` y recién después el `-----BEGIN`.
        $ruta = tempnam(sys_get_temp_dir(), 'test_pem_');
        file_put_contents($ruta, "Bag Attributes\n    localKeyID: 01 02\n-----BEGIN PRIVATE KEY-----\nabc\n");

        $this->assertTrue($this->servicio->esPem($ruta));

        @unlink($ruta);
    }

    public function test_un_archivo_inexistente_no_es_pem(): void
    {
        $this->assertFalse($this->servicio->esPem(sys_get_temp_dir().'/no_existe_jamas.pem'));
    }

    public function test_un_archivo_de_texto_sin_marcador_no_es_pem(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'test_txt_');
        file_put_contents($ruta, 'esto no es un certificado');

        $this->assertFalse($this->servicio->esPem($ruta));

        @unlink($ruta);
    }

    // ── Conversión PFX → PEM ────────────────────────────────────────────────

    public function test_convierte_un_pfx_moderno_a_pem(): void
    {
        $this->exigirOpenssl();

        $pem = $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), self::PASSWORD);

        $this->assertFileExists($pem);
        $this->assertTrue($this->servicio->esPem($pem));
        $this->assertStringContainsString('-----BEGIN', (string) file_get_contents($pem));
    }

    /**
     * El caso que motiva todo el `-legacy`: sin esa bandera, OpenSSL 3.x falla
     * con `RC2-40-CBC unsupported`. Si este test pasa, el reintento funciona.
     */
    public function test_convierte_un_pfx_legacy_rc2_3des_reintentando_con_la_bandera_legacy(): void
    {
        $this->exigirOpenssl();

        $pem = $this->servicio->convertirPfxAPem($this->fixture('certificado_legacy.pfx'), self::PASSWORD);

        $this->assertFileExists($pem);
        $this->assertTrue($this->servicio->esPem($pem));
        $this->assertStringContainsString('-----BEGIN', (string) file_get_contents($pem));
    }

    public function test_el_pem_convertido_incluye_la_clave_privada_sin_cifrar(): void
    {
        $this->exigirOpenssl();

        $pem = $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), self::PASSWORD);
        $contenido = (string) file_get_contents($pem);

        // `-nodes`: la clave sale sin cifrar, que es lo que exige Greenter.
        $this->assertStringContainsString('PRIVATE KEY', $contenido);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $contenido);
        $this->assertStringNotContainsString('ENCRYPTED', $contenido);
    }

    public function test_password_incorrecto_falla_con_mensaje_de_negocio(): void
    {
        $this->exigirOpenssl();

        $this->expectException(SunatSetupException::class);
        $this->expectExceptionMessage('La contraseña del certificado es incorrecta');

        $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), 'password-malo');
    }

    public function test_un_archivo_que_no_es_pfx_falla(): void
    {
        $this->exigirOpenssl();

        $this->expectException(SunatSetupException::class);

        $this->servicio->convertirPfxAPem($this->fixture('certificado.pem'), self::PASSWORD);
    }

    public function test_el_mensaje_de_error_nunca_contiene_el_password(): void
    {
        $this->exigirOpenssl();

        try {
            $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), 'clave-super-secreta');
            $this->fail('Debió lanzar SunatSetupException.');
        } catch (SunatSetupException $e) {
            $this->assertStringNotContainsString('clave-super-secreta', $e->getMessage());
        }
    }

    // ── Limpieza de temporales ──────────────────────────────────────────────

    public function test_limpiar_temporales_borra_el_pem_convertido(): void
    {
        $this->exigirOpenssl();

        $pem = $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), self::PASSWORD);
        $this->assertFileExists($pem);

        $this->servicio->limpiarTemporales();

        $this->assertFileDoesNotExist($pem);
        $this->assertSame([], $this->servicio->temporales());
    }

    public function test_limpiar_temporales_tambien_borra_el_temporal_de_una_conversion_fallida(): void
    {
        $this->exigirOpenssl();

        try {
            $this->servicio->convertirPfxAPem($this->fixture('certificado_moderno.pfx'), 'password-malo');
        } catch (SunatSetupException) {
            // esperado
        }

        $temporales = $this->servicio->temporales();
        $this->assertCount(1, $temporales, 'La conversión fallida debe haber registrado su temporal.');

        $this->servicio->limpiarTemporales();

        $this->assertFileDoesNotExist($temporales[0]);
    }

    public function test_limpiar_temporales_es_idempotente(): void
    {
        $this->servicio->limpiarTemporales();
        $this->servicio->limpiarTemporales();

        $this->assertSame([], $this->servicio->temporales());
    }

    // ── Persistencia en disco privado ───────────────────────────────────────

    public function test_guarda_el_pem_en_el_disco_privado_bajo_el_ruc_del_emisor(): void
    {
        Storage::fake('local');

        $ruta = $this->servicio->guardarPemPrivado($this->fixture('certificado.pem'), '20123456789');

        $this->assertSame('sunat/20123456789/certificado.pem', $ruta);
        Storage::disk('local')->assertExists($ruta);
        $this->assertStringContainsString('-----BEGIN', Storage::disk('local')->get($ruta));
    }

    public function test_el_ruc_se_sanea_para_que_no_escape_de_la_carpeta(): void
    {
        Storage::fake('local');

        $ruta = $this->servicio->guardarPemPrivado($this->fixture('certificado.pem'), '../../etc/passwd');

        $this->assertSame('sunat/etcpasswd/certificado.pem', $ruta);
        $this->assertStringNotContainsString('..', $ruta);
    }

    public function test_un_emisor_sin_ruc_cae_en_una_carpeta_por_defecto(): void
    {
        Storage::fake('local');

        $this->assertSame('sunat/sin-ruc/certificado.pem', $this->servicio->guardarPemPrivado($this->fixture('certificado.pem'), ''));
    }
}
