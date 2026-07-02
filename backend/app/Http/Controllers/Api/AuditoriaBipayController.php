<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaBipayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de cierres Bipay (cruce declarado vs scrapeado) + resolución de
 * conflictos de atribución + configuración de alertas webhook. Puerto de
 * gerencia/ajax_reconcile_details.php, ajax_auditoria_ajuste.php,
 * ajax_resolver_conflicto.php y cron_auditoria_nocturna.php del legacy.
 */
class AuditoriaBipayController extends Controller
{
    public function __construct(private readonly AuditoriaBipayService $auditoria)
    {
    }

    /** GET /v1/auditoria-bipay?fecha= — cierres del día con su estado. */
    public function index(Request $request): JsonResponse
    {
        $fecha = $this->fecha($request);

        $cierres = DB::table('auditoria_cierres as ac')
            ->leftJoin('tiendas as t', 't.codigo', '=', 'ac.tienda_codigo')
            ->where('ac.fecha', $fecha)
            ->orderByRaw('ABS(ac.diferencia) DESC')
            ->get(['ac.*', 't.nombre as tienda_nombre']);

        return response()->json(['fecha' => $fecha, 'cierres' => $cierres]);
    }

    /** POST /v1/auditoria-bipay/cruce {fecha, tienda?} — ejecutar cruce manual. */
    public function ejecutarCruce(Request $request): JsonResponse
    {
        $fecha  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha'))
            ? $request->input('fecha') : now()->toDateString();
        $tienda = strtoupper(trim((string) $request->input('tienda', '')));

        $resultado = $tienda !== ''
            ? $this->auditoria->ejecutarCruceTienda($tienda, $fecha)
            : $this->auditoria->ejecutarCruceDiario($fecha);

        return response()->json(['ok' => true, 'resultado' => $resultado]);
    }

    /**
     * GET /v1/auditoria-bipay/{id}/detalles — comparativa lado a lado:
     * movimientos del scraper vs reportes declarados (reconcile details).
     */
    public function detalles(int $id): JsonResponse
    {
        $aud = DB::table('auditoria_cierres as ac')
            ->join('tiendas as t', 't.codigo', '=', 'ac.tienda_codigo')
            ->where('ac.id', $id)
            ->first(['ac.*', 't.nombre as tienda_nombre']);

        if (! $aud) {
            return response()->json(['error' => 'Registro de auditoría no encontrado.'], 404);
        }

        $scraperLogs = DB::table('bitel_operaciones_detalle')
            ->where('tienda_codigo', $aud->tienda_codigo)
            ->whereRaw('DATE(fecha_hora) = ?', [$aud->fecha])
            ->orderBy('fecha_hora')
            ->get(['fecha_hora', 'tipo_operacion', 'descripcion', 'monto', 'codigo_personal']);

        $erpLogs = DB::table('reportes as r')
            ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
            ->leftJoin('agentes as a', 'a.id', '=', 'r.agente_id')
            ->where('r.tienda_id', $aud->tienda_codigo)
            ->where('r.fecha', $aud->fecha)
            ->orderBy('r.id')
            ->selectRaw('r.id, u.nombre AS usuario, a.nombres AS agente,
                IFNULL(r.bipay,0) AS bipay, IFNULL(r.recarga_bipay,0) AS recarga_bipay,
                IFNULL(r.retiro_bipay,0) AS retiro_bipay,
                (IFNULL(r.bipay,0) + IFNULL(r.recarga_bipay,0) - IFNULL(r.retiro_bipay,0)) AS total_calculado,
                r.creado_en')
            ->get();

        return response()->json([
            'auditoria'    => $aud,
            'scraper_logs' => $scraperLogs,
            'erp_logs'     => $erpLogs,
        ]);
    }

    /** POST /v1/auditoria-bipay/{id}/ajustar {observacion} — justificar un descuadre. */
    public function ajustar(Request $request, int $id): JsonResponse
    {
        $request->validate(['observacion' => 'required|string|max:2000']);

        $registro = DB::table('auditoria_cierres')->where('id', $id)->first(['estado']);
        if (! $registro) {
            return response()->json(['ok' => false, 'error' => 'Registro de auditoría no encontrado.'], 404);
        }
        if ($registro->estado === 'CUADRADO') {
            return response()->json(['ok' => false, 'error' => 'No se puede ajustar un cierre que ya está cuadrado.'], 422);
        }

        DB::table('auditoria_cierres')->where('id', $id)->update([
            'estado'             => 'AJUSTADO',
            'observacion_ajuste' => $request->input('observacion'),
            'ajustado_por'       => $request->user()->id,
            'fecha_ajuste'       => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Descuadre ajustado y justificado correctamente.']);
    }

    /**
     * POST /v1/auditoria-bipay/resolver-conflicto — disputa captador vs vendedor
     * de un ticket: re-atribuye la comisión y registra la decisión.
     */
    public function resolverConflicto(Request $request): JsonResponse
    {
        $request->validate([
            'dni_cliente'      => 'required|string|max:15',
            'ticket_creado_en' => 'required|string',
            'decision'         => 'required|in:CAPTADOR,VENDEDOR',
            'captador'         => 'nullable|string|max:100',
            'vendedor'         => 'nullable|string|max:100',
        ]);

        try {
            DB::transaction(function () use ($request) {
                if ($request->input('decision') === 'CAPTADOR') {
                    DB::table('tickets_emitidos')
                        ->where('dni_cliente', $request->input('dni_cliente'))
                        ->where('creado_en', $request->input('ticket_creado_en'))
                        ->update(['vendedor' => (string) $request->input('captador', '')]);
                }

                DB::statement('
                    INSERT INTO log_resolucion_atribucion
                        (dni_cliente, ticket_creado_en, vendedor_original, captador_original, decision, resuelto_por)
                    VALUES (?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE decision = VALUES(decision),
                        resuelto_por = VALUES(resuelto_por), resuelto_en = NOW()
                ', [
                    $request->input('dni_cliente'),
                    $request->input('ticket_creado_en'),
                    (string) $request->input('vendedor', ''),
                    (string) $request->input('captador', ''),
                    $request->input('decision'),
                    $request->user()->id,
                ]);
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'message' => 'Conflicto resuelto y comisión re-atribuida con éxito.']);
    }

    /** GET /v1/auditoria-bipay/webhook — configuración de alertas. */
    public function webhookConfig(): JsonResponse
    {
        return response()->json($this->auditoria->webhookConfig());
    }

    /** PUT /v1/auditoria-bipay/webhook {bipay_webhook_url, bipay_webhook_tipo, bipay_webhook_umbral}. */
    public function guardarWebhook(Request $request): JsonResponse
    {
        $request->validate([
            'bipay_webhook_url'    => 'nullable|string|max:500',
            'bipay_webhook_tipo'   => 'nullable|in:discord,slack',
            'bipay_webhook_umbral' => 'nullable|numeric|min:0',
        ]);

        $this->auditoria->guardarWebhookConfig($request->only([
            'bipay_webhook_url', 'bipay_webhook_tipo', 'bipay_webhook_umbral',
        ]));

        return response()->json(['ok' => true]);
    }

    private function fecha(Request $request): string
    {
        $f = (string) $request->query('fecha', '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : now()->toDateString();
    }
}
