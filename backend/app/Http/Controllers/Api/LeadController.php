<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InteraccionCrm;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = Lead::with('cliente:id,dni_ruc,nombre,telefono')
            ->when($request->estado, fn($q, $v) => $q->where('estado', $v))
            ->when($request->agente_id, fn($q, $v) => $q->where('agente_id', $v))
            ->when($request->tienda_id, fn($q, $v) => $q->where('tienda_id', $v))
            ->latest('creado_en')
            ->paginate($request->integer('per_page', 50));

        return response()->json($leads);
    }

    public function show(Lead $lead): JsonResponse
    {
        $lead->load([
            'cliente:id,dni_ruc,nombre,telefono,correo',
            'interacciones' => fn($q) => $q->latest('fecha')->limit(20),
        ]);
        return response()->json($lead);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'agente_id'  => ['required', 'integer', 'exists:agentes,id'],
            'tienda_id'  => ['required', 'string', 'max:10'],
            'estado'     => ['sometimes', 'in:NUEVO,CONTACTADO,INTERESADO,CONVERTIDO,PERDIDO'],
            'fuente'     => ['sometimes', 'in:PRESENCIAL,WHATSAPP,REFERIDO,LLAMADA'],
            'notas'      => ['nullable', 'string', 'max:2000'],
        ]);

        $lead = Lead::create($data);
        $lead->load('cliente:id,dni_ruc,nombre');
        return response()->json($lead, 201);
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'agente_id'  => ['sometimes', 'integer', 'exists:agentes,id'],
            'tienda_id'  => ['sometimes', 'string', 'max:10'],
            'estado'     => ['sometimes', 'in:NUEVO,CONTACTADO,INTERESADO,CONVERTIDO,PERDIDO'],
            'fuente'     => ['sometimes', 'in:PRESENCIAL,WHATSAPP,REFERIDO,LLAMADA'],
            'notas'      => ['nullable', 'string', 'max:2000'],
        ]);

        $lead->update($data);
        $lead->load('cliente:id,dni_ruc,nombre');
        return response()->json($lead);
    }

    public function destroy(Lead $lead): JsonResponse
    {
        $lead->interacciones()->delete();
        $lead->delete();
        return response()->json(null, 204);
    }

    // ── Interacciones CRM ────────────────────────────────────────────────────────

    public function interacciones(Lead $lead): JsonResponse
    {
        $interacciones = $lead->interacciones()
            ->latest('fecha')
            ->get();
        return response()->json($interacciones);
    }

    public function agregarInteraccion(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate([
            'agente_id' => ['required', 'integer', 'exists:agentes,id'],
            'tipo'      => ['required', 'in:LLAMADA,VISITA,WHATSAPP,VENTA,POSTVENTA'],
            'detalle'   => ['nullable', 'string', 'max:2000'],
        ]);

        $interaccion = InteraccionCrm::create(array_merge($data, [
            'lead_id'    => $lead->id,
            'cliente_id' => $lead->cliente_id,
        ]));

        return response()->json($interaccion, 201);
    }

    // ── Resumen del pipeline (métricas para dashboard) ──────────────────────────

    public function pipeline(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->when($request->agente_id, fn($q, $v) => $q->where('agente_id', $v))
            ->when($request->tienda_id, fn($q, $v) => $q->where('tienda_id', $v));

        $porEstado = (clone $query)
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->all();

        $estados = ['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO'];
        $pipeline = [];
        foreach ($estados as $estado) {
            $pipeline[] = ['estado' => $estado, 'total' => $porEstado[$estado] ?? 0];
        }

        $totalLeads    = array_sum(array_column($pipeline, 'total'));
        $convertidos   = $porEstado['CONVERTIDO'] ?? 0;
        $tasaConversion = $totalLeads > 0 ? round(($convertidos / $totalLeads) * 100, 1) : 0;

        return response()->json([
            'pipeline'        => $pipeline,
            'total_leads'     => $totalLeads,
            'tasa_conversion' => $tasaConversion,
        ]);
    }
}
