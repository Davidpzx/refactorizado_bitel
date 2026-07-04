<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InteraccionCrm;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController extends Controller
{
    // Fallar CERRADO: un usuario no-admin sin tienda_id asignada no debe ver nada.
    // Mismo criterio que EstadisticasController::tiendaScope.
    private function tiendaScope(Request $request): ?string
    {
        $user = $request->user();

        if ($user->rol !== 'admin') {
            return $user->tienda_id ?: '__SIN_TIENDA__';
        }

        return $request->get('tienda_id');
    }

    public function index(Request $request): JsonResponse
    {
        $tienda = $this->tiendaScope($request);

        $leads = Lead::with('cliente:id,dni_ruc,nombre,telefono')
            ->when($request->estado, fn($q, $v) => $q->where('estado', $v))
            ->when($request->agente_id, fn($q, $v) => $q->where('agente_id', $v))
            ->when($tienda, fn($q) => $q->where('tienda_id', $tienda))
            ->when($request->filled('q'), function ($q) use ($request) {
                $termino = '%'.trim((string) $request->input('q')).'%';
                $q->where(function ($sub) use ($termino) {
                    $sub->whereHas('cliente', function ($c) use ($termino) {
                        $c->where('nombre', 'like', $termino)
                            ->orWhere('dni_ruc', 'like', $termino)
                            ->orWhere('telefono', 'like', $termino);
                    })->orWhereIn('agente_id', function ($aq) use ($termino) {
                        $aq->select('id')->from('agentes')->where('nombres', 'like', $termino);
                    });
                });
            })
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

    // ── Dashboard CRM analítico (KPIs + tendencia + ranking) ───────────────────

    public function dashboard(Request $request): JsonResponse
    {
        $tiendaId = $request->input('tienda_id');
        $desde    = $request->input('desde', now()->subDays(30)->toDateString());
        $hasta    = $request->input('hasta', now()->toDateString());

        $buildBase = fn () => Lead::query()
            ->when($tiendaId, fn ($q) => $q->where('tienda_id', $tiendaId))
            ->whereBetween('creado_en', [$desde, $hasta . ' 23:59:59']);

        // Pipeline del período ─────────────────────────────────────────────────
        $porEstado = $buildBase()
            ->selectRaw('estado, COUNT(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $estados  = ['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO'];
        $pipeline = array_map(
            fn ($e) => ['estado' => $e, 'total' => (int) ($porEstado[$e] ?? 0)],
            $estados
        );
        $totalLeads  = array_sum(array_column($pipeline, 'total'));
        $convertidos = (int) ($porEstado['CONVERTIDO'] ?? 0);
        $perdidos    = (int) ($porEstado['PERDIDO']    ?? 0);
        $tasa        = $totalLeads > 0 ? round(($convertidos / $totalLeads) * 100, 1) : 0.0;

        // Por fuente ───────────────────────────────────────────────────────────
        $porFuente = $buildBase()
            ->selectRaw('fuente, COUNT(*) as total')
            ->groupBy('fuente')
            ->pluck('total', 'fuente');

        $fuentes    = ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA'];
        $por_fuente = array_map(
            fn ($f) => ['fuente' => $f, 'total' => (int) ($porFuente[$f] ?? 0)],
            $fuentes
        );

        // Tendencia diaria ─────────────────────────────────────────────────────
        $tendencia = $buildBase()
            ->selectRaw("DATE(creado_en) as dia, COUNT(*) as leads, SUM(estado = 'CONVERTIDO') as convertidos")
            ->groupBy('dia')
            ->orderBy('dia')
            ->get()
            ->map(fn ($r) => [
                'dia'         => $r->dia,
                'leads'       => (int) $r->leads,
                'convertidos' => (int) $r->convertidos,
            ]);

        // Ranking agentes CRM (JOIN agentes para nombre) ───────────────────────
        $ranking = $buildBase()
            ->join('agentes as ag', 'ag.id', '=', 'leads.agente_id')
            ->selectRaw("leads.agente_id, ag.nombres, ag.tienda_id as tienda_base, COUNT(*) as total_leads, SUM(leads.estado = 'CONVERTIDO') as conv")
            ->groupBy('leads.agente_id', 'ag.nombres', 'ag.tienda_id')
            ->orderByDesc('total_leads')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'agente_id'   => $r->agente_id,
                'nombres'     => $r->nombres,
                'tienda_id'   => $r->tienda_base,
                'total_leads' => (int) $r->total_leads,
                'convertidos' => (int) $r->conv,
                'tasa'        => $r->total_leads > 0
                    ? round(($r->conv / $r->total_leads) * 100, 1)
                    : 0.0,
            ]);

        // Actividad reciente ───────────────────────────────────────────────────
        $actividad = \App\Models\InteraccionCrm::query()
            ->join('agentes as ag', 'ag.id', '=', 'interacciones_crm.agente_id')
            ->leftJoin('leads as l', 'l.id', '=', 'interacciones_crm.lead_id')
            ->leftJoin('clientes as c', 'c.id', '=', 'l.cliente_id')
            ->select([
                'interacciones_crm.id',
                'interacciones_crm.tipo',
                'interacciones_crm.detalle',
                'interacciones_crm.fecha',
                'ag.nombres as agente_nombres',
                'c.nombre as cliente_nombre',
            ])
            ->orderByDesc('interacciones_crm.fecha')
            ->limit(8)
            ->get();

        return response()->json([
            'total_leads'        => $totalLeads,
            'tasa_conversion'    => $tasa,
            'convertidos'        => $convertidos,
            'perdidos'           => $perdidos,
            'pipeline'           => $pipeline,
            'por_fuente'         => $por_fuente,
            'tendencia'          => $tendencia,
            'ranking_agentes'    => $ranking,
            'actividad_reciente' => $actividad,
        ]);
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
