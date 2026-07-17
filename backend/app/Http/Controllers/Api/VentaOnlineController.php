<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VentaOnline;
use App\Models\VentaOnlineIncumplimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * App Venta Online — registro de ventas desde la app y su gestión.
 *
 * Autenticación por Sanctum (grupo v1/app). El actor (agente_ref/tienda) se toma
 * del usuario autenticado; jamás del cliente, para el scoping anti-IDOR. La gestión
 * (index) es para admin/gerente/jefe_tienda con filtros y KPIs; jefe_tienda queda
 * limitado a su tienda.
 */
class VentaOnlineController extends Controller
{
    private const OPERADORES = ['Movistar', 'Claro', 'Entel', 'Bitel', 'Otro', 'Nueva'];

    private function agenteRef(Request $request): string
    {
        $u = $request->user();
        return (string) ($u->nombre ?? $u->email ?? (string) $u->id);
    }

    /** Crea una venta en estado pendiente (llamada por la app tras el formulario). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dni'             => ['required', 'string', 'regex:/^\d{8}$/'],
            'nombres'         => ['required', 'string', 'max:160'],
            'telefono'        => ['nullable', 'string', 'regex:/^\d{6,15}$/'],
            'operador_origen' => ['required', 'string', 'in:' . implode(',', self::OPERADORES)],
            'tipo'            => ['required', 'in:delivery_chip,plan_online'],
            'plan_ofrecido'   => ['nullable', 'string', 'max:120'],
            'notas'           => ['nullable', 'string'],
        ]);

        $user         = $request->user();
        $agenteRef    = $this->agenteRef($request);
        $tiendaCodigo = (string) ($user->tienda_id ?? '');

        // Cruce CRM: enlaza el cliente si ya existe (por dni, o teléfono si vino).
        $crmClienteId = null;
        if (Schema::hasTable('crm_clientes')) {
            $q = DB::table('crm_clientes')->where('dni', $data['dni']);
            if (!empty($data['telefono'])) {
                $q->orWhere('telefono', $data['telefono']);
            }
            $cliente = $q->orderByRaw('dni = ? DESC', [$data['dni']])->first(['id']);
            if ($cliente) {
                $crmClienteId = (int) $cliente->id;
            }
        }

        $venta = VentaOnline::create([
            'agente_ref'      => $agenteRef,
            'tienda_codigo'   => $tiendaCodigo,
            'dni'             => $data['dni'],
            'nombres'         => mb_strtoupper($data['nombres']),
            'telefono'        => $data['telefono'] ?? null,
            'operador_origen' => $data['operador_origen'],
            'tipo'            => $data['tipo'],
            'plan_ofrecido'   => $data['plan_ofrecido'] ?? null,
            'notas'           => $data['notas'] ?? null,
            'estado'          => 'pendiente',
            'crm_cliente_id'  => $crmClienteId,
            'origen'          => 'app',
        ]);

        if ($crmClienteId !== null && Schema::hasTable('crm_interacciones')) {
            try {
                DB::table('crm_interacciones')->insert([
                    'cliente_id'       => $crmClienteId,
                    'tienda_codigo'    => $tiendaCodigo,
                    'agente_nombre'    => $agenteRef,
                    'tipo_operacion'   => 'venta_online',
                    'producto_interes' => $data['plan_ofrecido'] ?? null,
                    'motivo_rechazo'   => null,
                    'observacion'      => 'Captado por app Venta Online (' . $data['tipo'] . ')',
                    'fecha_hora'       => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[venta-online] interaccion CRM: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success'        => true,
            'id'             => $venta->id,
            'crm_cliente_id' => $crmClienteId,
            'ya_contactado'  => $crmClienteId !== null,
        ], 201);
    }

    /** Ventas del propio agente para una fecha (default hoy). */
    public function mias(Request $request): JsonResponse
    {
        $fecha = (string) $request->query('fecha', now()->toDateString());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = now()->toDateString();
        }
        $fin = date('Y-m-d', strtotime($fecha . ' +1 day'));

        $ventas = VentaOnline::query()
            ->where('agente_ref', $this->agenteRef($request))
            ->where('created_at', '>=', $fecha)
            ->where('created_at', '<', $fin)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'fecha' => $fecha, 'ventas' => $ventas]);
    }

    /** Cambia el estado de una venta PROPIA del agente. */
    public function estado(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'estado'       => ['required', 'in:exitoso,fallido'],
            'motivo_falla' => ['nullable', 'string', 'max:200'],
        ]);

        if ($data['estado'] === 'fallido' && empty($data['motivo_falla'])) {
            return response()->json(['success' => false, 'message' => 'El motivo de falla es obligatorio.'], 422);
        }

        // Scoping anti-IDOR: solo ventas del propio agente.
        $venta = VentaOnline::where('id', $id)
            ->where('agente_ref', $this->agenteRef($request))
            ->first();

        if (!$venta) {
            return response()->json(['success' => false, 'message' => 'Venta no encontrada'], 404);
        }

        $venta->estado = $data['estado'];
        $venta->motivo_falla = $data['estado'] === 'fallido' ? $data['motivo_falla'] : null;
        $venta->save();

        if ($data['estado'] === 'exitoso' && $venta->crm_cliente_id && Schema::hasTable('crm_interacciones')) {
            try {
                DB::table('crm_interacciones')->insert([
                    'cliente_id'      => (int) $venta->crm_cliente_id,
                    'tienda_codigo'   => $venta->tienda_codigo,
                    'agente_nombre'   => $venta->agente_ref,
                    'tipo_operacion'  => 'venta_online',
                    'observacion'     => 'Venta online concretada',
                    'fecha_hora'      => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[venta-online] interaccion CRM estado: ' . $e->getMessage());
            }
        }

        return response()->json(['success' => true, 'id' => $venta->id, 'estado' => $venta->estado]);
    }

    /** Reporte de apertura de Activa Bitel sin registro previo (fail-open). */
    public function incumplimiento(Request $request): JsonResponse
    {
        $data = $request->validate([
            'detalle' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        VentaOnlineIncumplimiento::create([
            'agente_ref'    => $this->agenteRef($request),
            'tienda_codigo' => (string) ($user->tienda_id ?? ''),
            'detectado_en'  => now(),
            'detalle'       => $data['detalle'] ?? null,
        ]);

        return response()->json(['success' => true], 201);
    }

    /** Listado de gestión con filtros + KPIs (admin/gerente/jefe_tienda). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $esJefe = $user->rol === ($user::ROL_JEFE_TIENDA ?? 'jefe_tienda');

        $q = VentaOnline::query();

        if ($esJefe) {
            $q->where('tienda_codigo', (string) ($user->tienda_id ?? ''));
        } elseif ($request->filled('tienda')) {
            $q->where('tienda_codigo', $request->query('tienda'));
        }

        if ($request->filled('fecha_desde')) { $q->where('created_at', '>=', $request->query('fecha_desde')); }
        if ($request->filled('fecha_hasta')) {
            $q->where('created_at', '<', date('Y-m-d', strtotime($request->query('fecha_hasta') . ' +1 day')));
        }
        if ($request->filled('agente'))   { $q->where('agente_ref', $request->query('agente')); }
        if ($request->filled('estado') && in_array($request->query('estado'), ['pendiente', 'exitoso', 'fallido'], true)) {
            $q->where('estado', $request->query('estado'));
        }
        if ($request->filled('operador') && in_array($request->query('operador'), self::OPERADORES, true)) {
            $q->where('operador_origen', $request->query('operador'));
        }
        if ($request->filled('tipo') && in_array($request->query('tipo'), ['delivery_chip', 'plan_online'], true)) {
            $q->where('tipo', $request->query('tipo'));
        }
        if ($request->filled('busqueda')) {
            $b = preg_replace('/\D/', '', (string) $request->query('busqueda'));
            if ($b !== '') { $q->where(fn ($w) => $w->where('dni', 'like', $b . '%')->orWhere('telefono', 'like', $b . '%')); }
        }

        $porPagina = (int) $request->query('per_page', 25);
        if ($porPagina < 1 || $porPagina > 100) { $porPagina = 25; }

        // KPIs sobre el mismo filtro (clonar antes de paginar).
        $kpiQ = (clone $q);
        $total     = (clone $kpiQ)->count();
        $exitosos  = (clone $kpiQ)->where('estado', 'exitoso')->count();
        $fallidos  = (clone $kpiQ)->where('estado', 'fallido')->count();
        $pendientes = (clone $kpiQ)->where('estado', 'pendiente')->count();
        $topMotivos = (clone $kpiQ)->where('estado', 'fallido')->whereNotNull('motivo_falla')
            ->select('motivo_falla as motivo', DB::raw('COUNT(*) as n'))
            ->groupBy('motivo_falla')->orderByDesc('n')->limit(5)->get();

        $incQ = VentaOnlineIncumplimiento::query();
        if ($esJefe) { $incQ->where('tienda_codigo', (string) ($user->tienda_id ?? '')); }
        elseif ($request->filled('tienda')) { $incQ->where('tienda_codigo', $request->query('tienda')); }
        if ($request->filled('fecha_desde')) { $incQ->where('detectado_en', '>=', $request->query('fecha_desde')); }
        if ($request->filled('fecha_hasta')) {
            $incQ->where('detectado_en', '<', date('Y-m-d', strtotime($request->query('fecha_hasta') . ' +1 day')));
        }
        $incumplimientos = $incQ->count();

        $ventas = $q->orderByDesc('created_at')->paginate($porPagina);

        return response()->json([
            'success' => true,
            'ventas'  => $ventas->items(),
            'paginacion' => [
                'total'      => $ventas->total(),
                'pagina'     => $ventas->currentPage(),
                'por_pagina' => $ventas->perPage(),
                'paginas'    => $ventas->lastPage(),
            ],
            'kpis' => [
                'total'           => $total,
                'exitosos'        => $exitosos,
                'fallidos'        => $fallidos,
                'pendientes'      => $pendientes,
                'pct_exito'       => $total > 0 ? round($exitosos * 100 / $total, 1) : 0.0,
                'incumplimientos' => $incumplimientos,
                'top_motivos'     => $topMotivos,
            ],
        ]);
    }
}
