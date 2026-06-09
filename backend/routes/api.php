<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComisionPlanController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\BitacoraStockController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HistorialController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlanillaController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Support\Facades\Route;

// ── Health (público) ─────────────────────────────────────────────────────────
Route::get('/v1/health', fn() => response()->json([
    'status' => 'ok',
    'app'    => config('app.name'),
    'env'    => config('app.env'),
]));

// ── Auth (público) ───────────────────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

// ── Recursos protegidos ──────────────────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::get('auth/me',      [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // ── Dashboard ────────────────────────────────────────────────────────────
    Route::get('dashboard/kpis',      [DashboardController::class, 'kpis']);
    Route::get('dashboard/anomalias', [DashboardController::class, 'anomalias']);

    // ── Historial Completo ────────────────────────────────────────────────────
    Route::get('historial',           [HistorialController::class, 'index']);
    Route::get('historial/exportar',  [HistorialController::class, 'exportar']);

    // ── Bitácora de Stock ─────────────────────────────────────────────────────
    Route::get('bitacora-stock',      [BitacoraStockController::class, 'index']);
    Route::get('bitacora-stock/kpis', [BitacoraStockController::class, 'kpis']);

    // ── Reportes — rutas especiales ANTES del apiResource ────────────────────
    Route::get('reportes/mis-reportes', [ReporteController::class, 'misReportes']);

    Route::patch('reportes/{reporte}/destino-efectivo', [ReporteController::class, 'actualizarDestino']);
    Route::post('reportes/{reporte}/solicitar-edicion', [ReporteController::class, 'solicitarEdicion']);
    Route::post('reportes/{reporte}/aprobar-edicion',   [ReporteController::class, 'aprobarEdicion']);

    Route::apiResource('reportes', ReporteController::class);

    // ── Otros recursos ────────────────────────────────────────────────────────
    Route::apiResource('agentes',     AgenteController::class);
    Route::apiResource('clientes',    ClienteController::class);
    Route::apiResource('inventario',  InventarioController::class);
    Route::apiResource('ventas',      VentaController::class);
    Route::apiResource('comprobantes', ComprobanteController::class);

    Route::get('comisiones-planes', [ComisionPlanController::class, 'index']);

    Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar']);

    // ── Agentes — acciones adicionales ───────────────────────────────────────
    Route::get('agentes/{agente}/ventas',     [AgenteController::class, 'ventas']);
    Route::get('agentes/{agente}/comisiones', [AgenteController::class, 'comisiones']);

    // ── Planilla ─────────────────────────────────────────────────────────────
    Route::get('planilla/{mes}',                    [PlanillaController::class, 'calcular']);
    Route::post('planilla/ajuste',                  [PlanillaController::class, 'guardarAjuste']);
    Route::post('planilla/ajuste/reset-comisiones', [PlanillaController::class, 'resetarComisiones']);

    // ── CRM ──────────────────────────────────────────────────────────────────
    Route::get('crm/pipeline',               [LeadController::class, 'pipeline']);
    Route::apiResource('leads',              LeadController::class);
    Route::get('leads/{lead}/interacciones', [LeadController::class, 'interacciones']);
    Route::post('leads/{lead}/interacciones',[LeadController::class, 'agregarInteraccion']);
});
