<?php

namespace Tests\Unit;

use App\Services\LogoProcessorService;
use PHPUnit\Framework\TestCase;

/**
 * Port de `config/logo_helpers.php::procesar_logo_upload()`: flood-fill de
 * fondo desde las 4 esquinas + redimensión a máx. 400px + PNG transparente.
 */
class LogoProcessorServiceTest extends TestCase
{
    private LogoProcessorService $service;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LogoProcessorService();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    private function guardarComoJpeg(\GdImage $img): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'logo_test_').'.jpg';
        imagejpeg($img, $ruta, 95);
        imagedestroy($img);

        $this->temporales[] = $ruta;

        return $ruta;
    }

    private function guardarComoPng(\GdImage $img): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'logo_test_').'.png';
        imagepng($img, $ruta);
        imagedestroy($img);

        $this->temporales[] = $ruta;

        return $ruta;
    }

    /** Cuadrado rojo de 20x20 centrado sobre fondo blanco sólido de 100x100. */
    private function imagenConFondoBlancoSolido(): \GdImage
    {
        $img = imagecreatetruecolor(100, 100);
        $blanco = imagecolorallocate($img, 255, 255, 255);
        $rojo = imagecolorallocate($img, 220, 20, 20);
        imagefilledrectangle($img, 0, 0, 99, 99, $blanco);
        imagefilledrectangle($img, 40, 40, 59, 59, $rojo);

        return $img;
    }

    private function esquinaTransparente(string $dataUrl, int $x, int $y): bool
    {
        [, $b64] = explode(',', $dataUrl, 2);
        $img = imagecreatefromstring(base64_decode($b64));
        $color = imagecolorat($img, $x, $y);
        $alpha = ($color >> 24) & 0x7F;
        imagedestroy($img);

        return $alpha === 127;
    }

    public function test_devuelve_null_si_el_archivo_no_existe(): void
    {
        $this->assertNull($this->service->procesar('/ruta/que/no/existe.jpg'));
    }

    public function test_devuelve_null_si_el_archivo_no_es_una_imagen_valida(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'no_imagen_');
        file_put_contents($ruta, 'esto no es una imagen');
        $this->temporales[] = $ruta;

        $this->assertNull($this->service->procesar($ruta));
    }

    public function test_fondo_blanco_solido_sale_transparente(): void
    {
        $ruta = $this->guardarComoJpeg($this->imagenConFondoBlancoSolido());

        $resultado = $this->service->procesar($ruta);

        $this->assertNotNull($resultado);
        $this->assertStringStartsWith('data:image/png;base64,', $resultado);
        $this->assertTrue($this->esquinaTransparente($resultado, 0, 0), 'la esquina superior-izquierda debe quedar transparente');
        $this->assertTrue($this->esquinaTransparente($resultado, 99, 99), 'la esquina inferior-derecha debe quedar transparente');
    }

    public function test_el_centro_del_logo_no_queda_transparente(): void
    {
        $ruta = $this->guardarComoJpeg($this->imagenConFondoBlancoSolido());

        $resultado = $this->service->procesar($ruta);

        $this->assertNotNull($resultado);
        $this->assertFalse($this->esquinaTransparente($resultado, 50, 50), 'el cuadrado rojo central no debe borrarse');
    }

    public function test_imagen_ya_transparente_pasa_intacta_sin_perder_su_alpha(): void
    {
        $img = imagecreatetruecolor(50, 50);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparente = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, 49, 49, $transparente);
        $rojo = imagecolorallocatealpha($img, 220, 20, 20, 0);
        imagefilledrectangle($img, 10, 10, 39, 39, $rojo);

        $ruta = $this->guardarComoPng($img);

        $resultado = $this->service->procesar($ruta);

        $this->assertNotNull($resultado);
        $this->assertTrue($this->esquinaTransparente($resultado, 0, 0));
        $this->assertFalse($this->esquinaTransparente($resultado, 25, 25));
    }

    public function test_redimensiona_a_maximo_400px_de_lado_mayor(): void
    {
        $img = imagecreatetruecolor(800, 400);
        $blanco = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 799, 399, $blanco);

        $ruta = $this->guardarComoJpeg($img);

        $resultado = $this->service->procesar($ruta);
        $this->assertNotNull($resultado);

        [, $b64] = explode(',', $resultado, 2);
        $decodificada = imagecreatefromstring(base64_decode($b64));

        $this->assertSame(400, imagesx($decodificada));
        $this->assertSame(200, imagesy($decodificada));
    }

    public function test_no_redimensiona_una_imagen_ya_pequena(): void
    {
        $ruta = $this->guardarComoJpeg($this->imagenConFondoBlancoSolido());

        $resultado = $this->service->procesar($ruta);
        $this->assertNotNull($resultado);

        [, $b64] = explode(',', $resultado, 2);
        $decodificada = imagecreatefromstring(base64_decode($b64));

        $this->assertSame(100, imagesx($decodificada));
        $this->assertSame(100, imagesy($decodificada));
    }
}
