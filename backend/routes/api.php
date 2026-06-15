<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BipayController;
use App\Http\Controllers\Api\BitacoraStockController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComisionPlanController;
use App\Http\Controllers\Api\ConfigComisionesController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ControlCenterController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DniController;
use App\Http\Controllers\Api\EstadisticasController;
use App\Http\Controllers\Api\HistorialController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\PlanillaController;
use App\Http\Controllers\Api\ReporteBcpController;
use App\Http\Controllers\Api\ReporteBorradorController;
use App\Http\Controllers\Api\ReporteController;
use App\Http\Controllers\Api\TiendaController;
use App\Http\Controllers\Api\ChipsController;
use App\Http\Controllers\Api\DiagnosticoController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\DispositivoController;
use App\Http\Controllers\Api\PostulanteController;
use App\Http\Controllers\Api\MatrizInventarioController;
use App\Http\Controllers\Api\PanelFinancierasController;
use App\Http\Controllers\Api\TrasladoChipsController;
use App\Http\Controllers\Api\TrasladoController;
use App\Http\Controllers\Api\ConstanciaController;
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

// ── Dispositivo — autorización fingerprint (público) ─────────────────────────
Route::post('/v1/autorizar-dispositivo', [DispositivoController::class, 'autorizar'])->middleware('throttle:30,1');

// ── Postulaciones — formulario público ───────────────────────────────────────
Route::post('/v1/postulaciones',         [PostulanteController::class, 'store'])->middleware('throttle:10,1');
Route::get('/v1/postulaciones/tiendas',  [PostulanteController::class, 'tiendas']);

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
    Route::get('dashboard/anomalias', [DashboardController::class, 'anomalias'])->middleware('role:admin');
    Route::get('control-center',      [ControlCenterController::class, 'index']);
    Route::post('marcar-notificacion', [ControlCenterController::class, 'marcarNotificacion']);

    // ── Historial Completo ────────────────────────────────────────────────────
    Route::get('historial',           [HistorialController::class, 'index'])->middleware('role:admin');
    Route::get('historial/exportar',  [HistorialController::class, 'exportar'])->middleware('role:admin');

    // ── Bitácora de Stock ─────────────────────────────────────────────────────
    Route::get('bitacora-stock',           [BitacoraStockController::class, 'index']);
    Route::get('bitacora-stock/kpis',      [BitacoraStockController::class, 'kpis']);
    Route::post('bitacora-stock/corregir', [BitacoraStockController::class, 'corregir'])->middleware('role:admin');

    // ── Reportes — rutas especiales ANTES del apiResource ────────────────────
    Route::get('reportes/mis-reportes', [ReporteController::class, 'misReportes']);
    Route::get('reportes/vendedores', [ReporteController::class, 'vendedores']);
    Route::get('reportes/borrador', [ReporteBorradorController::class, 'show'])->middleware('open.shift');
    Route::post('reportes/borrador', [ReporteBorradorController::class, 'store'])->middleware('open.shift');
    Route::delete('reportes/borrador', [ReporteBorradorController::class, 'destroy'])->middleware('open.shift');
    Route::post('reportes/borrador/eliminar', [ReporteBorradorController::class, 'destroy'])->middleware('open.shift');

    Route::patch('reportes/{reporte}/destino-efectivo', [ReporteController::class, 'actualizarDestino']);
    Route::post('reportes/{reporte}/solicitar-edicion', [ReporteController::class, 'solicitarEdicion']);
    Route::post('reportes/{reporte}/aprobar-edicion',        [ReporteController::class, 'aprobarEdicion']);
    Route::put('reportes/{reporte}/reprocesar',             [ReporteController::class, 'reprocesar']);
    Route::get('reportes/{reporte}/historial',              [ReporteController::class, 'historial']);
    Route::post('reporte-categorias/{id}/fijar-costo',      [ReporteController::class, 'fijarCosto']);

    Route::get('reportes', [ReporteController::class, 'index'])->middleware('role:admin');
    Route::post('reportes', [ReporteController::class, 'store'])->middleware(['role:admin,tienda', 'open.shift']);
    Route::get('reportes/{reporte}', [ReporteController::class, 'show']);
    Route::match(['put', 'patch'], 'reportes/{reporte}', [ReporteController::class, 'update']);
    Route::delete('reportes/{reporte}', [ReporteController::class, 'destroy']);

    // ── Otros recursos ────────────────────────────────────────────────────────
    // Custom agentes routes BEFORE apiResource to avoid {agente} wildcard conflict
    Route::get('agentes/exportar',              [MatrizInventarioController::class, 'exportarAgentes'])->middleware('role:admin');
    Route::get('agentes/exportar-ficha',        [AgenteController::class, 'exportarFichaTecnica'])->middleware('role:admin');
    Route::apiResource('agentes', AgenteController::class)->middleware('role:admin');
    Route::apiResource('clientes',    ClienteController::class);
    // Custom inventario routes MUST come before apiResource to avoid {inventario} wildcard conflict
    Route::get('inventario/kardex',          [InventarioController::class, 'kardex']);
    Route::get('inventario/stock-estancado', [InventarioController::class, 'stockEstancado']);
    Route::get('inventario/campana-costos',  [InventarioController::class, 'campanaCostos']);
    Route::get('inventario/precios-pendientes', [InventarioController::class, 'preciosPendientes']);
    Route::get('inventario/exportar-kardex', [InventarioController::class, 'exportarKardex']);
    Route::get('inventario/matriz',          [MatrizInventarioController::class, 'index']);
    Route::get('inventario/exportar',        [MatrizInventarioController::class, 'exportar']);
    Route::get('inventario', [InventarioController::class, 'index']);
    Route::post('inventario', [InventarioController::class, 'store'])->middleware('role:admin');
    Route::get('inventario/{inventario}', [InventarioController::class, 'show']);
    Route::match(['put', 'patch'], 'inventario/{inventario}', [InventarioController::class, 'update'])->middleware('role:admin');
    Route::delete('inventario/{inventario}', [InventarioController::class, 'destroy'])->middleware('role:admin');
    Route::post('inventario/{id}/ajustar-stock-real', [InventarioController::class, 'ajustarStockReal'])->middleware('role:admin');
    Route::post('inventario/{id}/restaurar',            [InventarioController::class, 'restaurar'])->middleware('role:admin');
    Route::post('inventario/{id}/recalcular-ganancias', [InventarioController::class, 'recalcularGanancias'])->middleware('role:admin');
    Route::post('inventario/{id}/precio-agente',        [InventarioController::class, 'fijarPrecioAgente']);
    Route::apiResource('ventas',      VentaController::class);
    Route::apiResource('comprobantes', ComprobanteController::class)->middleware('role:admin');

    // ── Comisiones de Planes ──────────────────────────────────────────────────
    Route::get('comisiones-planes',                    [ComisionPlanController::class, 'index']);
    Route::post('comisiones-planes',                   [ComisionPlanController::class, 'store'])->middleware('role:admin');
    Route::put('comisiones-planes/{comisionesPlan}',   [ComisionPlanController::class, 'update'])->middleware('role:admin');
    Route::delete('comisiones-planes/{comisionesPlan}',[ComisionPlanController::class, 'destroy'])->middleware('role:admin');
    Route::post('comisiones-planes/recalcular',        [ComisionPlanController::class, 'recalcularMasivo'])->middleware('role:admin');
    Route::get('config-comisiones',                        [ConfigComisionesController::class, 'index'])->middleware('role:admin');
    Route::put('config-comisiones/tarifas',                [ConfigComisionesController::class, 'guardarTarifas'])->middleware('role:admin');
    Route::put('config-comisiones/rangos-productividad',   [ConfigComisionesController::class, 'guardarRangosProductividad'])->middleware('role:admin');
    Route::put('config-comisiones/rangos-servicio',        [ConfigComisionesController::class, 'guardarRangosServicio'])->middleware('role:admin');

    // ── Configuración Empresa ─────────────────────────────────────────────────
    Route::get('configuracion',             [ConfiguracionController::class, 'show'])->middleware('role:admin');
    Route::get('configuracion/con-logo',    [ConfiguracionController::class, 'showConLogo'])->middleware('role:admin');
    Route::put('configuracion',             [ConfiguracionController::class, 'update'])->middleware('role:admin');
    Route::post('configuracion/logo',       [ConfiguracionController::class, 'updateLogo'])->middleware('role:admin');
    Route::delete('configuracion/logo',     [ConfiguracionController::class, 'deleteLogo'])->middleware('role:admin');

    // ── RENIEC DNI ────────────────────────────────────────────────────────────
    Route::get('dni/{dni}',                 [DniController::class, 'consultar']);

    Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar'])->middleware('role:admin');

    // ── Agentes — acciones adicionales ───────────────────────────────────────
    Route::get('agentes/{agente}/ventas',              [AgenteController::class, 'ventas'])->middleware('role:admin');
    Route::get('agentes/{agente}/comisiones',          [AgenteController::class, 'comisiones'])->middleware('role:admin');
    Route::patch('agentes/{id}/fechas-laborales',      [AgenteController::class, 'editarFechasLaborales'])->middleware('role:admin');
    Route::post('agentes/{id}/token-seguridad',        [AgenteController::class, 'tokenSeguridad'])->middleware('role:admin');
    Route::get('agentes/{id}/adelantos',               [AgenteController::class, 'adelantos'])->middleware('role:admin');
    Route::post('agentes/{id}/adelantos',              [AgenteController::class, 'registrarAdelanto'])->middleware('role:admin');
    Route::delete('agentes/{id}/adelantos/{adelantoId}', [AgenteController::class, 'eliminarAdelanto'])->middleware('role:admin');
    Route::get('agentes/{id}/perfil-rrhh',              [AgenteController::class, 'perfilRrhh'])->middleware('role:admin');
    Route::put('agentes/{id}/perfil-rrhh',              [AgenteController::class, 'actualizarPerfilRrhh'])->middleware('role:admin');
    Route::get('agentes/{id}/boletas',                  [AgenteController::class, 'boletas'])->middleware('role:admin');
    Route::get('agentes/{id}/seguridad',                [AgenteController::class, 'estadoSeguridad'])->middleware('role:admin');
    Route::post('agentes/{id}/reset-dispositivo',       [AgenteController::class, 'resetDispositivo'])->middleware('role:admin');

    // ── Planilla ─────────────────────────────────────────────────────────────
    Route::get('planilla/{mes}/exportar',           [PlanillaController::class, 'exportarExcel'])->middleware('role:admin');
    Route::get('planilla/{mes}',                    [PlanillaController::class, 'calcular'])->middleware('role:admin');
    Route::post('planilla/ajuste',                  [PlanillaController::class, 'guardarAjuste'])->middleware('role:admin');
    Route::post('planilla/ajuste/reset-comisiones', [PlanillaController::class, 'resetarComisiones'])->middleware('role:admin');

    // ── CRM ──────────────────────────────────────────────────────────────────
    Route::get('crm/pipeline',               [LeadController::class, 'pipeline']);
    Route::apiResource('leads',              LeadController::class);
    Route::get('leads/{lead}/interacciones', [LeadController::class, 'interacciones']);
    Route::post('leads/{lead}/interacciones',[LeadController::class, 'agregarInteraccion']);

    // ── Estadísticas ─────────────────────────────────────────────────────────
    Route::get('estadisticas/ventas',       [EstadisticasController::class, 'ventas'])->middleware('role:admin');
    Route::get('estadisticas/exportar',     [EstadisticasController::class, 'exportar'])->middleware('role:admin');
    Route::get('estadisticas/productividad',[EstadisticasController::class, 'productividad'])->middleware('role:admin');
    Route::get('estadisticas/ranking/subfiltros', [EstadisticasController::class, 'subfiltrosRanking'])->middleware('role:admin');
    Route::get('estadisticas/ranking',      [EstadisticasController::class, 'rankingAgentes'])->middleware('role:admin');

    // ── Reporte BCP ───────────────────────────────────────────────────────────
    Route::get('reporte-bcp',         [ReporteBcpController::class, 'index'])->middleware('role:admin');
    Route::post('reporte-bcp',        [ReporteBcpController::class, 'store'])->middleware('open.shift');
    Route::get('reporte-bcp/tiendas', [ReporteBcpController::class, 'tiendas']);

    // ── Usuarios ──────────────────────────────────────────────────────────────
    Route::apiResource('usuarios', UsuarioController::class)->middleware('role:admin');

    // ── Tiendas ───────────────────────────────────────────────────────────────
    Route::apiResource('tiendas', TiendaController::class)->middleware('role:admin');

    // ── Bipay ─────────────────────────────────────────────────────────────────
    Route::get('bipay/saldo',          [BipayController::class, 'saldo']);
    Route::get('bipay/transacciones',  [BipayController::class, 'transacciones']);
    Route::post('bipay/recarga',       [BipayController::class, 'recarga'])->middleware('role:admin');
    Route::post('bipay/transferir',    [BipayController::class, 'transferir'])->middleware('role:admin');
    Route::post('bipay/ajustar',       [BipayController::class, 'ajustar'])->middleware('role:admin');
    Route::post('bipay/cuentas',       [BipayController::class, 'crearCuenta'])->middleware('role:admin');
    Route::put('bipay/cuentas/{id}',   [BipayController::class, 'editarCuenta'])->middleware('role:admin');
    Route::delete('bipay/cuentas/{id}', [BipayController::class, 'eliminarCuenta'])->middleware('role:admin');
    Route::get('bipay/cajero/estado',       [BipayController::class, 'estadoCajero']);
    Route::post('bipay/cajero/actualizar',  [BipayController::class, 'actualizarCajero']);
    Route::post('bipay/cajero/cierre',      [BipayController::class, 'cierreCajero']);

    // ── Tickets Emitidos ─────────────────────────────────────────────────────
    Route::get('tickets',              [TicketController::class, 'index']);
    Route::get('tickets/{id}',         [TicketController::class, 'show']);
    Route::post('tickets',             [TicketController::class, 'store'])->middleware('open.shift');
    Route::patch('tickets/{id}',       [TicketController::class, 'update']);
    Route::delete('tickets/{id}',      [TicketController::class, 'destroy']);

    // ── Postulaciones (admin) ─────────────────────────────────────────────────
    Route::get('postulaciones',          [PostulanteController::class, 'index'])->middleware('role:admin');
    Route::get('postulaciones/{id}',     [PostulanteController::class, 'show'])->middleware('role:admin');
    Route::post('postulaciones/{id}/aprobar', [PostulanteController::class, 'aprobar'])->middleware('role:admin');
    Route::patch('postulaciones/{id}',   [PostulanteController::class, 'update'])->middleware('role:admin');
    Route::delete('postulaciones/{id}',  [PostulanteController::class, 'destroy'])->middleware('role:admin');

    // ── Diagnóstico (admin) ───────────────────────────────────────────────────
    Route::get('diagnostico',                       [DiagnosticoController::class, 'index'])->middleware('role:admin');

    // ── Panel Financieras ─────────────────────────────────────────────────────
    Route::get('financieras',                               [PanelFinancierasController::class, 'index'])->middleware('role:admin');
    Route::post('financieras/{id}/confirmar-desembolso',    [PanelFinancierasController::class, 'confirmarDesembolso'])->middleware('role:admin');
    Route::post('financieras/{id}/revertir-desembolso',     [PanelFinancierasController::class, 'revertirDesembolso'])->middleware('role:admin');

    // ── Chips (gestión interna) ───────────────────────────────────────────────
    Route::get('chips',                         [ChipsController::class, 'index']);
    Route::post('chips',                        [ChipsController::class, 'store']);
    Route::post('chips/{id}/cambiar-codigo',    [ChipsController::class, 'cambiarCodigo']);
    Route::delete('chips/{id}',                 [ChipsController::class, 'destroy']);
    Route::get('chips/{id}/historial',          [ChipsController::class, 'historial']);
    Route::post('chips/{id}/ajustar-stock-real',[ChipsController::class, 'ajustarStockReal'])->middleware('role:admin');


    // ── Traslados de Equipos ──────────────────────────────────────────────────
    Route::get('traslados/pendientes-aprobacion', [TrasladoController::class, 'pendientesAprobacion']);
    Route::get('traslados',                       [TrasladoController::class, 'index']);
    Route::post('traslados/lote/{codigoLote}/confirmar', [TrasladoController::class, 'confirmarLote']);
    Route::get('traslados/{id}',                  [TrasladoController::class, 'show']);
    Route::post('traslados',                      [TrasladoController::class, 'store']);
    Route::post('traslados/{id}/confirmar',       [TrasladoController::class, 'confirmar']);
    Route::post('traslados/{id}/gestionar',       [TrasladoController::class, 'gestionar']);

    // ── Traslados de Chips ────────────────────────────────────────────────────
    Route::get('traslados-chips',                       [TrasladoChipsController::class, 'index']);
    Route::post('traslados-chips',                      [TrasladoChipsController::class, 'store']);
    Route::post('traslados-chips/{id}/confirmar',       [TrasladoChipsController::class, 'confirmar']);
    Route::post('traslados-chips/{id}/gestionar',       [TrasladoChipsController::class, 'gestionar']);
    Route::get('inventario-chips',                      [TrasladoChipsController::class, 'inventario']);

    // ── Constancias PDF ──────────────────────────────────────────────────────
    Route::get('constancias/traslado',    [ConstanciaController::class, 'traslado']);
    Route::get('constancias/agente/{id}', [ConstanciaController::class, 'agente']);
    Route::get('constancias/reporte/{id}',[ConstanciaController::class, 'reporte']);
    Route::get('constancias/boleta/{id}',   [ConstanciaController::class, 'boleta']);
    Route::post('constancias/boleta',       [ConstanciaController::class, 'crearBoleta']);
    Route::patch('constancias/boleta/{id}', [ConstanciaController::class, 'accionBoleta']);

    // ── Asistencias (panel admin) ─────────────────────────────────────────────
    Route::get('asistencias',                        [AsistenciaController::class, 'index'])->middleware('role:admin');
    Route::post('asistencias',                       [AsistenciaController::class, 'registrar'])->middleware('role:admin');
    Route::post('asistencias/{id}/aprobar',          [AsistenciaController::class, 'aprobar'])->middleware('role:admin');
    Route::get('asistencias/exportar',               [AsistenciaController::class, 'exportar'])->middleware('role:admin');
    Route::get('asistencias/fotos-pendientes',       [AsistenciaController::class, 'fotosPendientes'])->middleware('role:admin');
    Route::post('asistencias/{id}/photo-action',     [AsistenciaController::class, 'photoAction'])->middleware('role:admin');
    Route::get('attendance/qr-stream/{tienda_id}',   [AsistenciaController::class, 'qrStream']);
    Route::get('asistencias/mis-tardanzas',           [AsistenciaController::class, 'misTardanzas']);
    Route::get('asistencias/mi-historial',             [AsistenciaController::class, 'miHistorial']);
    Route::post('asistencias/salvavidas',             [AsistenciaController::class, 'salvavidas']);
    Route::post('asistencias/excepcion',              [AsistenciaController::class, 'registrarExcepcion'])->middleware('role:admin');
    Route::patch('asistencias/{id}',                  [AsistenciaController::class, 'editar'])->middleware('role:admin');
    Route::delete('asistencias/{id}',                 [AsistenciaController::class, 'eliminar'])->middleware('role:admin');
});
