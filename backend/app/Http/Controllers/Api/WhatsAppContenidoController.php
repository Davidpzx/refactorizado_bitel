<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use App\Models\WhatsAppBotFotoProducto;
use App\Models\WhatsAppBotPromocion;
use App\Services\WhatsApp\ImagenProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppContenidoController extends Controller
{
    private function esAdministrador(): bool
    {
        $user = Auth::user();

        return $user instanceof Usuario && $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR);
    }

    public function promocion(): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json(['ok' => true, 'data' => WhatsAppBotPromocion::find(1)]);
    }

    public function guardarPromocion(Request $request, ImagenProductoService $procesador): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $data = $request->validate([
            'texto' => ['required', 'string'],
            'foto' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $valores = ['texto' => $data['texto']];
        if ($request->hasFile('foto')) {
            $dataUri = $procesador->procesar($request->file('foto')->getRealPath());
            if ($dataUri === null) {
                return response()->json(['message' => 'No se pudo procesar la imagen.'], 422);
            }
            $valores['foto_base64'] = $dataUri;
        }

        WhatsAppBotPromocion::updateOrCreate(['id' => 1], $valores);

        return response()->json(['ok' => true]);
    }

    public function fotosProducto(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json(['ok' => true, 'data' => WhatsAppBotFotoProducto::orderBy('producto_nombre')->get()]);
    }

    public function guardarFotoProducto(Request $request, ImagenProductoService $procesador): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $data = $request->validate([
            'producto_nombre' => ['required', 'string', 'max:150'],
            'foto' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $dataUri = $procesador->procesar($request->file('foto')->getRealPath());
        if ($dataUri === null) {
            return response()->json(['message' => 'No se pudo procesar la imagen.'], 422);
        }

        WhatsAppBotFotoProducto::updateOrCreate(
            ['producto_nombre' => $data['producto_nombre']],
            ['foto_base64' => $dataUri]
        );

        return response()->json(['ok' => true]);
    }

    public function eliminarFotoProducto(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        WhatsAppBotFotoProducto::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function buscarNombresInventario(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $nombres = InventarioTienda::where('producto_nombre', 'like', "%{$q}%")
            ->distinct()->limit(10)->pluck('producto_nombre');

        return response()->json($nombres);
    }
}
