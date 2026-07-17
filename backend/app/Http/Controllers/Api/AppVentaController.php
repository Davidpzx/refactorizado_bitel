<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Distribución del APK de la app "Venta Online" (mismo patrón que
 * AppTerminalController). El .apk vive en storage/app/{config('app_venta.apk_path')};
 * los metadatos (version, min_version, fecha_limite, OTA) en app_venta_version.
 */
class AppVentaController extends Controller
{
    private function apkPath(): string
    {
        return storage_path('app/' . config('app_venta.apk_path'));
    }

    public function version(): JsonResponse
    {
        $ruta = $this->apkPath();

        if (! is_file($ruta)) {
            return response()->json(['version' => null, 'disponible' => false]);
        }

        $fila = DB::table('app_venta_version')->orderByDesc('id')->first();

        return response()->json([
            'version'        => $fila->version ?? config('app_venta.version'),
            'disponible'     => true,
            'min_version'    => $fila->min_version ?? null,
            'fecha_limite'   => $fila->fecha_limite ?? null,
            'url_descarga'   => url('/api/v1/app-venta/descargar'),
            'tamano_bytes'   => $fila->tamano_bytes ?? filesize($ruta),
            'actualizado_en' => $fila->actualizado_en ?? null,
        ]);
    }

    public function ota(): JsonResponse
    {
        $fila = DB::table('app_venta_version')->orderByDesc('id')->first();

        if (! $fila || empty($fila->ota_bundle_version)) {
            return response()->json(['disponible' => false, 'bundle_version' => null]);
        }

        $base = url('/api/v1/app-venta/ota-file') . '?file=';

        return response()->json([
            'disponible'     => true,
            'bundle_version' => $fila->ota_bundle_version,
            'files'          => [
                'app.js'     => $base . 'app.js',
                'styles.css' => $base . 'styles.css',
                'index.html' => $base . 'index.html',
                'config.js'  => $base . 'config.js',
            ],
        ]);
    }

    /**
     * Sirve los 4 archivos de la app Venta Online desde storage/app/app-venta/ota/
     * (subidos junto con el APK). Mismo propósito que ota_files.php en rolando,
     * pero bitel no comparte filesystem con ese repo, así que aquí sí es una copia
     * que hay que re-subir cuando cambie la lógica compartida de la app.
     */
    public function otaFile(Request $request): mixed
    {
        $permitidos = [
            'app.js'     => 'application/javascript',
            'styles.css' => 'text/css',
            'index.html' => 'text/html',
            'config.js'  => 'application/javascript',
        ];

        $file = (string) $request->query('file', '');
        if (! isset($permitidos[$file])) {
            return response()->json(['success' => false, 'message' => 'Archivo no encontrado'], 404);
        }

        $ruta = storage_path('app/app-venta/ota/' . $file);
        if (! is_file($ruta)) {
            return response()->json(['success' => false, 'message' => 'Archivo no publicado'], 404);
        }

        return response(file_get_contents($ruta), 200, [
            'Content-Type'  => $permitidos[$file] . '; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function descargar(): BinaryFileResponse|JsonResponse
    {
        $ruta = $this->apkPath();

        if (! is_file($ruta)) {
            return response()->json(['error' => 'Aún no se ha publicado ninguna versión de la app.'], 404);
        }

        return response()->download($ruta, 'venta-online.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function subir(Request $request): JsonResponse
    {
        $data = $request->validate([
            'apk'          => ['required', 'file', 'max:' . config('app_venta.max_upload_kb')],
            'version'      => ['required', 'string', 'max:20'],
            'min_version'  => ['nullable', 'string', 'max:20'],
            'fecha_limite' => ['nullable', 'date'],
            'ota_bundle_version' => ['nullable', 'string', 'max:40'],
            'ota_url_zip'  => ['nullable', 'url', 'max:255'],
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

        DB::table('app_venta_version')->truncate();
        DB::table('app_venta_version')->insert([
            'version'            => $data['version'],
            'min_version'        => $data['min_version'] ?? null,
            'fecha_limite'       => $data['fecha_limite'] ?? null,
            'ota_bundle_version' => $data['ota_bundle_version'] ?? null,
            'ota_url_zip'        => $data['ota_url_zip'] ?? null,
            'tamano_bytes'       => filesize($ruta),
            'actualizado_en'     => now(),
        ]);

        return response()->json([
            'message' => 'APK publicado.',
            'version' => $data['version'],
        ]);
    }
}
