<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BipayController;
use App\Http\Controllers\Api\BitacoraStockController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComisionPlanController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DniController;
use App\Http\Controllers\Api\EstadisticasController;
use App\Http\Controllers\Api\HistorialController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlanillaController;
use App\Http\Controllers\Api\ReporteBcpController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\TiendaController;
use App\Http\Controllers\Api\UsuarioController;
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
    Route::post('login',      [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('verify-pin', [AuthController::class, 'verifyPin'])->middleware('throttle:20,1');
});

// ── Terminal de Asistencias (público, throttled) ──────────────────────────────
Route::prefix('v1/attendance')->middleware('throttle:60,1')->group(function () {
    Route::get('status/{dni}',  [AsistenciaController::class, 'status']);
    Route::post('mark',         [AsistenciaController::class, 'mark']);
    Route::post('mark-qr',      [AsistenciaController::class, 'markQr']);
    Route::post('mark-photo',   [AsistenciaController::class, 'markPhoto']);
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
    Route::get('reportes/{reporte}/historial',           [ReporteController::class, 'historial']);

    Route::apiResource('reportes', ReporteController::class);

    // ── Otros recursos ────────────────────────────────────────────────────────
    Route::apiResource('agentes',     AgenteController::class);
    Route::apiResource('clientes',    ClienteController::class);
    Route::apiResource('inventario',  InventarioController::class);
    Route::apiResource('ventas',      VentaController::class);
    Route::apiResource('comprobantes', ComprobanteController::class);

    // ── Comisiones de Planes ──────────────────────────────────────────────────
    Route::get('comisiones-planes',                    [ComisionPlanController::class, 'index']);
    Route::post('comisiones-planes',                   [ComisionPlanController::class, 'store']);
    Route::put('comisiones-planes/{comisionesPlan}',   [ComisionPlanController::class, 'update']);
    Route::delete('comisiones-planes/{comisionesPlan}',[ComisionPlanController::class, 'destroy']);
    Route::post('comisiones-planes/recalcular',        [ComisionPlanController::class, 'recalcularMasivo']);

    // ── Configuración Empresa ─────────────────────────────────────────────────
    Route::get('configuracion',             [ConfiguracionController::class, 'show']);
    Route::get('configuracion/con-logo',    [ConfiguracionController::class, 'showConLogo']);
    Route::put('configuracion',             [ConfiguracionController::class, 'update']);
    Route::post('configuracion/logo',       [ConfiguracionController::class, 'updateLogo']);
    Route::delete('configuracion/logo',     [ConfiguracionController::class, 'deleteLogo']);

    // ── RENIEC DNI ────────────────────────────────────────────────────────────
    Route::get('dni/{dni}',                 [DniController::class, 'consultar']);

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

    // ── Estadísticas ─────────────────────────────────────────────────────────
    Route::get('estadisticas/ventas',       [EstadisticasController::class, 'ventas']);
    Route::get('estadisticas/productividad',[EstadisticasController::class, 'productividad']);
    Route::get('estadisticas/ranking',      [EstadisticasController::class, 'rankingAgentes']);

    // ── Reporte BCP ───────────────────────────────────────────────────────────
    Route::get('reporte-bcp',         [ReporteBcpController::class, 'index']);
    Route::post('reporte-bcp',        [ReporteBcpController::class, 'store']);
    Route::get('reporte-bcp/tiendas', [ReporteBcpController::class, 'tiendas']);

    // ── Usuarios ──────────────────────────────────────────────────────────────
    Route::apiResource('usuarios', UsuarioController::class);

    // ── Tiendas ───────────────────────────────────────────────────────────────
    Route::apiResource('tiendas', TiendaController::class);

    // ── Bipay ─────────────────────────────────────────────────────────────────
    Route::get('bipay/saldo',          [BipayController::class, 'saldo']);
    Route::get('bipay/transacciones',  [BipayController::class, 'transacciones']);
    Route::post('bipay/recarga',       [BipayController::class, 'recarga']);

    // ── Asistencias (panel admin) ─────────────────────────────────────────────
    Route::get('asistencias',                        [AsistenciaController::class, 'index']);
    Route::post('asistencias',                       [AsistenciaController::class, 'registrar']);
    Route::post('asistencias/{id}/aprobar',          [AsistenciaController::class, 'aprobar']);
    Route::get('asistencias/exportar',               [AsistenciaController::class, 'exportar']);
    Route::get('asistencias/fotos-pendientes',       [AsistenciaController::class, 'fotosPendientes']);
    Route::post('asistencias/{id}/photo-action',     [AsistenciaController::class, 'photoAction']);
    Route::get('attendance/qr-stream/{tienda_id}',   [AsistenciaController::class, 'qrStream']);
});
