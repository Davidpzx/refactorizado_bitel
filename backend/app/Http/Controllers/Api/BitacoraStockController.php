<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BitacoraStockController extends Controller
{
    /** Query base con todos los filtros (fecha, tienda, acción, agente, categoría, búsqueda). Compartida por index() y exportar(). */
    private function baseQuery(Request $request)
    {
        $user = $request->user();

        $base = DB::table('historial_inventario as h')
            ->leftJoin('agentes as a', 'h.agente_id', '=', 'a.id')
            ->leftJoin('inventario_tiendas as it', 'h.producto_id', '=', 'it.id')
            ->leftJoin('tiendas as t', 'h.tienda_id', '=', 't.id')
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('h.fecha_hora', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('h.fecha_hora', '<=', $f))
            ->when($request->accion,      fn($q, $v) => $q->where('h.accion', $v))
            ->when($request->agente_id,   fn($q, $v) => $q->where('h.agente_id', $v))
            ->when($request->categoria,   fn($q, $v) => $q->where('it.tipo', strtoupper($v)))
            ->when($request->filled('q'), function ($q) use ($request) {
                $texto = '%'.trim((string) $request->input('q')).'%';
                $q->where(function ($qq) use ($texto) {
                    $qq->where('it.producto_nombre', 'like', $texto)
                        ->orWhere('h.observacion', 'like', $texto)
                        ->orWhere('h.imei_serial', 'like', $texto)
                        ->orWhere('a.nombres', 'like', $texto);
                });
            });

        if ($user->rol !== 'admin') {
            // $user->tienda_id es el código string (e.g. 'PUNDA50') → resolvemos a int.
            // Fallar CERRADO: sin tienda válida no debe ver nada (-1 no existe como PK).
            $tiendaIntId = DB::table('tiendas')->where('codigo', $user->tienda_id)->value('id');
            $base->where('h.tienda_id', $tiendaIntId ?: -1);
        } elseif ($request->filled('tienda')) {
            $tiendaIntId = DB::table('tiendas')->where('codigo', $request->tienda)->value('id');
            if ($tiendaIntId) {
                $base->where('h.tienda_id', $tiendaIntId);
            }
        }

        return $base;
    }

    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('historial_inventario')) {
            return response()->json([
                'kpis'        => null,
                'movimientos' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1],
                'warning'     => 'La tabla historial_inventario aún no ha sido migrada.',
            ]);
        }

        $perPage = $request->integer('per_page', 20);
        $base    = $this->baseQuery($request);

        $kpis = (clone $base)->selectRaw("
            COUNT(*)                                                            AS total_mov,
            COALESCE(SUM(CASE WHEN h.accion='SUMA'  THEN h.cantidad ELSE 0 END),0) AS total_entradas,
            COALESCE(SUM(CASE WHEN h.accion='RESTA' THEN h.cantidad ELSE 0 END),0) AS total_salidas,
            COUNT(DISTINCT h.tienda_id)                                         AS tiendas_afectadas,
            COUNT(DISTINCT h.agente_id)                                         AS agentes_involucrados
        ")->first();

        $movimientos = (clone $base)
            ->select([
                'h.id', 'h.fecha_hora', 'h.tienda_id', 'h.accion', 'h.cantidad',
                'h.motivo', 'h.observacion', 'h.imei_serial', 'h.precio_en_ese_momento', 'h.dni_autorizacion',
                DB::raw("COALESCE(t.codigo, CAST(h.tienda_id AS CHAR)) AS tienda_codigo"),
                DB::raw("COALESCE(t.nombre, '') AS tienda_nombre"),
                DB::raw("COALESCE(a.nombres, 'Sistema') AS agente_nombre"),
                DB::raw("COALESCE(it.producto_nombre, 'Chip/Otros') AS producto_nombre"),
                DB::raw("COALESCE(it.tipo, 'CHIP') AS producto_tipo"),
            ])
            ->orderByDesc('h.fecha_hora')
            ->paginate($perPage);

        return response()->json(['kpis' => $kpis, 'movimientos' => $movimientos]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        $rows = $this->baseQuery($request)
            ->select([
                'h.fecha_hora', 'h.tienda_id', 'h.accion', 'h.cantidad',
                'h.motivo', 'h.observacion', 'h.imei_serial', 'h.precio_en_ese_momento', 'h.dni_autorizacion',
                DB::raw("COALESCE(t.codigo, CAST(h.tienda_id AS CHAR)) AS tienda_codigo"),
                DB::raw("COALESCE(t.nombre, '') AS tienda_nombre"),
                DB::raw("COALESCE(a.nombres, 'Sistema') AS agente_nombre"),
                DB::raw("COALESCE(it.producto_nombre, 'Chip/Otros') AS producto_nombre"),
                DB::raw("COALESCE(it.tipo, 'CHIP') AS producto_tipo"),
            ])
            ->orderByDesc('h.fecha_hora')
            ->get();

        $headers = ['Fecha/Hora', 'Tienda', 'Agente', 'Producto', 'Tipo', 'Acción', 'Cantidad', 'IMEI/Serie', 'Precio S/', 'DNI Autorizante', 'Motivo', 'Observación'];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bitácora Stock');
        $sheet->fromArray($headers, null, 'A1');
        $ultimaColumna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$ultimaColumna}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);

        foreach ($rows as $index => $r) {
            $sheet->fromArray([
                $r->fecha_hora,
                $r->tienda_codigo,
                $r->agente_nombre,
                $r->producto_nombre,
                $r->producto_tipo,
                $r->accion,
                (int) $r->cantidad,
                $r->imei_serial,
                $r->precio_en_ese_momento !== null ? (float) $r->precio_en_ese_momento : null,
                $r->dni_autorizacion,
                $r->motivo,
                $r->observacion,
            ], null, 'A'.($index + 2));
        }

        for ($column = 1; $column <= count($headers); $column++) {
            $sheet->getColumnDimension(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column)
            )->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        return response()->streamDownload(
            static function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'bitacora_stock_'.date('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function kpis(Request $request): JsonResponse
    {
        if (! Schema::hasTable('historial_inventario')) {
            return response()->json(null);
        }

        $user  = $request->user();
        $query = DB::table('historial_inventario as h')
            ->when($request->fecha_desde, fn($q, $f) => $q->whereDate('h.fecha_hora', '>=', $f))
            ->when($request->fecha_hasta, fn($q, $f) => $q->whereDate('h.fecha_hora', '<=', $f));

        if ($user->rol !== 'admin') {
            // Mismo criterio fail-closed que baseQuery(): resolver código → int id.
            $tiendaIntId = DB::table('tiendas')->where('codigo', $user->tienda_id)->value('id');
            $query->where('h.tienda_id', $tiendaIntId ?: -1);
        }

        return response()->json($query->selectRaw("
            COUNT(*)                                                                AS total_mov,
            COALESCE(SUM(CASE WHEN h.accion='SUMA'  THEN h.cantidad ELSE 0 END),0) AS total_entradas,
            COALESCE(SUM(CASE WHEN h.accion='RESTA' THEN h.cantidad ELSE 0 END),0) AS total_salidas,
            COUNT(DISTINCT h.tienda_id)                                             AS tiendas_afectadas
        ")->first());
    }

    // ── POST /bitacora-stock/corregir — Corrección manual de stock (SUMA/RESTA) ──
    public function corregir(Request $request): JsonResponse
    {
        $inventarioId = (int) $request->input('inventario_id', 0);
        $dniAgente    = trim($request->input('dni_agente', ''));
        $accion       = strtoupper(trim($request->input('accion', '')));
        $cantidad     = (int) $request->input('cantidad', 0);
        $observacion  = trim($request->input('observacion', ''));
        $tipo         = strtoupper(trim($request->input('tipo', '')));
        $tiendaCodigo = trim($request->input('tienda_codigo', ''));

        if ($inventarioId <= 0)                                   return response()->json(['success' => false, 'message' => 'ID de inventario inválido.'], 422);
        if (!preg_match('/^\d{8}$/', $dniAgente))                return response()->json(['success' => false, 'message' => 'DNI de autorización inválido (8 dígitos).'], 422);
        if ($cantidad <= 0 || $cantidad > 9999)                  return response()->json(['success' => false, 'message' => 'Cantidad fuera de rango (1–9999).'], 422);
        if (!in_array($accion, ['SUMA', 'RESTA'], true))         return response()->json(['success' => false, 'message' => 'Acción inválida. Use SUMA o RESTA.'], 422);
        if (!in_array($tipo, ['CHIP', 'ACC'], true))             return response()->json(['success' => false, 'message' => 'Tipo inválido. Use CHIP o ACC.'], 422);
        if (empty($tiendaCodigo))                                 return response()->json(['success' => false, 'message' => 'Código de tienda requerido.'], 422);
        if (mb_strlen($observacion) < 10)                        return response()->json(['success' => false, 'message' => 'Observación: mínimo 10 caracteres.'], 422);

        DB::beginTransaction();
        try {
            $agente = DB::table('agentes')->whereRaw("TRIM(dni) = TRIM(?)", [$dniAgente])->where('estado', 'ACTIVO')->first();
            if ($agente) {
                $agenteId = $agente->id;
            } else {
                $usr = DB::table('usuarios')->whereRaw("TRIM(dni) = TRIM(?)", [$dniAgente])->where('activo', 1)->first();
                if (!$usr) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Autorizador no encontrado o inactivo.'], 404);
                }
                $agenteId = null;
            }

            $tiendaIntId = DB::table('tiendas')->where('codigo', $tiendaCodigo)->value('id');
            if (!$tiendaIntId) {
                DB::rollBack();
                return response()->json(['success' => false, 'message' => 'Tienda no encontrada.'], 404);
            }

            $productoNombre      = '';
            $imeiSerial          = null;
            $precioEseMomento    = null;
            $cantHist            = 0;
            $prodIdHist          = null;
            $stockAnterior       = null;
            $stockNuevo          = null;

            if ($tipo === 'CHIP') {
                $chip = DB::table('inventario_chips')->where('id', $inventarioId)->lockForUpdate()->first();
                if (!$chip) { DB::rollBack(); return response()->json(['success' => false, 'message' => 'Registro de chips no encontrado.'], 404); }
                $stockAnterior = (int)$chip->stock_actual;
                if ($accion === 'RESTA') {
                    if ($chip->stock_actual < $cantidad) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "Stock insuficiente. Solo hay {$chip->stock_actual} chips."], 422);
                    }
                    DB::table('inventario_chips')->where('id', $inventarioId)->decrement('stock_actual', $cantidad);
                    $cantHist  = -$cantidad;
                    $stockNuevo = $stockAnterior - $cantidad;
                } else {
                    DB::table('inventario_chips')->where('id', $inventarioId)->increment('stock_actual', $cantidad);
                    $cantHist  = $cantidad;
                    $stockNuevo = $stockAnterior + $cantidad;
                }
                $productoNombre = 'CHIP ' . ($chip->tipo_chip ?? 'SIM');
            } else {
                $prod = DB::table('inventario_tiendas')->where('id', $inventarioId)->lockForUpdate()->first();
                if (!$prod) { DB::rollBack(); return response()->json(['success' => false, 'message' => 'Producto no encontrado.'], 404); }
                if ($accion === 'RESTA') {
                    if ($prod->cantidad < $cantidad) {
                        DB::rollBack();
                        return response()->json(['success' => false, 'message' => "Stock insuficiente. Solo hay {$prod->cantidad} unidades."], 422);
                    }
                    DB::table('inventario_tiendas')->where('id', $inventarioId)->decrement('cantidad', $cantidad);
                    $cantHist = -$cantidad;
                } else {
                    DB::table('inventario_tiendas')->where('id', $inventarioId)->increment('cantidad', $cantidad);
                    $cantHist = $cantidad;
                }
                $productoNombre   = $prod->producto_nombre;
                $imeiSerial       = $prod->imei_serial ?: null;
                $precioEseMomento = $prod->precio_minimo > 0 ? $prod->precio_minimo : null;
                $prodIdHist       = $prod->id;
            }

            $histData = [
                'tienda_id'           => $tiendaIntId,
                'agente_id'           => $agenteId,
                'accion'              => $accion,
                'cantidad'            => abs($cantHist),
                'tienda_origen'       => $tiendaCodigo,
                'observacion'         => $observacion,
                'producto_id'         => $prodIdHist,
                'imei_serial'         => $imeiSerial,
                'precio_en_ese_momento' => $precioEseMomento,
                'dni_autorizacion'    => $dniAgente,
                'fecha_hora'          => now(),
            ];
            if (Schema::hasColumn('historial_inventario', 'stock_anterior')) {
                $histData['stock_anterior'] = $stockAnterior;
                $histData['stock_nuevo']    = $stockNuevo;
            }
            DB::table('historial_inventario')->insert($histData);

            DB::commit();
            $icono   = $accion === 'SUMA' ? '+' : '-';
            return response()->json([
                'success' => true,
                'message' => "{$icono}{$cantidad} unidades de «{$productoNombre}» registrado. Autorizado por DNI {$dniAgente}.",
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error interno: ' . $e->getMessage()], 500);
        }
    }
}
