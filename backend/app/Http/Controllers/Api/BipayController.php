<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

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
            ->whereRaw('DATE(tb.creado_en) BETWEEN ? AND ?', [$desde, $hasta])
            ->select([
                'tb.*',
                'tb.creado_en AS created_at',
                'co.alias AS origen_alias',
                'cd.alias AS destino_alias',
            ]);

        if ($cuentaId) {
            $query->where(fn($q) => $q->where('tb.cuenta_origen_id', $cuentaId)
                ->orWhere('tb.cuenta_destino_id', $cuentaId));
        }

        $data = $query->orderByDesc('tb.creado_en')->paginate($request->integer('per_page', 20));

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

            $this->insertarTransaccion([
                'cuenta_origen_id' => $data['cuenta_id'],
                'tipo_operacion'   => 'RECARGA',
                'plataforma'       => $plataforma,
                'monto'            => $monto,
                'saldo_origen_pre' => $cuenta->saldo_bipay,
                'saldo_anypay_pre' => $cuenta->saldo_anypay,
                'observacion'      => $data['referencia'] ?? null,
                'creado_por'       => auth()->id(),
            ]);

            DB::commit();
            return response()->json(['message' => 'Recarga registrada. Nuevo saldo: S/ ' . number_format($nuevoTotal, 2)]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── POST /bipay/transferir — Transferencia entre cuentas (admin, D3) ─────────
    public function transferir(Request $request): JsonResponse
    {
        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        if (! empty($this->tablasFaltantes())) {
            return response()->json(['error' => 'Tablas Bipay no configuradas.'], 422);
        }

        $data = $request->validate([
            'cuenta_origen_id'  => ['required', 'integer', 'different:cuenta_destino_id'],
            'cuenta_destino_id' => ['required', 'integer'],
            'monto'             => ['required', 'numeric', 'min:0.01'],
            'observacion'       => ['nullable', 'string', 'max:200'],
        ]);

        $res = DB::transaction(function () use ($data) {
            $origen  = DB::table('cuentas_bipay')->where('id', $data['cuenta_origen_id'])->lockForUpdate()->first();
            $destino = DB::table('cuentas_bipay')->where('id', $data['cuenta_destino_id'])->lockForUpdate()->first();
            if (! $origen || ! $destino) {
                return ['error' => 'Cuenta de origen o destino no encontrada.', 'status' => 404];
            }
            $monto = (float) $data['monto'];
            if ((float) $origen->saldo_actual < $monto) {
                return ['error' => 'Saldo insuficiente en la cuenta de origen.', 'status' => 422];
            }

            // Resta del origen: primero de bipay, el resto de anypay (ninguno negativo).
            $oBipay = (float) $origen->saldo_bipay;
            $oAnypay = (float) $origen->saldo_anypay;
            $deBipay = min($oBipay, $monto);
            $nuevoOBipay = $oBipay - $deBipay;
            $nuevoOAnypay = $oAnypay - ($monto - $deBipay);

            DB::table('cuentas_bipay')->where('id', $origen->id)->update([
                'saldo_bipay'  => $nuevoOBipay,
                'saldo_anypay' => $nuevoOAnypay,
                'saldo_actual' => $nuevoOBipay + $nuevoOAnypay,
            ]);

            $nuevoDBipay = (float) $destino->saldo_bipay + $monto;
            DB::table('cuentas_bipay')->where('id', $destino->id)->update([
                'saldo_bipay'  => $nuevoDBipay,
                'saldo_actual' => $nuevoDBipay + (float) $destino->saldo_anypay,
            ]);

            $this->insertarTransaccion([
                'cuenta_origen_id'  => $origen->id,
                'cuenta_destino_id' => $destino->id,
                'tipo_operacion'    => 'TRANSFERENCIA',
                'plataforma'        => 'AMBOS',
                'monto'             => $monto,
                'saldo_origen_pre'  => $oBipay,
                'saldo_anypay_pre'  => $oAnypay,
                'observacion'       => $data['observacion'] ?? null,
                'creado_por'        => auth()->id(),
            ]);

            return ['ok' => true, 'origen' => $origen->alias, 'destino' => $destino->alias, 'monto' => $monto];
        });

        if (isset($res['error'])) {
            return response()->json(['success' => false, 'message' => $res['error']], $res['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Transferencia de S/ ' . number_format($res['monto'], 2) . " de {$res['origen']} a {$res['destino']}.",
        ]);
    }

    // ── POST /bipay/ajustar — Ajuste manual de saldo con motivo (admin, D3) ──────
    public function ajustar(Request $request): JsonResponse
    {
        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        if (! empty($this->tablasFaltantes())) {
            return response()->json(['error' => 'Tablas Bipay no configuradas.'], 422);
        }

        $data = $request->validate([
            'cuenta_id'    => ['required', 'integer'],
            'saldo_bipay'  => ['required', 'numeric', 'min:0'],
            'saldo_anypay' => ['required', 'numeric', 'min:0'],
            'motivo'       => ['required', 'string', 'min:5', 'max:200'],
        ]);

        $res = DB::transaction(function () use ($data) {
            $cuenta = DB::table('cuentas_bipay')->where('id', $data['cuenta_id'])->lockForUpdate()->first();
            if (! $cuenta) {
                return ['error' => 'Cuenta no encontrada.', 'status' => 404];
            }
            $nuevoBipay = (float) $data['saldo_bipay'];
            $nuevoAnypay = (float) $data['saldo_anypay'];
            $nuevoTotal = $nuevoBipay + $nuevoAnypay;
            $diferencia = round($nuevoTotal - (float) $cuenta->saldo_actual, 2);

            DB::table('cuentas_bipay')->where('id', $cuenta->id)->update([
                'saldo_bipay'  => $nuevoBipay,
                'saldo_anypay' => $nuevoAnypay,
                'saldo_actual' => $nuevoTotal,
            ]);

            $this->insertarTransaccion([
                'cuenta_origen_id' => $cuenta->id,
                'tipo_operacion'   => 'AJUSTE',
                'plataforma'       => 'AMBOS',
                'monto'            => $diferencia,
                'saldo_origen_pre' => (float) $cuenta->saldo_bipay,
                'saldo_anypay_pre' => (float) $cuenta->saldo_anypay,
                'observacion'      => $data['motivo'],
                'creado_por'       => auth()->id(),
            ]);

            return ['ok' => true, 'diferencia' => $diferencia];
        });

        if (isset($res['error'])) {
            return response()->json(['success' => false, 'message' => $res['error']], $res['status']);
        }

        return response()->json([
            'success'    => true,
            'message'    => 'Saldo ajustado correctamente.',
            'diferencia' => $res['diferencia'],
        ]);
    }

    public function estadoCajero(Request $request): JsonResponse
    {
        $contexto = $this->contextoCajero($request);
        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        $ahora = $this->ahoraCajero();
        $hoy = $ahora->toDateString();
        $cuentaId = (int) $contexto->id;
        $tiendaCodigo = (string) $contexto->tienda_codigo;

        $declaracion = DB::table('bipay_saldos_dia')
            ->where('tienda_codigo', $tiendaCodigo)
            ->where('cuenta_bipay_id', $cuentaId)
            ->where('fecha', $hoy)
            ->first();

        $cuentaLive = DB::table('cuentas_bipay')
            ->select('saldo_bipay', 'saldo_anypay')
            ->find($cuentaId);

        return response()->json([
            'ok' => true,
            'bipay_live' => (float) ($cuentaLive->saldo_bipay ?? 0),
            'anypay_live' => (float) ($cuentaLive->saldo_anypay ?? 0),
            'bipay_actual' => $declaracion?->saldo_bipay_actual !== null
                ? (float) $declaracion->saldo_bipay_actual
                : null,
            'anypay_actual' => $declaracion?->saldo_anypay_actual !== null
                ? (float) $declaracion->saldo_anypay_actual
                : null,
            'bipay_cierre' => $declaracion?->saldo_bipay_cierre !== null
                ? (float) $declaracion->saldo_bipay_cierre
                : null,
            'anypay_cierre' => $declaracion?->saldo_anypay_cierre !== null
                ? (float) $declaracion->saldo_anypay_cierre
                : null,
            'alerta' => (bool) ($declaracion?->alerta_enviada ?? false),
            'umbral' => (float) ($contexto->umbral_alerta ?? 0),
            'cooldown_segs' => $this->segundosCooldown($cuentaId, $tiendaCodigo, $ahora),
            'cerrado' => $declaracion !== null
                && ($declaracion->saldo_bipay_cierre !== null || $declaracion->saldo_anypay_cierre !== null),
            'tiendas_estado' => $this->tiendasEstado($cuentaId, $hoy, $ahora),
        ]);
    }

    public function actualizarCajero(Request $request): JsonResponse
    {
        $contexto = $this->contextoCajero($request);
        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        $tieneBipay = $request->has('saldo_bipay') && $request->input('saldo_bipay') !== '';
        $tieneAnypay = $request->has('saldo_anypay') && $request->input('saldo_anypay') !== '';

        if (! $tieneBipay && ! $tieneAnypay) {
            return response()->json([
                'ok' => false,
                'msg' => 'Ingresa al menos el saldo de Bipay o Anypay.',
            ], 422);
        }

        $validator = Validator::make($request->only(['saldo_bipay', 'saldo_anypay']), [
            'saldo_bipay' => ['sometimes', 'numeric', 'min:0'],
            'saldo_anypay' => ['sometimes', 'numeric', 'min:0'],
        ], [
            'saldo_bipay.numeric' => 'Saldo Bipay debe ser numérico.',
            'saldo_bipay.min' => 'Saldo Bipay debe ser mayor o igual a 0.',
            'saldo_anypay.numeric' => 'Saldo Anypay debe ser numérico.',
            'saldo_anypay.min' => 'Saldo Anypay debe ser mayor o igual a 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'msg' => $validator->errors()->first(),
            ], 422);
        }

        $ahora = $this->ahoraCajero();
        $hoy = $ahora->toDateString();
        $cuentaId = (int) $contexto->id;
        $tiendaCodigo = (string) $contexto->tienda_codigo;
        $nuevoBipay = $tieneBipay ? (float) $request->input('saldo_bipay') : null;
        $nuevoAnypay = $tieneAnypay ? (float) $request->input('saldo_anypay') : null;
        $umbral = (float) ($contexto->umbral_alerta ?? 0);

        try {
            $resultado = DB::transaction(function () use (
                $ahora,
                $hoy,
                $cuentaId,
                $tiendaCodigo,
                $nuevoBipay,
                $nuevoAnypay,
                $umbral,
                $request
            ): array {
                DB::table('cuentas_bipay')->where('id', $cuentaId)->lockForUpdate()->first();

                $cooldown = DB::table('bipay_cooldowns')
                    ->where('cuenta_bipay_id', $cuentaId)
                    ->where('tienda_codigo', $tiendaCodigo)
                    ->lockForUpdate()
                    ->first();

                $cooldownSegs = $this->segundosRestantes($cooldown?->cooldown_hasta, $ahora);
                if ($cooldownSegs > 0) {
                    $minutos = (int) ceil($cooldownSegs / 60);

                    return [
                        'ok' => false,
                        'cooldown' => true,
                        'cooldown_segs' => $cooldownSegs,
                        'msg' => "Debes esperar {$minutos} min antes de tu próximo tramo.",
                    ];
                }

                $declaracion = DB::table('bipay_saldos_dia')
                    ->where('tienda_codigo', $tiendaCodigo)
                    ->where('cuenta_bipay_id', $cuentaId)
                    ->where('fecha', $hoy)
                    ->lockForUpdate()
                    ->first();

                $bipayPrevio = (float) ($declaracion?->saldo_bipay_actual ?? 0);
                $anypayPrevio = (float) ($declaracion?->saldo_anypay_actual ?? 0);
                $bipayFinal = $nuevoBipay ?? $bipayPrevio;
                $anypayFinal = $nuevoAnypay ?? $anypayPrevio;
                $saldoFinal = $bipayFinal + $anypayFinal;
                $esAlerta = $umbral > 0 && $saldoFinal <= $umbral;

                DB::table('bipay_saldos_dia')->updateOrInsert(
                    [
                        'tienda_codigo' => $tiendaCodigo,
                        'cuenta_bipay_id' => $cuentaId,
                        'fecha' => $hoy,
                    ],
                    [
                        'saldo_bipay_actual' => $bipayFinal,
                        'saldo_anypay_actual' => $anypayFinal,
                        'saldo_actual' => $saldoFinal,
                        'alerta_enviada' => $esAlerta,
                        'actualizado_en' => $ahora,
                    ]
                );

                DB::table('cuentas_bipay')
                    ->where('id', $cuentaId)
                    ->update([
                        'saldo_bipay' => $bipayFinal,
                        'saldo_anypay' => $anypayFinal,
                        'saldo_actual' => $saldoFinal,
                    ]);

                $this->insertarTransaccion([
                    'cuenta_origen_id' => $cuentaId,
                    'tipo_operacion' => 'DECLARACION_DIA',
                    'plataforma' => 'AMBOS',
                    'tienda_codigo' => $tiendaCodigo,
                    'monto' => $saldoFinal - ($bipayPrevio + $anypayPrevio),
                    'saldo_origen_pre' => $bipayFinal,
                    'saldo_anypay_pre' => $anypayFinal,
                    'observacion' => 'Tramo tienda '.$tiendaCodigo,
                    'creado_por' => $request->user()->id,
                ], $ahora);

                DB::table('bipay_cooldowns')->updateOrInsert(
                    [
                        'cuenta_bipay_id' => $cuentaId,
                        'tienda_codigo' => $tiendaCodigo,
                    ],
                    [
                        'cooldown_hasta' => $ahora->copy()->addMinutes(4),
                        'actualizado_en' => $ahora,
                    ]
                );

                $otrasTiendas = DB::table('tiendas')
                    ->where('cuenta_bipay_id', $cuentaId)
                    ->where('codigo', '!=', $tiendaCodigo)
                    ->pluck('codigo');

                foreach ($otrasTiendas as $otraTienda) {
                    $cooldownOtra = DB::table('bipay_cooldowns')
                        ->where('cuenta_bipay_id', $cuentaId)
                        ->where('tienda_codigo', $otraTienda)
                        ->lockForUpdate()
                        ->first();

                    if ($this->segundosRestantes($cooldownOtra?->cooldown_hasta, $ahora) > 0) {
                        continue;
                    }

                    DB::table('bipay_cooldowns')->updateOrInsert(
                        [
                            'cuenta_bipay_id' => $cuentaId,
                            'tienda_codigo' => $otraTienda,
                        ],
                        [
                            'cooldown_hasta' => $ahora->copy()->addMinutes(random_int(1, 3)),
                            'actualizado_en' => $ahora,
                        ]
                    );
                }

                return [
                    'ok' => true,
                    'bipay_actual' => $nuevoBipay !== null ? $bipayFinal : null,
                    'anypay_actual' => $nuevoAnypay !== null ? $anypayFinal : null,
                    'bipay_live' => $bipayFinal,
                    'anypay_live' => $anypayFinal,
                    'alerta' => $esAlerta,
                    'umbral' => $umbral,
                    'cooldown_segs' => 240,
                ];
            });

            return response()->json($resultado, ($resultado['cooldown'] ?? false) ? 409 : 200);
        } catch (\Throwable $e) {
            Log::error('Error al actualizar saldo Bipay/Anypay de tienda.', [
                'tienda' => $tiendaCodigo,
                'cuenta_bipay_id' => $cuentaId,
                'exception' => $e,
            ]);

            return response()->json([
                'ok' => false,
                'msg' => 'Error BD al actualizar saldo.',
            ], 500);
        }
    }

    public function cierreCajero(Request $request): JsonResponse
    {
        $contexto = $this->contextoCajero($request);
        if ($contexto instanceof JsonResponse) {
            return $contexto;
        }

        $tieneBipay = $request->has('saldo_bipay_cierre') && $request->input('saldo_bipay_cierre') !== '';
        $tieneAnypay = $request->has('saldo_anypay_cierre') && $request->input('saldo_anypay_cierre') !== '';

        if (! $tieneBipay && ! $tieneAnypay) {
            return response()->json([
                'ok' => false,
                'msg' => 'Ingresa al menos un saldo de cierre (Bipay o Anypay).',
            ], 422);
        }

        $validator = Validator::make($request->only([
            'saldo_bipay_cierre',
            'saldo_anypay_cierre',
        ]), [
            'saldo_bipay_cierre' => ['sometimes', 'numeric', 'min:0'],
            'saldo_anypay_cierre' => ['sometimes', 'numeric', 'min:0'],
        ], [
            'saldo_bipay_cierre.numeric' => 'Cierre Bipay debe ser numérico.',
            'saldo_bipay_cierre.min' => 'Cierre Bipay debe ser mayor o igual a 0.',
            'saldo_anypay_cierre.numeric' => 'Cierre Anypay debe ser numérico.',
            'saldo_anypay_cierre.min' => 'Cierre Anypay debe ser mayor o igual a 0.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'msg' => $validator->errors()->first(),
            ], 422);
        }

        $ahora = $this->ahoraCajero();
        $hoy = $ahora->toDateString();
        $cuentaId = (int) $contexto->id;
        $tiendaCodigo = (string) $contexto->tienda_codigo;
        $cierreBipay = $tieneBipay ? (float) $request->input('saldo_bipay_cierre') : null;
        $cierreAnypay = $tieneAnypay ? (float) $request->input('saldo_anypay_cierre') : null;

        try {
            $resultado = DB::transaction(function () use (
                $ahora,
                $hoy,
                $cuentaId,
                $tiendaCodigo,
                $cierreBipay,
                $cierreAnypay,
                $request
            ): array {
                DB::table('cuentas_bipay')->where('id', $cuentaId)->lockForUpdate()->first();

                $declaracion = DB::table('bipay_saldos_dia')
                    ->where('tienda_codigo', $tiendaCodigo)
                    ->where('cuenta_bipay_id', $cuentaId)
                    ->where('fecha', $hoy)
                    ->lockForUpdate()
                    ->first();

                if (! $declaracion) {
                    return [
                        'ok' => false,
                        'msg' => 'No hay tramo registrado hoy; actualiza al menos un tramo antes de cerrar.',
                        'status' => 422,
                    ];
                }

                if ($declaracion->saldo_bipay_cierre !== null || $declaracion->saldo_anypay_cierre !== null) {
                    return [
                        'ok' => false,
                        'msg' => 'La jornada ya fue cerrada.',
                        'status' => 409,
                    ];
                }

                $bipayFinal = $cierreBipay ?? (float) ($declaracion->saldo_bipay_actual ?? 0);
                $anypayFinal = $cierreAnypay ?? (float) ($declaracion->saldo_anypay_actual ?? 0);
                $saldoFinal = $bipayFinal + $anypayFinal;

                DB::table('bipay_saldos_dia')
                    ->where('id', $declaracion->id)
                    ->update([
                        'saldo_bipay_cierre' => $bipayFinal,
                        'saldo_anypay_cierre' => $anypayFinal,
                        'saldo_cierre' => $saldoFinal,
                        'saldo_actual' => $saldoFinal,
                        'actualizado_en' => $ahora,
                    ]);

                DB::table('cuentas_bipay')
                    ->where('id', $cuentaId)
                    ->update([
                        'saldo_bipay' => $bipayFinal,
                        'saldo_anypay' => $anypayFinal,
                        'saldo_actual' => $saldoFinal,
                    ]);

                DB::table('bipay_cooldowns')
                    ->where('cuenta_bipay_id', $cuentaId)
                    ->where('tienda_codigo', $tiendaCodigo)
                    ->delete();

                $this->insertarTransaccion([
                    'cuenta_origen_id' => $cuentaId,
                    'tipo_operacion' => 'CIERRE_DIA',
                    'plataforma' => 'AMBOS',
                    'tienda_codigo' => $tiendaCodigo,
                    'monto' => $saldoFinal,
                    'saldo_origen_pre' => $bipayFinal,
                    'saldo_anypay_pre' => $anypayFinal,
                    'observacion' => 'Cierre tienda '.$tiendaCodigo,
                    'creado_por' => $request->user()->id,
                ], $ahora);

                return [
                    'ok' => true,
                    'bipay_cierre' => $bipayFinal,
                    'anypay_cierre' => $anypayFinal,
                ];
            });

            $status = (int) ($resultado['status'] ?? 200);
            unset($resultado['status']);

            return response()->json($resultado, $status);
        } catch (\Throwable $e) {
            Log::error('Error al cerrar saldo Bipay/Anypay de tienda.', [
                'tienda' => $tiendaCodigo,
                'cuenta_bipay_id' => $cuentaId,
                'exception' => $e,
            ]);

            return response()->json([
                'ok' => false,
                'msg' => 'Error BD al registrar cierre.',
            ], 500);
        }
    }

    private function contextoCajero(Request $request): mixed
    {
        if ($request->user()->rol !== 'tienda') {
            return response()->json([
                'ok' => false,
                'msg' => 'Solo usuarios de tienda pueden usar la consola Bipay/Anypay.',
            ], 403);
        }

        $faltantes = [];
        foreach ([
            'tiendas',
            'cuentas_bipay',
            'bipay_saldos_dia',
            'bipay_cooldowns',
            'transacciones_bipay',
        ] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                $faltantes[] = $tabla;
            }
        }

        if ($faltantes !== []) {
            return response()->json([
                'ok' => false,
                'msg' => 'Tablas Bipay no configuradas: '.implode(', ', $faltantes),
            ], 422);
        }

        $cuenta = DB::table('tiendas as t')
            ->join('cuentas_bipay as cb', 'cb.id', '=', 't.cuenta_bipay_id')
            ->where('t.codigo', $request->user()->tienda_id)
            ->whereNotNull('t.cuenta_bipay_id')
            ->select('cb.*', 't.codigo as tienda_codigo', 't.nombre as tienda_nombre')
            ->first();

        if (! $cuenta) {
            return response()->json([
                'ok' => false,
                'msg' => 'Esta tienda no tiene Razón Social Bipay/Anypay asignada.',
            ], 422);
        }

        return $cuenta;
    }

    private function ahoraCajero(): Carbon
    {
        return now(config('reportes.timezone', 'America/Lima'));
    }

    private function segundosCooldown(int $cuentaId, string $tiendaCodigo, Carbon $ahora): int
    {
        $cooldownHasta = DB::table('bipay_cooldowns')
            ->where('cuenta_bipay_id', $cuentaId)
            ->where('tienda_codigo', $tiendaCodigo)
            ->value('cooldown_hasta');

        return $this->segundosRestantes($cooldownHasta, $ahora);
    }

    private function segundosRestantes(mixed $cooldownHasta, Carbon $ahora): int
    {
        if (! $cooldownHasta) {
            return 0;
        }

        $hasta = Carbon::parse((string) $cooldownHasta, $ahora->timezone);

        return max(0, $hasta->getTimestamp() - $ahora->getTimestamp());
    }

    private function tiendasEstado(int $cuentaId, string $hoy, Carbon $ahora): array
    {
        return DB::table('tiendas as t')
            ->leftJoin('bipay_cooldowns as bc', function ($join) use ($cuentaId) {
                $join->on('bc.tienda_codigo', '=', 't.codigo')
                    ->where('bc.cuenta_bipay_id', '=', $cuentaId);
            })
            ->leftJoin('bipay_saldos_dia as sd', function ($join) use ($cuentaId, $hoy) {
                $join->on('sd.tienda_codigo', '=', 't.codigo')
                    ->where('sd.cuenta_bipay_id', '=', $cuentaId)
                    ->where('sd.fecha', '=', $hoy);
            })
            ->where('t.cuenta_bipay_id', $cuentaId)
            ->select([
                't.codigo',
                't.nombre',
                'bc.cooldown_hasta',
                'bc.actualizado_en',
                'sd.saldo_bipay_actual',
                'sd.saldo_anypay_actual',
            ])
            ->get()
            ->map(function ($tienda) use ($ahora): array {
                $actualizadoEn = $tienda->actualizado_en
                    ? Carbon::parse((string) $tienda->actualizado_en, $ahora->timezone)
                    : null;

                return [
                    'codigo' => $tienda->codigo,
                    'nombre' => $tienda->nombre,
                    'cooldown_segs' => $this->segundosRestantes($tienda->cooldown_hasta, $ahora),
                    'segs_desde_actualizacion' => $actualizadoEn
                        ? max(0, $ahora->getTimestamp() - $actualizadoEn->getTimestamp())
                        : null,
                    'saldo_bipay_actual' => $tienda->saldo_bipay_actual !== null
                        ? (float) $tienda->saldo_bipay_actual
                        : null,
                    'saldo_anypay_actual' => $tienda->saldo_anypay_actual !== null
                        ? (float) $tienda->saldo_anypay_actual
                        : null,
                ];
            })
            ->sort(function (array $a, array $b): int {
                return $b['cooldown_segs'] <=> $a['cooldown_segs']
                    ?: strcmp($a['nombre'], $b['nombre']);
            })
            ->values()
            ->all();
    }

    // ── POST /bipay/cuentas — Crear cuenta Madre/Hijo (admin) ───────────────────
    // Paridad legacy gerencia/panel_bipay.php (acción nueva_cuenta).
    public function crearCuenta(Request $request): JsonResponse
    {
        if ($request->user()->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }
        if (! Schema::hasTable('cuentas_bipay')) {
            return response()->json(['success' => false, 'message' => 'Tabla Bipay no configurada.'], 422);
        }

        $data = $request->validate([
            'alias'           => ['required', 'string', 'max:80'],
            'tipo'            => ['required', 'in:MADRE,HIJO'],
            'numero_cuenta'   => ['required', 'string', 'max:60'],
            'nombre_titular'  => ['nullable', 'string', 'max:100'],
            'cuenta_madre_id' => ['nullable', 'integer', 'required_if:tipo,HIJO'],
            'saldo_bipay'     => ['nullable', 'numeric', 'min:0'],
            'saldo_anypay'    => ['nullable', 'numeric', 'min:0'],
            'umbral_alerta'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $saldoBipay  = round((float) ($data['saldo_bipay'] ?? 0), 2);
        $saldoAnypay = round((float) ($data['saldo_anypay'] ?? 0), 2);

        $insert = [
            'alias'         => $data['alias'],
            'tipo'          => $data['tipo'],
            'numero_cuenta' => $data['numero_cuenta'],
            'saldo_bipay'   => $saldoBipay,
            'saldo_anypay'  => $saldoAnypay,
            'saldo_actual'  => $saldoBipay + $saldoAnypay,
        ];
        if (Schema::hasColumn('cuentas_bipay', 'nombre_titular'))  $insert['nombre_titular']  = $data['nombre_titular'] ?? null;
        if (Schema::hasColumn('cuentas_bipay', 'cuenta_madre_id')) $insert['cuenta_madre_id'] = $data['tipo'] === 'HIJO' ? ($data['cuenta_madre_id'] ?: null) : null;
        if (Schema::hasColumn('cuentas_bipay', 'umbral_alerta'))   $insert['umbral_alerta']   = round((float) ($data['umbral_alerta'] ?? 0), 2);

        $id = DB::table('cuentas_bipay')->insertGetId($insert);

        return response()->json(['success' => true, 'message' => "Cuenta \"{$data['alias']}\" creada.", 'id' => $id], 201);
    }

    // ── PUT /bipay/cuentas/{id} — Editar cuenta (admin) ─────────────────────────
    public function editarCuenta(Request $request, int $id): JsonResponse
    {
        if ($request->user()->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }
        if (! Schema::hasTable('cuentas_bipay')) {
            return response()->json(['success' => false, 'message' => 'Tabla Bipay no configurada.'], 422);
        }

        $data = $request->validate([
            'alias'           => ['required', 'string', 'max:80'],
            'tipo'            => ['required', 'in:MADRE,HIJO'],
            'numero_cuenta'   => ['required', 'string', 'max:60'],
            'nombre_titular'  => ['nullable', 'string', 'max:100'],
            'cuenta_madre_id' => ['nullable', 'integer', 'required_if:tipo,HIJO'],
            'umbral_alerta'   => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! DB::table('cuentas_bipay')->where('id', $id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Cuenta no encontrada.'], 404);
        }

        // No se tocan saldos aquí (eso es recarga/ajuste); solo datos de la cuenta.
        $update = [
            'alias'         => $data['alias'],
            'tipo'          => $data['tipo'],
            'numero_cuenta' => $data['numero_cuenta'],
        ];
        if (Schema::hasColumn('cuentas_bipay', 'nombre_titular'))  $update['nombre_titular']  = $data['nombre_titular'] ?? null;
        if (Schema::hasColumn('cuentas_bipay', 'cuenta_madre_id')) $update['cuenta_madre_id'] = $data['tipo'] === 'HIJO' ? ($data['cuenta_madre_id'] ?: null) : null;
        if (Schema::hasColumn('cuentas_bipay', 'umbral_alerta'))   $update['umbral_alerta']   = round((float) ($data['umbral_alerta'] ?? 0), 2);

        DB::table('cuentas_bipay')->where('id', $id)->update($update);

        return response()->json(['success' => true, 'message' => "Cuenta \"{$data['alias']}\" actualizada."]);
    }

    // ── DELETE /bipay/cuentas/{id} — Eliminar cuenta (admin) ────────────────────
    public function eliminarCuenta(Request $request, int $id): JsonResponse
    {
        if ($request->user()->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }
        if (! Schema::hasTable('cuentas_bipay')) {
            return response()->json(['success' => false, 'message' => 'Tabla Bipay no configurada.'], 422);
        }

        // Bloquear borrado si tiene subcuentas o transacciones asociadas (integridad legacy).
        if (Schema::hasColumn('cuentas_bipay', 'cuenta_madre_id')
            && DB::table('cuentas_bipay')->where('cuenta_madre_id', $id)->exists()) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar: tiene subcuentas asociadas.'], 422);
        }
        if (Schema::hasTable('transacciones_bipay')
            && DB::table('transacciones_bipay')
                ->where('cuenta_origen_id', $id)->orWhere('cuenta_destino_id', $id)->exists()) {
            return response()->json(['success' => false, 'message' => 'No se puede eliminar: tiene transacciones asociadas.'], 422);
        }

        $deleted = DB::table('cuentas_bipay')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Cuenta no encontrada.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Cuenta eliminada.']);
    }

    private function insertarTransaccion(array $data, mixed $fecha = null): void
    {
        $columnaFecha = Schema::hasColumn('transacciones_bipay', 'creado_en')
            ? 'creado_en'
            : 'created_at';
        $data[$columnaFecha] = $fecha ?? now();

        DB::table('transacciones_bipay')->insert($data);
    }
}
