<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DiagnosticoController extends Controller
{
    public function index(): JsonResponse
    {
        $user = Auth::user();
        // Diagnóstico técnico: exclusivo administrador (plan 16, ni el gerente lo tiene).
        if (! Permisos::puede($user, 'config_tecnica')) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $tiendas = DB::table('tiendas')
            ->orderBy('id')
            ->select('id', 'codigo', 'nombre', 'cuenta_bipay_id', 'activo')
            ->get();

        $usuarios = DB::table('usuarios')
            ->orderByRaw("rol ASC, nombre ASC")
            ->select('id', 'nombre', 'email', 'rol', 'tienda_id', 'activo')
            ->get();

        $chips = DB::table('inventario_chips as ic')
            ->leftJoin('tiendas as t', 't.id', '=', 'ic.tienda_id')
            ->orderBy('ic.id')
            ->select('ic.id', 't.codigo as tienda_codigo', 't.nombre as tienda_nombre', 'ic.tienda_origen', 'ic.stock_actual')
            ->get();

        $sesion = [
            'user_id'  => $user->id,
            'tienda_id' => $user->tienda_id,
            'rol'      => $user->rol,
            'nombre'   => $user->nombre,
        ];

        // Traslados pendientes
        $trasladosPendientes = DB::table('traslados_stock')
            ->whereIn('estado', ['PENDIENTE', 'PENDIENTE_APROBACION'])
            ->count();
        $chipsPendientes = DB::table('traslados_chips')
            ->whereIn('estado', ['PENDIENTE', 'PENDIENTE_APROBACION'])
            ->count();

        return response()->json([
            'sesion'                   => $sesion,
            'tiendas'                  => $tiendas,
            'usuarios'                 => $usuarios,
            'chips'                    => $chips,
            'traslados_pendientes'     => $trasladosPendientes,
            'chips_traslados_pendientes' => $chipsPendientes,
        ]);
    }
}
