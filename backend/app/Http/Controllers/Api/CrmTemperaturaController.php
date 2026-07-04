<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm\TemperaturaCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM con temperatura calculada — paridad con gerencia/crm_dashboard.php del legacy sis_bipay.
 * Opera sobre crm_clientes/crm_interacciones (dominio propio, indexado por DNI), no sobre leads.estado.
 */
class CrmTemperaturaController extends Controller
{
    public function __construct(private readonly TemperaturaCalculator $calculador)
    {
    }

    /** GET /v1/crm/temperatura — feed de interacciones con la temperatura del cliente. */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('crm_interacciones as i')
            ->join('crm_clientes as c', 'c.id', '=', 'i.cliente_id')
            ->when($request->tienda_codigo, fn ($q, $v) => $q->where('i.tienda_codigo', $v))
            ->when($request->dni, fn ($q, $v) => $q->where('c.dni', $v))
            ->when($request->desde, fn ($q, $v) => $q->where('i.fecha_hora', '>=', $v))
            ->when($request->hasta, fn ($q, $v) => $q->where('i.fecha_hora', '<=', $v . ' 23:59:59'))
            ->orderByDesc('i.fecha_hora')
            ->select([
                'i.id', 'i.cliente_id', 'c.dni', 'c.nombres', 'c.apellidos', 'c.telefono',
                'i.tienda_codigo', 'i.agente_nombre', 'i.tipo_operacion',
                'i.producto_interes', 'i.motivo_rechazo', 'i.fecha_hora',
            ]);

        $perPage = $request->integer('per_page', 50);
        $paginado = $query->paginate($perPage);

        $temperaturas = [];
        $filtroTemp = $request->input('temperatura');

        $data = collect($paginado->items())->map(function ($row) use (&$temperaturas) {
            $row = (array) $row;
            if (! isset($temperaturas[$row['dni']])) {
                $temperaturas[$row['dni']] = $this->calculador->calcular($row['dni']);
            }
            $row['temperatura'] = $temperaturas[$row['dni']];
            return $row;
        });

        if ($filtroTemp) {
            $data = $data->filter(fn ($row) => $row['temperatura']['etiqueta'] === $filtroTemp)->values();
        }

        return response()->json([
            'data'         => $data,
            'total'        => $paginado->total(),
            'current_page' => $paginado->currentPage(),
            'per_page'     => $paginado->perPage(),
        ]);
    }

    /** GET /v1/crm/temperatura/{dni} — temperatura calculada de un solo cliente. */
    public function porDni(string $dni): JsonResponse
    {
        if (! preg_match('/^\d{8}$/', $dni)) {
            return response()->json(['error' => 'El DNI debe tener exactamente 8 dígitos numéricos.'], 422);
        }

        return response()->json($this->calculador->calcular($dni));
    }
}
