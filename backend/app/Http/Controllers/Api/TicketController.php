<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\UserAgentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TicketController extends Controller
{
    public function __construct(private readonly UserAgentResolver $userAgentResolver)
    {
    }

    // ── POST /tickets — Crear ticket (soporta payload simple o items[]) ─────────
    public function store(Request $request): JsonResponse
    {
        $user     = Auth::user();
        $esAdmin  = $user->rol === 'admin';
        $agenteId = $esAdmin
            ? (int) $request->input('agente_id', 0)
            : $this->userAgentResolver->resolveOrFail($user)->id;
        $tiendaId = $esAdmin
            ? substr(trim((string) $request->input('tienda_id', '')), 0, 20)
            : trim((string) $user->tienda_id);

        if ($agenteId <= 0 || $tiendaId === '') {
            return response()->json(['ok' => false, 'message' => 'Agente y tienda son obligatorios.'], 422);
        }
        $itemsRaw = $request->input('items');

        $monto          = 0.0;
        $descripcion    = '';
        $nombreCliente  = substr(trim($request->input('nombre_cliente', '')), 0, 150);
        $dniCliente     = substr(trim($request->input('dni_cliente', '')), 0, 20);

        if (is_array($itemsRaw) && count($itemsRaw) > 0) {
            $partes  = [];
            $nombres = [];
            $dnis    = [];
            foreach ($itemsRaw as $item) {
                $con    = substr(trim((string)($item['concepto'] ?? '')), 0, 100);
                $mon    = round((float)($item['monto'] ?? 0), 2);
                $nom    = substr(trim((string)($item['nombre']  ?? '')), 0, 80);
                $dniIt  = substr(trim((string)($item['dni']     ?? '')), 0, 20);
                if ($mon > 0) {
                    $seg    = $con !== '' ? $con : 'Pago';
                    $extras = array_filter([$nom, $dniIt]);
                    if (!empty($extras)) $seg .= ' (' . implode(' - ', $extras) . ')';
                    $seg      .= ' S/' . number_format($mon, 2);
                    $partes[]  = $seg;
                    $monto    += $mon;
                    if ($nom   !== '') $nombres[] = $nom;
                    if ($dniIt !== '') $dnis[]    = $dniIt;
                }
            }
            $descripcion   = substr(implode(', ', $partes), 0, 1000);
            if (!empty($nombres)) $nombreCliente = substr(implode(', ', $nombres), 0, 150);
            if (!empty($dnis))    $dniCliente    = substr(implode(', ', $dnis),    0, 20);
        } else {
            $monto       = round((float) $request->input('monto', 0), 2);
            $descripcion = substr(trim($request->input('descripcion', '')), 0, 1000);
        }

        if ($monto <= 0) {
            return response()->json(['ok' => false, 'message' => 'Monto inválido.'], 422);
        }

        $efeBruto      = round((float) $request->input('efectivo', 0), 2);
        $yape          = round((float) $request->input('yape', 0), 2);
        $bipay         = round((float) $request->input('bipay', 0), 2);
        $transferencia = round((float) $request->input('plin', $request->input('transferencia', 0)), 2);
        $totalRec      = $efeBruto + $yape + $bipay + $transferencia;
        $vuelto        = max(0.0, round($totalRec - $monto, 2));
        $efectivo      = max(0.0, round($efeBruto - $vuelto, 2));
        $cantidad      = max(1, (int) $request->input('cantidad', 1));

        $metodos   = array_filter([
            $efectivo      > 0 ? 'Efectivo'    : '',
            $yape          > 0 ? 'Yape'        : '',
            $bipay         > 0 ? 'Bipay'       : '',
            $transferencia > 0 ? 'Plin/Transf' : '',
        ]);
        $formaPago = !empty($metodos) ? implode('+', $metodos) : 'Efectivo';

        $id = DB::table('tickets_emitidos')->insertGetId([
            'tienda_id'      => $tiendaId,
            'agente_id'      => $agenteId,
            'vendedor'       => $esAdmin
                ? substr(trim((string) $request->input('vendedor', '')), 0, 100)
                : substr((string) $user->nombre, 0, 100),
            'nombre_cliente' => $nombreCliente,
            'dni_cliente'    => $dniCliente,
            'forma_pago'     => $formaPago,
            'telefono'       => substr(trim($request->input('telefono', '')), 0, 20),
            'descripcion'    => $descripcion,
            'monto'          => $monto,
            'cantidad'       => $cantidad,
            'efectivo'       => $efectivo,
            'yape'           => $yape,
            'bipay'          => $bipay,
            'transferencia'  => $transferencia,
            'vuelto'         => $vuelto,
            'creado_en'      => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    // ── GET /tickets/{id} — Ver ticket individual (para impresión) ───────────
    public function show(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('tickets_emitidos as t')
            ->leftJoin('agentes as a', 'a.id', '=', 't.agente_id')
            ->where('t.id', $id)
            ->select('t.*', DB::raw("COALESCE(a.nombres, t.vendedor) AS cajero_nombres"))
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Ticket no encontrado.'], 404);
        }
        abort_if(
            $request->user()->rol !== 'admin' && $ticket->tienda_id !== $request->user()->tienda_id,
            403,
            'No tienes permisos sobre este ticket.'
        );
        return response()->json($ticket);
    }

    // ── PATCH /tickets/{id} — Actualizar datos del cliente / anular ───────────
    // Solo escribe los campos presentes en el request: un PATCH parcial (p.ej. anular
    // enviando solo `estado`) ya no borra el nombre/pagos del ticket.
    public function update(Request $request, int $id): JsonResponse
    {
        $ticket = DB::table('tickets_emitidos')->where('id', $id)->first();
        if (! $ticket) {
            return response()->json(['message' => 'Ticket no encontrado.'], 404);
        }
        abort_if(
            $request->user()->rol !== 'admin' && $ticket->tienda_id !== $request->user()->tienda_id,
            403,
            'No tienes permisos sobre este ticket.'
        );

        $textos = [
            'nombre_cliente' => 150,
            'forma_pago'     => 50,
            'telefono'       => 20,
        ];
        $montos = ['efectivo', 'yape', 'bipay', 'vuelto'];

        $update = [];
        foreach ($textos as $campo => $max) {
            if ($request->has($campo)) {
                $update[$campo] = substr(trim((string) $request->input($campo)), 0, $max);
            }
        }
        foreach ($montos as $campo) {
            if ($request->has($campo)) {
                $update[$campo] = round((float) $request->input($campo), 2);
            }
        }
        if ($request->has('plin') || $request->has('transferencia')) {
            $update['transferencia'] = round((float) $request->input('plin', $request->input('transferencia', 0)), 2);
        }
        // Anular (estado) — guardado por columna por drift legacy.
        if ($request->has('estado') && Schema::hasColumn('tickets_emitidos', 'estado')) {
            $update['estado'] = substr(trim((string) $request->input('estado')), 0, 30);
        }

        if (! empty($update)) {
            $update['updated_at'] = now();
            DB::table('tickets_emitidos')->where('id', $id)->update($update);
        }

        return response()->json(['ok' => true]);
    }

    // ── DELETE /tickets/{id} — Eliminar ticket (admin, paridad api/eliminar_ticket.php) ──
    public function destroy(int $id): JsonResponse
    {
        $user = Auth::user();
        if ($user->rol !== 'admin') {
            return response()->json(['success' => false, 'message' => 'Solo administradores.'], 403);
        }

        $deleted = DB::table('tickets_emitidos')->where('id', $id)->delete();
        if (! $deleted) {
            return response()->json(['success' => false, 'message' => 'Ticket no encontrado.'], 404);
        }
        return response()->json(['success' => true, 'message' => 'Ticket eliminado.']);
    }

    /** Filtros comunes a index() y exportar(): tienda, agente, fechas, búsqueda, cliente y forma de pago. */
    private function baseQuery(Request $request)
    {
        $user    = Auth::user();
        $esAdmin = $user->rol === 'admin';

        $query = DB::table('tickets_emitidos');

        if (!$esAdmin) {
            $query->where('tienda_id', $user->tienda_id);
        }

        if ($request->filled('tienda_id')) $query->where('tienda_id', $request->tienda_id);
        if ($request->filled('agente_id')) $query->where('agente_id', $request->agente_id);
        if ($request->filled('desde'))     $query->where('creado_en', '>=', $request->desde);
        if ($request->filled('hasta'))     $query->where('creado_en', '<=', $request->hasta . ' 23:59:59');

        if ($request->filled('q')) {
            $texto = '%'.trim((string) $request->input('q')).'%';
            $query->where('descripcion', 'like', $texto);
        }

        if ($request->filled('dni_cliente')) {
            $texto = '%'.trim((string) $request->input('dni_cliente')).'%';
            $query->where(function ($qq) use ($texto) {
                $qq->where('dni_cliente', 'like', $texto)
                    ->orWhere('nombre_cliente', 'like', $texto);
            });
        }

        // Forma de pago: se deriva de los montos por canal (no del texto libre 'forma_pago'),
        // para que coincida exactamente con el badge que ya calcula el frontend (detectFormaPago()).
        if ($request->filled('forma_pago')) {
            $canales = ['efectivo', 'yape', 'bipay', 'transferencia'];
            $conteo  = implode(' + ', array_map(fn ($c) => "CASE WHEN {$c} > 0 THEN 1 ELSE 0 END", $canales));

            switch (strtoupper((string) $request->input('forma_pago'))) {
                case 'EFECTIVO':
                    $query->where('efectivo', '>', 0)->where('yape', 0)->where('bipay', 0)->where('transferencia', 0);
                    break;
                case 'YAPE':
                    $query->where('yape', '>', 0)->where('efectivo', 0)->where('bipay', 0)->where('transferencia', 0);
                    break;
                case 'BIPAY':
                    $query->where('bipay', '>', 0)->where('efectivo', 0)->where('yape', 0)->where('transferencia', 0);
                    break;
                case 'PLIN':
                case 'TRANSFERENCIA':
                    $query->where('transferencia', '>', 0)->where('efectivo', 0)->where('yape', 0)->where('bipay', 0);
                    break;
                case 'MIXTO':
                    $query->whereRaw("({$conteo}) > 1");
                    break;
            }
        }

        return $query;
    }

    // ── GET /tickets — Listar tickets (admin o filtro por tienda) ─────────────
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int)($request->per_page ?? 50), 200);
        $query   = $this->baseQuery($request)->orderByDesc('creado_en');

        return response()->json($query->paginate($perPage));
    }

    // ── GET /tickets/exportar — Excel real con los mismos filtros que index() ──
    public function exportar(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $rows = $this->baseQuery($request)->orderByDesc('creado_en')->get();

        $headers = ['Ticket #', 'Fecha', 'Tienda', 'Vendedor', 'Cliente', 'DNI', 'Teléfono', 'Descripción', 'Cantidad', 'Monto S/', 'Efectivo', 'Yape', 'Bipay', 'Transf./Plin', 'Vuelto', 'Forma de pago'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tickets');
        $sheet->fromArray($headers, null, 'A1');
        $ultimaColumna = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$ultimaColumna}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A1A2E']],
        ]);

        foreach ($rows as $index => $r) {
            $sheet->fromArray([
                '#'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT),
                substr((string) $r->creado_en, 0, 10),
                $r->tienda_id,
                $r->vendedor,
                $r->nombre_cliente,
                $r->dni_cliente,
                $r->telefono,
                $r->descripcion,
                (int) $r->cantidad,
                (float) $r->monto,
                (float) $r->efectivo,
                (float) $r->yape,
                (float) $r->bipay,
                (float) $r->transferencia,
                (float) $r->vuelto,
                $r->forma_pago,
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
                (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            'tickets_'.date('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
