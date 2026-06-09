<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfiguracionController extends Controller
{
    public function show(): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json($this->defaults());
        }

        $row = DB::table('configuracion_empresa')->where('id', 1)->first();

        if (!$row) {
            return response()->json($this->defaults());
        }

        $data = (array) $row;
        // No exponer el logo en este endpoint (puede ser muy grande)
        unset($data['logo_base64']);

        return response()->json($data);
    }

    public function showConLogo(): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json($this->defaults());
        }

        $row = DB::table('configuracion_empresa')->where('id', 1)->first();

        return response()->json($row ? (array) $row : $this->defaults());
    }

    public function update(Request $request): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json(['error' => 'Tabla configuracion_empresa no existe.'], 422);
        }

        $data = $request->validate([
            'razon_social'        => ['required', 'string', 'max:200'],
            'nombre_comercial'    => ['nullable', 'string', 'max:200'],
            'ruc'                 => ['required', 'string', 'max:11'],
            'gerente_general'     => ['nullable', 'string', 'max:100'],
            'gerente_dni'         => ['nullable', 'string', 'max:8'],
            'sistema_nombre'      => ['nullable', 'string', 'max:100'],
            'telefono_contacto'   => ['nullable', 'string', 'max:20'],
            'direccion_principal' => ['nullable', 'string', 'max:255'],
            'correo_contacto'     => ['nullable', 'email', 'max:100'],
        ]);

        // UPSERT — crea id=1 si no existe
        DB::table('configuracion_empresa')->upsert(
            array_merge(['id' => 1], $data),
            ['id'],
            array_keys($data)
        );

        return response()->json(['message' => 'Configuración guardada.']);
    }

    public function updateLogo(Request $request): JsonResponse
    {
        if (!Schema::hasTable('configuracion_empresa')) {
            return response()->json(['error' => 'Tabla configuracion_empresa no existe.'], 422);
        }

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $mime     = $request->file('logo')->getMimeType();
        $base64   = base64_encode(file_get_contents($request->file('logo')->getRealPath()));
        $dataUrl  = "data:{$mime};base64,{$base64}";

        DB::table('configuracion_empresa')
            ->upsert(['id' => 1, 'logo_base64' => $dataUrl], ['id'], ['logo_base64']);

        return response()->json(['message' => 'Logo actualizado.', 'logo_base64' => $dataUrl]);
    }

    public function deleteLogo(): JsonResponse
    {
        DB::table('configuracion_empresa')
            ->where('id', 1)
            ->update(['logo_base64' => null]);

        return response()->json(['message' => 'Logo eliminado.']);
    }

    private function defaults(): array
    {
        return [
            'id'                  => null,
            'razon_social'        => '',
            'nombre_comercial'    => '',
            'ruc'                 => '',
            'gerente_general'     => '',
            'gerente_dni'         => '',
            'sistema_nombre'      => 'SIS-KYRO',
            'telefono_contacto'   => '',
            'direccion_principal' => '',
            'correo_contacto'     => '',
        ];
    }
}
