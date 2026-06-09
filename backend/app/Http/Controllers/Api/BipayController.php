<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BipayController extends Controller
{
    private function tablasFaltantes(): array
    {
        $faltantes = [];
        foreach (['cuentas_bipay', 'transacciones_bipay'] as $tabla) {
            if (!Schema::hasTable($tabla)) {
                $faltantes[] = $tabla;
            }
        }
        return $faltantes;
    }

    public function saldo(): JsonResponse
    {
        $faltantes = $this->tablasFaltantes();
        if (!empty($faltantes)) {
            return response()->json([
                'warning' => 'Tablas Bipay no existen: ' . implode(', ', $faltantes),
                'cuentas' => [],
                'kpis'    => ['total_bipay' => 0, 'total_anypay' => 0, 'total_saldo' => 0],
            ]);
        }

        $cuentas = DB::table('cuentas_bipay')
            ->select('id', 'alias', 'numero_cuenta', 'tipo', 'saldo_actual', 'saldo_bipay', 'saldo_anypay')
            ->orderBy('tipo')
            ->orderBy('alias')
            ->get();

        $kpis = DB::table('cuentas_bipay')
            ->selectRaw('
                COALESCE(SUM(saldo_bipay), 0)  AS total_bipay,
                COALESCE(SUM(saldo_anypay), 0) AS total_anypay,
                COALESCE(SUM(saldo_actual), 0) AS total_saldo
            ')
            ->first();

        return response()->json([
            'cuentas' => $cuentas,
            'kpis'    => $kpis,
        ]);
    }

    public function transacciones(Request $request): JsonResponse
    {
        $faltantes = $this->tablasFaltantes();
        if (!empty($faltantes)) {
            return response()->json([
                'warning' => 'Tablas Bipay no existen.',
                'data'    => [],
                'total'   => 0,
            ]);
        }

        $desde    = $request->get('fecha_desde', now()->startOfMonth()->toDateString());
        $hasta    = $request->get('fecha_hasta', now()->toDateString());
        $cuentaId = $request->get('cuenta_id');

        $query = DB::table('transacciones_bipay as tb')
            ->leftJoin('cuentas_bipay as co', 'co.id', '=', 'tb.cuenta_origen_id')
            ->leftJoin('cuentas_bipay as cd', 'cd.id', '=', 'tb.cuenta_destino_id')
            ->whereRaw('DATE(tb.created_at) BETWEEN ? AND ?', [$desde, $hasta])
            ->select([
                'tb.*',
                'co.alias AS origen_alias',
                'cd.alias AS destino_alias',
            ]);

        if ($cuentaId) {
            $query->where(fn($q) => $q->where('tb.cuenta_origen_id', $cuentaId)
                ->orWhere('tb.cuenta_destino_id', $cuentaId));
        }

        $data = $query->orderByDesc('tb.created_at')->paginate($request->integer('per_page', 20));

        return response()->json($data);
    }

    public function recarga(Request $request): JsonResponse
    {
        if (!empty($this->tablasFaltantes())) {
            return response()->json(['error' => 'Tablas Bipay no configuradas.'], 422);
        }

        $data = $request->validate([
            'cuenta_id'    => ['required', 'integer'],
            'monto_bipay'  => ['required', 'numeric', 'min:0'],
            'monto_anypay' => ['required', 'numeric', 'min:0'],
            'referencia'   => ['nullable', 'string', 'max:100'],
        ]);

        if ($data['monto_bipay'] <= 0 && $data['monto_anypay'] <= 0) {
            return response()->json(['error' => 'Al menos un monto debe ser mayor a 0.'], 422);
        }

        DB::beginTransaction();
        try {
            $cuenta = DB::table('cuentas_bipay')->lockForUpdate()->find($data['cuenta_id']);
            if (!$cuenta) {
                return response()->json(['error' => 'Cuenta no encontrada.'], 404);
            }

            $nuevoBipay  = $cuenta->saldo_bipay  + $data['monto_bipay'];
            $nuevoAnypay = $cuenta->saldo_anypay + $data['monto_anypay'];
            $nuevoTotal  = $nuevoBipay + $nuevoAnypay;
            $monto       = $data['monto_bipay'] + $data['monto_anypay'];
            $plataforma  = ($data['monto_bipay'] > 0 && $data['monto_anypay'] > 0) ? 'AMBOS' : ($data['monto_bipay'] > 0 ? 'BIPAY' : 'ANYPAY');

            DB::table('cuentas_bipay')
                ->where('id', $data['cuenta_id'])
                ->update(['saldo_bipay' => $nuevoBipay, 'saldo_anypay' => $nuevoAnypay, 'saldo_actual' => $nuevoTotal]);

            DB::table('transacciones_bipay')->insert([
                'cuenta_origen_id' => $data['cuenta_id'],
                'tipo_operacion'   => 'RECARGA',
                'plataforma'       => $plataforma,
                'monto'            => $monto,
                'saldo_origen_pre' => $cuenta->saldo_bipay,
                'saldo_anypay_pre' => $cuenta->saldo_anypay,
                'observacion'      => $data['referencia'] ?? null,
                'creado_por'       => auth()->id(),
                'created_at'       => now(),
            ]);

            DB::commit();
            return response()->json(['message' => 'Recarga registrada. Nuevo saldo: S/ ' . number_format($nuevoTotal, 2)]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
