<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DniController extends Controller
{
    // Misma API que usa sis_bipay — pública, sin token, confiable
    private const API_URL = 'https://api.apis.net.pe/v2/reniec/dni?numero=';
    private const TTL     = 3600 * 24 * 7; // 7 días (datos RENIEC no cambian)

    public function consultar(Request $request, string $dni): JsonResponse
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['success' => false, 'message' => 'DNI debe tener exactamente 8 dígitos.'], 422);
        }

        $cacheKey = "reniec_dni_{$dni}";
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders(['Referer' => config('app.url', 'http://localhost')])
                ->get(self::API_URL . $dni);

            $body = $response->json();

            if (!$response->successful() || empty($body) || isset($body['message'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se encontró información para el DNI ' . $dni,
                ], 404);
            }

            // Normalizar a snake_case para que el frontend lo consuma directamente.
            // apis.net.pe devuelve: nombres, apellidoPaterno, apellidoMaterno (camelCase)
            $normalizado = [
                'nombres'          => $body['nombres']          ?? null,
                'apellido_paterno' => $body['apellidoPaterno']  ?? $body['apellido_paterno'] ?? null,
                'apellido_materno' => $body['apellidoMaterno']  ?? $body['apellido_materno'] ?? null,
                'numero_documento' => $body['numeroDocumento']  ?? $body['numero_documento'] ?? $dni,
                'tipo_documento'   => $body['tipoDocumento']    ?? $body['tipo_documento']   ?? 1,
                'nombre_completo'  => $body['nombre_completo']  ?? null,
            ];

            // nombre_completo como fallback calculado si la API no lo trae
            if (empty($normalizado['nombre_completo'])) {
                $normalizado['nombre_completo'] = trim(
                    implode(' ', array_filter([
                        $normalizado['nombres'],
                        $normalizado['apellido_paterno'],
                        $normalizado['apellido_materno'],
                    ]))
                ) ?: null;
            }

            Cache::put($cacheKey, $normalizado, self::TTL);
            return response()->json($normalizado);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar RENIEC: ' . $e->getMessage(),
            ], 503);
        }
    }
}
