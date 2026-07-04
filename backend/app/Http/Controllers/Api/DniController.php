<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class DniController extends Controller
{
    // v1 = pública, sin token — igual que sis_bipay
    private const API_URL = 'https://api.apis.net.pe/v1/dni?numero=';
    private const TTL     = 3600 * 24 * 7; // 7 días (RENIEC no cambia)

    public function consultar(Request $request, string $dni): JsonResponse
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['success' => false, 'message' => 'DNI debe tener exactamente 8 dígitos.'], 422);
        }

        // Caché de primer nivel: si el CRM ya capturó este DNI, se evita la API externa por completo.
        $local = DB::table('crm_clientes')->where('dni', $dni)->first(['nombres', 'apellidos']);
        if ($local) {
            $ap = explode(' ', trim($local->apellidos), 2);
            return response()->json([
                'nombres'          => $local->nombres,
                'apellido_paterno' => $ap[0] ?? '',
                'apellido_materno' => $ap[1] ?? '',
                'numero_documento' => $dni,
                'tipo_documento'   => 1,
                'nombre_completo'  => trim($local->nombres . ' ' . $local->apellidos),
                'fuente'           => 'cache_local',
            ]);
        }

        $cacheKey = "reniec_dni_{$dni}";
        $cached   = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        try {
            $response = Http::timeout(8)->get(self::API_URL . $dni);

            $body = $response->json();

            // v1 devuelve {} vacío o sin nombres si no encuentra
            if (!$response->successful() || empty($body) || empty($body['nombres'] ?? '')) {
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
