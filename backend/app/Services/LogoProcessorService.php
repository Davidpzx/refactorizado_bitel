<?php

namespace App\Services;

use GdImage;

/**
 * Procesa el logo subido en el Perfil de Empresa: quita el fondo sólido con
 * flood-fill desde las 4 esquinas (distancia de color, tolerancia
 * configurable), redimensiona a máx. 400px de lado mayor y devuelve un data
 * URI PNG transparente.
 *
 * Port de `config/logo_helpers.php::procesar_logo_upload()` (legacy).
 */
class LogoProcessorService
{
    private const MAX_LADO = 400;
    private const TOLERANCIA_DEFECTO = 50;

    /** Devuelve un data URI PNG transparente, o null si el archivo no es una imagen válida. */
    public function procesar(string $rutaArchivo, int $tolerancia = self::TOLERANCIA_DEFECTO): ?string
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

        // Redimensionar a máx. 400px de lado mayor (mantener proporción).
        $escala = min(1.0, self::MAX_LADO / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $img = imagecreatetruecolor($nw, $nh);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparente = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, $nw, $nh, $transparente);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        $this->quitarFondo($img, $nw, $nh, $transparente, $tolerancia);

        ob_start();
        imagepng($img, null, 9);
        $png = ob_get_clean();
        imagedestroy($img);

        if ($png === false || $png === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($png);
    }

    /**
     * Flood-fill desde las 4 esquinas: solo se vuelven transparentes los
     * píxeles conectados al borde y cercanos al color de fondo, así no se
     * borran píxeles internos del logo que casualmente coincidan con el
     * color de fondo.
     */
    private function quitarFondo(GdImage $img, int $nw, int $nh, int $transparente, int $tolerancia): void
    {
        [$br, $bg, $bb] = $this->colorPromedioDeEsquinas($img, $nw, $nh);
        $tol2 = $tolerancia * $tolerancia;

        $visitado = array_fill(0, $nw * $nh, false);
        $pila = [];

        foreach ([[0, 0], [$nw - 1, 0], [0, $nh - 1], [$nw - 1, $nh - 1]] as $p) {
            $pila[] = $p[1] * $nw + $p[0];
        }

        while (! empty($pila)) {
            $idx = array_pop($pila);

            if ($visitado[$idx]) {
                continue;
            }

            $visitado[$idx] = true;

            $x = $idx % $nw;
            $y = intdiv($idx, $nw);

            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $dr = $r - $br;
            $dg = $g - $bg;
            $db = $b - $bb;

            if (($dr * $dr + $dg * $dg + $db * $db) > $tol2) {
                continue;
            }

            imagesetpixel($img, $x, $y, $transparente);

            if ($x > 0) {
                $n = $idx - 1;
                if (! $visitado[$n]) {
                    $pila[] = $n;
                }
            }
            if ($x < $nw - 1) {
                $n = $idx + 1;
                if (! $visitado[$n]) {
                    $pila[] = $n;
                }
            }
            if ($y > 0) {
                $n = $idx - $nw;
                if (! $visitado[$n]) {
                    $pila[] = $n;
                }
            }
            if ($y < $nh - 1) {
                $n = $idx + $nw;
                if (! $visitado[$n]) {
                    $pila[] = $n;
                }
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function colorPromedioDeEsquinas(GdImage $img, int $nw, int $nh): array
    {
        $esquinas = [
            imagecolorat($img, 0, 0),
            imagecolorat($img, $nw - 1, 0),
            imagecolorat($img, 0, $nh - 1),
            imagecolorat($img, $nw - 1, $nh - 1),
        ];

        $r = $g = $b = 0;

        foreach ($esquinas as $c) {
            $r += ($c >> 16) & 0xFF;
            $g += ($c >> 8) & 0xFF;
            $b += $c & 0xFF;
        }

        return [(int) ($r / 4), (int) ($g / 4), (int) ($b / 4)];
    }
}
