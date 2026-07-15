<?php

namespace Tests\Unit;

use App\Services\WhatsApp\ImagenProductoService;
use Tests\TestCase;

class ImagenProductoServiceTest extends TestCase
{
    public function test_procesa_una_imagen_valida(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $img = imagecreatetruecolor(1000, 500);
        imagepng($img, $ruta);
        imagedestroy($img);

        $resultado = (new ImagenProductoService())->procesar($ruta);

        $this->assertNotNull($resultado);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $resultado);
        unlink($ruta);
    }

    public function test_devuelve_null_si_no_es_imagen(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'notimg') . '.txt';
        file_put_contents($ruta, 'no soy una imagen');

        $resultado = (new ImagenProductoService())->procesar($ruta);

        $this->assertNull($resultado);
        unlink($ruta);
    }
}
