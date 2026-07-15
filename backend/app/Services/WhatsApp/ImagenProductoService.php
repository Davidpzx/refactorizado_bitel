<?php

namespace App\Services\WhatsApp;

class ImagenProductoService
{
    private const MAX_LADO = 800;

    /** Redimensiona a max 800px de lado mayor y devuelve un data URI JPEG. Null si no es una imagen valida. */
    public function procesar(string $rutaArchivo): ?string
    {
        if (! is_file($rutaArchivo)) {
            return null;
        }

        $info = @getimagesize($rutaArchivo);
        if ($info === false) {
            return null;
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($rutaArchivo),
            IMAGETYPE_PNG => @imagecreatefrompng($rutaArchivo),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($rutaArchivo) : false,
            default => false,
        };
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $escala = min(1.0, self::MAX_LADO / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $img = imagecreatetruecolor($nw, $nh);
        $blanco = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $nw, $nh, $blanco);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        if ($jpeg === false || $jpeg === '') {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
