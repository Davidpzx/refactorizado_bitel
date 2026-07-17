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

        if (! $fila || empty($fila->ota_bundle_version) || empty($fila->ota_url_zip)) {
            return response()->json(['disponible' => false, 'bundle_version' => null]);
        }

        return response()->json([
            'disponible'     => true,
            'bundle_version' => $fila->ota_bundle_version,
            'url_zip'        => $fila->ota_url_zip,
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
