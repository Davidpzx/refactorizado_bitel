<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documentos del agente (foto de perfil / foto o PDF del DNI), almacenados
 * como Data URI base64 en la BD — sin filesystem. Puerto de
 * gerencia/ajax_subir_doc_agente.php del legacy sis_bipay.
 */
class AgenteDocumentoController extends Controller
{
    private const CAMPOS = ['foto_perfil', 'foto_dni'];

    /** GET /v1/agentes/{id}/documentos */
    public function ver(int $id): JsonResponse
    {
        $agente = Agente::findOrFail($id);

        return response()->json([
            'foto_perfil' => $agente->foto_perfil ?? null,
            'foto_dni'    => $agente->foto_dni ?? null,
        ]);
    }

    /** POST /v1/agentes/{id}/documentos — multipart: campo (foto_perfil|foto_dni) + archivo. */
    public function subir(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'campo'   => 'required|in:' . implode(',', self::CAMPOS),
            'archivo' => 'required|file|max:10240',
        ]);

        $agente  = Agente::findOrFail($id);
        $campo   = $request->input('campo');
        $archivo = $request->file('archivo');
        $mime    = $archivo->getMimeType();

        $esPdf    = $campo === 'foto_dni' && $mime === 'application/pdf';
        $esImagen = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);

        if (! $esImagen && ! $esPdf) {
            return response()->json(['ok' => false, 'error' => $campo === 'foto_dni'
                ? 'Formato no permitido. Usa JPG, PNG, WEBP o PDF para el DNI.'
                : 'Formato no permitido. Usa JPG, PNG o WEBP.'], 422);
        }

        $maxBytes = $esPdf ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
        if ($archivo->getSize() > $maxBytes) {
            return response()->json(['ok' => false, 'error' => 'El archivo supera el límite permitido.'], 422);
        }

        $b64 = $esPdf
            ? 'data:application/pdf;base64,' . base64_encode(file_get_contents($archivo->getRealPath()))
            : ($this->comprimirImagen($archivo->getRealPath())
                ?? 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($archivo->getRealPath())));

        $agente->forceFill([$campo => $b64])->save();

        return response()->json(['ok' => true, 'b64' => $b64, 'es_pdf' => $esPdf]);
    }

    /** DELETE /v1/agentes/{id}/documentos/{campo} */
    public function eliminar(int $id, string $campo): JsonResponse
    {
        if (! in_array($campo, self::CAMPOS, true)) {
            return response()->json(['ok' => false, 'error' => 'campo inválido'], 422);
        }
        Agente::findOrFail($id)->forceFill([$campo => null])->save();

        return response()->json(['ok' => true]);
    }

    /** Redimensiona a máx 800px y recomprime a JPEG q80 (igual que el legacy). Requiere GD. */
    private function comprimirImagen(string $tmp, int $maxPx = 800, int $q = 80): ?string
    {
        if (! function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $info = @getimagesize($tmp);
        if (! $info) {
            return null;
        }
        [$w, $h, $tipo] = [$info[0], $info[1], $info[2]];

        $src = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default        => false,
        };
        if (! $src) {
            return null;
        }

        $ratio = min($maxPx / $w, $maxPx / $h, 1.0);
        $nw = (int) round($w * $ratio);
        $nh = (int) round($h * $ratio);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        imagejpeg($dst, null, $q);
        $data = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);

        return 'data:image/jpeg;base64,' . base64_encode($data);
    }
}
