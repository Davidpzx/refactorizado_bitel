<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Consulta RUC (SUNAT). Equivalente moderno del módulo ApisConsumirRUCYDNI
 * del legacy (que scrapeaba); usa el mismo proveedor que DniController.
 */
class RucController extends Controller
{
    private const API_URL = 'https://api.apis.net.pe/v1/ruc?numero=';
    private const TTL     = 3600 * 24 * 7; // 7 días

    public function consultar(string $ruc): JsonResponse
    {
        if (! preg_match('/^\d{11}$/', $ruc)) {
            return response()->json(['success' => false, 'message' => 'RUC debe tener exactamente 11 dígitos.'], 422);
        }

        $cacheKey = "sunat_ruc_{$ruc}";
        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached);
        }

        try {
            $response = Http::timeout(8)->get(self::API_URL . $ruc);
            $body = $response->json();

            if (! $response->successful() || empty($body) || empty($body['nombre'] ?? $body['razonSocial'] ?? '')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró información para el RUC ' . $ruc,
                ], 404);
            }

            $normalizado = [
                'ruc'          => $body['numeroDocumento'] ?? $ruc,
                'razon_social' => $body['nombre'] ?? $body['razonSocial'] ?? null,
                'estado'       => $body['estado'] ?? null,
                'condicion'    => $body['condicion'] ?? null,
                'direccion'    => $body['direccion'] ?? null,
                'ubigeo'       => $body['ubigeo'] ?? null,
                'distrito'     => $body['distrito'] ?? null,
                'provincia'    => $body['provincia'] ?? null,
                'departamento' => $body['departamento'] ?? null,
            ];

            Cache::put($cacheKey, $normalizado, self::TTL);
            return response()->json($normalizado);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar SUNAT: ' . $e->getMessage(),
            ], 503);
        }
    }
}
