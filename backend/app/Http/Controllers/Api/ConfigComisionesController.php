<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de comisiones operativas (paridad legacy):
 *  - guardar_tarifas_ajax.php   → tarifas simples en config_comisiones
 *  - configurar_comisiones.php  → rangos PLAN/EQUIPO en config_comisiones
 *  - guardar_rangos_ajax.php    → rangos bipay/krece/payjoy en comisiones_rangos
 */
class ConfigComisionesController extends Controller
{
    private const TARIFAS = ['ganancia_recargas', 'ganancia_bipay', 'ganancia_krece', 'ganancia_payjoy'];
    private const SERVICIOS = ['bipay', 'krece', 'payjoy'];

    // ── GET /config-comisiones — Lee tarifas + rangos PLAN/EQUIPO + rangos servicio ──
    public function index(): JsonResponse
    {
        $tarifas = array_fill_keys(self::TARIFAS, 0.0);
        $plan = [];
        $equipo = [];
        $rangosServicio = array_fill_keys(self::SERVICIOS, []);

        if (Schema::hasTable('config_comisiones')) {
            $rows = DB::table('config_comisiones')->get();
            foreach ($rows as $r) {
                if (in_array($r->tipo, self::TARIFAS, true)) {
                    $tarifas[$r->tipo] = (float) $r->monto;
                } elseif ($r->tipo === 'PLAN') {
                    $plan[] = ['desde' => (int) $r->rango_desde, 'hasta' => (int) $r->rango_hasta, 'monto' => (float) $r->monto];
                } elseif ($r->tipo === 'EQUIPO') {
                    $equipo[] = ['desde' => (int) $r->rango_desde, 'hasta' => (int) $r->rango_hasta, 'monto' => (float) $r->monto];
                }
            }
        }

        if (Schema::hasTable('comisiones_rangos')) {
            foreach (DB::table('comisiones_rangos')->orderBy('monto_min')->get() as $r) {
                if (isset($rangosServicio[$r->tipo_servicio])) {
                    $rangosServicio[$r->tipo_servicio][] = [
                        'monto_min' => (float) $r->monto_min,
                        'monto_max' => $r->monto_max !== null ? (float) $r->monto_max : null,
                        'ganancia'  => (float) $r->ganancia,
                    ];
                }
            }
        }

        return response()->json([
            'tarifas'         => $tarifas,
            'rangos_plan'     => $plan,
            'rangos_equipo'   => $equipo,
            'rangos_servicio' => $rangosServicio,
        ]);
    }

    // ── PUT /config-comisiones/tarifas — % recargas + montos bipay/krece/payjoy ──
    public function guardarTarifas(Request $request): JsonResponse
    {
        if ($err = $this->guardAdmin('config_comisiones')) return $err;

        $data = $request->validate([
            'ganancia_recargas' => ['required', 'numeric', 'between:0,100'],
            'ganancia_bipay'    => ['required', 'numeric', 'min:0'],
            'ganancia_krece'    => ['required', 'numeric', 'min:0'],
            'ganancia_payjoy'   => ['required', 'numeric', 'min:0'],
        ]);

        foreach (self::TARIFAS as $tipo) {
            $valor = round((float) $data[$tipo], 2);
            $existe = DB::table('config_comisiones')->where('tipo', $tipo)->exists();
            if ($existe) {
                DB::table('config_comisiones')->where('tipo', $tipo)->update(['monto' => $valor]);
            } else {
                DB::table('config_comisiones')->insert(['tipo' => $tipo, 'monto' => $valor]);
            }
        }

        return response()->json([
            'success' => true,
            'msg'     => 'Tarifas guardadas. Los reportes existentes NO se modificaron.',
        ]);
    }

    // ── PUT /config-comisiones/rangos-productividad — Rangos PLAN y/o EQUIPO ─────
    public function guardarRangosProductividad(Request $request): JsonResponse
    {
        if ($err = $this->guardAdmin('config_comisiones')) return $err;

        $data = $request->validate([
            'tipo'           => ['required', 'in:PLAN,EQUIPO'],
            'rangos'         => ['present', 'array'],
            'rangos.*.desde' => ['required', 'integer', 'min:1'],
            'rangos.*.hasta' => ['required', 'integer', 'min:1'],
            'rangos.*.monto' => ['required', 'numeric', 'min:0'],
        ]);

        if ($error = $this->validarRangos($data['rangos'], 'desde', 'hasta')) {
            return response()->json(['success' => false, 'msg' => $error], 422);
        }

        DB::transaction(function () use ($data) {
            DB::table('config_comisiones')->where('tipo', $data['tipo'])->delete();
            foreach ($data['rangos'] as $r) {
                DB::table('config_comisiones')->insert([
                    'tipo'        => $data['tipo'],
                    'rango_desde' => (int) $r['desde'],
                    'rango_hasta' => (int) $r['hasta'],
                    'monto'       => round((float) $r['monto'], 2),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'msg'     => count($data['rangos']) . " rango(s) {$data['tipo']} guardados. Reportes históricos intactos.",
        ]);
    }

    // ── PUT /config-comisiones/rangos-servicio — Rangos bipay/krece/payjoy ───────
    public function guardarRangosServicio(Request $request): JsonResponse
    {
        if ($err = $this->guardAdmin('comisiones_rangos')) return $err;

        $data = $request->validate([
            'tipo_servicio'      => ['required', 'in:bipay,krece,payjoy'],
            'rangos'             => ['present', 'array'],
            'rangos.*.monto_min' => ['required', 'numeric', 'min:0'],
            'rangos.*.monto_max' => ['nullable', 'numeric', 'min:0'],
            'rangos.*.ganancia'  => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data['rangos'] as $i => $r) {
            if (isset($r['monto_max']) && $r['monto_max'] !== null && (float) $r['monto_max'] < (float) $r['monto_min']) {
                return response()->json(['success' => false, 'msg' => 'Rango #' . ($i + 1) . ': el máximo debe ser ≥ mínimo.'], 422);
            }
        }
        if ($error = $this->validarRangos($data['rangos'], 'monto_min', 'monto_max')) {
            return response()->json(['success' => false, 'msg' => $error], 422);
        }

        DB::transaction(function () use ($data) {
            DB::table('comisiones_rangos')->where('tipo_servicio', $data['tipo_servicio'])->delete();
            foreach ($data['rangos'] as $r) {
                DB::table('comisiones_rangos')->insert([
                    'tipo_servicio' => $data['tipo_servicio'],
                    'monto_min'     => round((float) $r['monto_min'], 2),
                    'monto_max'     => isset($r['monto_max']) && $r['monto_max'] !== null ? round((float) $r['monto_max'], 2) : null,
                    'ganancia'      => round((float) $r['ganancia'], 2),
                    'creado_en'     => now(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'msg'     => count($data['rangos']) . ' rango(s) guardados para ' . strtoupper($data['tipo_servicio']) . '.',
        ]);
    }

    private function guardAdmin(string $tabla): ?JsonResponse
    {
        // Matriz plan 16: "Comisiones (config planes)" es admin+gerente exclusivo.
        if (! Auth::user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE)) {
            return response()->json(['success' => false, 'msg' => 'Acceso denegado.'], 403);
        }
        if (! Schema::hasTable($tabla)) {
            return response()->json(['success' => false, 'msg' => "Tabla {$tabla} no disponible."], 503);
        }
        return null;
    }

    private function validarRangos(array $rangos, string $campoMin, string $campoMax): ?string
    {
        usort($rangos, fn ($a, $b) => (float) $a[$campoMin] <=> (float) $b[$campoMin]);
        $finAnterior = null;

        foreach ($rangos as $i => $rango) {
            $min = (float) $rango[$campoMin];
            $max = $rango[$campoMax] ?? null;
            if ($max !== null && (float) $max < $min) {
                return 'Rango #'.($i + 1).': el máximo debe ser mayor o igual al mínimo.';
            }
            if ($finAnterior === null && $i > 0) {
                return 'Un rango sin límite superior debe ser el último.';
            }
            if ($finAnterior !== null && $min <= $finAnterior) {
                return 'Los rangos no pueden solaparse.';
            }
            $finAnterior = $max === null ? null : (float) $max;
        }

        return null;
    }
}
