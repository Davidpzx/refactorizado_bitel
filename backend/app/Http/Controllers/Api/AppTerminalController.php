<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * APP-09a — canal de distribución del APK de la app de asistencia (Capacitor).
 *
 * El archivo vive en storage/app/{config('app_terminal.apk_path')} (mismo
 * patrón que `integrador/agente` en IntegradorController: storage_path()
 * directo, no el disco 'local' de Filesystem — en Laravel 11+ ese disco
 * apunta a storage/app/private). La versión + metadatos se persisten en
 * `app_terminal_version` (fila única id=1, igual que `configuracion_empresa`)
 * para no tener que leer el filesystem en cada GET de /version.
 */
class AppTerminalController extends Controller
{
    private function apkPath(): string
    {
        return storage_path('app/'.config('app_terminal.apk_path'));
    }

    public function version(): JsonResponse
    {
        $ruta = $this->apkPath();

        if (! is_file($ruta)) {
            return response()->json(['version' => null, 'disponible' => false]);
        }

        $fila = DB::table('app_terminal_version')->where('id', 1)->first();

        return response()->json([
            'version'        => $fila->version ?? config('app_terminal.version'),
            'disponible'     => true,
            'url_descarga'   => url('/api/v1/app-terminal/descargar'),
            'tamano_bytes'   => $fila->tamano_bytes ?? filesize($ruta),
            'actualizado_en' => $fila->actualizado_en ?? null,
        ]);
    }

    public function descargar(): BinaryFileResponse|JsonResponse
    {
        $ruta = $this->apkPath();

        if (! is_file($ruta)) {
            return response()->json(['error' => 'Aún no se ha publicado ninguna versión de la app.'], 404);
        }

        return response()->download($ruta, 'asistencia.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function subir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apk'     => ['required', 'file', 'max:'.config('app_terminal.max_upload_kb')],
            'version' => ['required', 'string', 'max:20'],
        ]);

        $archivo = $request->file('apk');
        if (strtolower((string) $archivo->getClientOriginalExtension()) !== 'apk') {
            return response()->json(['error' => 'El archivo debe tener extensión .apk.'], 422);
        }

        $ruta = $this->apkPath();
        if (! is_dir(dirname($ruta))) {
            mkdir(dirname($ruta), 0755, true);
        }
        $archivo->move(dirname($ruta), basename($ruta));

        DB::table('app_terminal_version')->upsert([
            'id'             => 1,
            'version'        => $data['version'],
            'tamano_bytes'   => filesize($ruta),
            'actualizado_en' => now(),
        ], ['id'], ['version', 'tamano_bytes', 'actualizado_en']);

        return response()->json([
            'message' => 'APK publicado.',
            'version' => $data['version'],
        ]);
    }
}
