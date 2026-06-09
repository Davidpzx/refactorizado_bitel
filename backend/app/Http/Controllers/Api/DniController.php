<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DniController extends Controller
{
    private const API_URL   = 'https://api-codart.cgrt.org/api/v1/consultas/reniec/dni/';
    private const API_TOKEN = '2ipOi8UVT1PxIaEG6ZI9f5c2wfAd4ytlh5SYIVSfWdqtcLdrt91ruMkx0Hwf';
    private const TTL       = 3600 * 24; // 24 h

    public function consultar(Request $request, string $dni): JsonResponse
    {
        if (!preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['success' => false, 'message' => 'DNI debe tener exactamente 8 dígitos.'], 422);
        }

        // Cachear por DNI — evita llamadas repetidas a la API externa
        $cacheKey = "reniec_dni_{$dni}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return response()->json($cached);
        }

        try {
            $response = Http::timeout(8)
                ->withToken(self::API_TOKEN)
                ->get(self::API_URL . $dni);

            $body = $response->json();

            if ($response->successful() && !empty($body)) {
                Cache::put($cacheKey, $body, self::TTL);
                return response()->json($body);
            }

            return response()->json([
                'success' => false,
                'message' => 'No se encontró información para el DNI ' . $dni,
            ], 404);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al consultar RENIEC: ' . $e->getMessage(),
            ], 503);
        }
    }
}
