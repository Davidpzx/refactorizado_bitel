<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BipayController;
use App\Http\Controllers\Api\BitacoraStockController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComisionPlanController;
use App\Http\Controllers\Api\ConfigComisionesController;
use App\Http\Controllers\Api\ComprobanteColaController;
use App\Http\Controllers\Api\ComprobanteColaPublicoController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\ConfiguracionController;
use App\Http\Controllers\Api\ControlCenterController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DniController;
use App\Http\Controllers\Api\EstadisticasController;
use App\Http\Controllers\Api\FacturacionConfigController;
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
use App\Http\Controllers\Api\PostpagoController;
use App\Http\Controllers\Api\MapaCalorController;
use App\Http\Controllers\Api\AgenteDocumentoController;
use App\Http\Controllers\Api\AsistenciaNeiryController;
use App\Http\Controllers\Api\AuditoriaBipayController;
use App\Http\Controllers\Api\ClienteCrmController;
use App\Http\Controllers\Api\CrmTemperaturaController;
use App\Http\Controllers\Api\CuadreBitelController;
use App\Http\Controllers\Api\IntegradorController;
use App\Http\Controllers\Api\RucController;
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
Route::post('/v1/asistencias/turno-corrido', [AsistenciaController::class, 'turnoCorrido'])->middleware('throttle:60,1');

// ── Integrador Bitel — endpoints máquina-a-máquina (agente local → servidor) ──
// Autenticación propia: token de tienda (agente-config) o API key + timestamp.
Route::prefix('v1/integrador')->middleware('throttle:120,1')->group(function () {
    Route::post('agente-config',           [IntegradorController::class, 'agenteConfig']);
    Route::post('recibir-saldo',           [IntegradorController::class, 'recibirSaldo']);
    Route::post('recibir-morosidad',       [IntegradorController::class, 'recibirMorosidad']);
    Route::post('recibir-bitel-historico', [IntegradorController::class, 'recibirBitelHistorico']);
});

// ── CPE público — link firmado HMAC para WhatsApp (sin sesión) ───────────────
// Port de `reportes/cpe_publico.php` / `reportes/imprimir_comprobante.php`. La
// autorización es la firma (exp+firma), no un middleware de auth.
Route::prefix('v1/cpe')->middleware('throttle:60,1')->group(function () {
    Route::get('{id}',                  [ComprobanteColaPublicoController::class, 'show']);
    Route::get('{id}/descargar/{tipo}', [ComprobanteColaPublicoController::class, 'descargar']);
});

// ── Recursos protegidos ──────────────────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::get('auth/me',      [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // ── Dashboard ────────────────────────────────────────────────────────────
    Route::get('dashboard/kpis',      [DashboardController::class, 'kpis']);
    Route::get('dashboard/anomalias', [DashboardController::class, 'anomalias'])->middleware('role:admin');
    Route::get('dashboard/exportar',  [DashboardController::class, 'exportar'])->middleware('role:admin');
    Route::get('control-center',      [ControlCenterController::class, 'index']);
    Route::post('marcar-notificacion', [ControlCenterController::class, 'marcarNotificacion']);

    // ── Historial Completo (admin ve todo; tienda ve solo su propia tienda) ───
    Route::get('historial',           [HistorialController::class, 'index'])->middleware('role:admin,tienda');
    Route::get('historial/kpis',      [HistorialController::class, 'kpis'])->middleware('role:admin,tienda');
    Route::get('historial/exportar',  [HistorialController::class, 'exportar'])->middleware('role:admin,tienda');

    // ── Bitácora de Stock ─────────────────────────────────────────────────────
    Route::get('bitacora-stock',           [BitacoraStockController::class, 'index']);
    Route::get('bitacora-stock/kpis',      [BitacoraStockController::class, 'kpis']);
    Route::get('bitacora-stock/exportar',  [BitacoraStockController::class, 'exportar']);
    Route::post('bitacora-stock/corregir', [BitacoraStockController::class, 'corregir'])->middleware('role:admin');

    // ── Reportes — rutas especiales ANTES del apiResource ────────────────────
    Route::get('reportes/mis-reportes', [ReporteController::class, 'misReportes']);
    Route::get('reportes/vendedores', [ReporteController::class, 'vendedores']);
    Route::get('reportes/borrador', [ReporteBorradorController::class, 'show'])->middleware('open.shift');
    Route::post('reportes/borrador', [ReporteBorradorController::class, 'store'])->middleware('open.shift');
    Route::delete('reportes/borrador', [ReporteBorradorController::class, 'destroy'])->middleware('open.shift');
    Route::post('reportes/borrador/eliminar', [ReporteBorradorController::class, 'destroy'])->middleware('open.shift');

    Route::patch('reportes/{reporte}/destino-efectivo', [ReporteController::class, 'actualizarDestino']);
    Route::post('reportes/{reporte}/agregar-venta',    [ReporteController::class, 'agregarVenta']);
    Route::delete('reportes/{reporte}/ventas/{venta}', [ReporteController::class, 'eliminarVenta']);
    Route::patch('reportes/{reporte}/cabecera',        [ReporteController::class, 'actualizarCabecera']);
    Route::post('reportes/{reporte}/solicitar-edicion', [ReporteController::class, 'solicitarEdicion']);
    Route::post('reportes/{reporte}/aprobar-edicion',        [ReporteController::class, 'aprobarEdicion']);
    Route::post('reportes/{reporte}/denegar-edicion',         [ReporteController::class, 'denegarEdicion']);
    Route::put('reportes/{reporte}/reprocesar',             [ReporteController::class, 'reprocesar']);
    Route::get('reportes/{reporte}/historial',              [ReporteController::class, 'historial']);
    Route::get('reportes/{reporte}/exportar-excel',          [ReporteController::class, 'exportarExcel']);
    Route::post('reporte-categorias/{id}/fijar-costo',      [ReporteController::class, 'fijarCosto']);

    Route::get('reportes', [ReporteController::class, 'index'])->middleware('role:admin');
    Route::post('reportes', [ReporteController::class, 'store'])->middleware(['role:admin,tienda', 'open.shift']);
    Route::get('reportes/{reporte}', [ReporteController::class, 'show']);
    Route::match(['put', 'patch'], 'reportes/{reporte}', [ReporteController::class, 'update']);
    Route::delete('reportes/{reporte}', [ReporteController::class, 'destroy']);

    // ── Otros recursos ────────────────────────────────────────────────────────
    // Custom agentes routes BEFORE apiResource to avoid {agente} wildcard conflict
    // Endpoint ligero accesible a todos los autenticados (para selects/dropdowns)
    Route::get('agentes/select', fn() => response()->json(
        \App\Models\Agente::where('estado', 'ACTIVO')->orderBy('nombres')->get(['id', 'nombres', 'dni'])
    ));
    Route::get('agentes/exportar',              [MatrizInventarioController::class, 'exportarAgentes'])->middleware('role:admin');
    Route::get('agentes/exportar-ficha',        [AgenteController::class, 'exportarFichaTecnica'])->middleware('role:admin');
    // show() valida tienda_base internamente (admin ve todo, no-admin solo su propia tienda)
    Route::apiResource('agentes', AgenteController::class)->except(['show'])->middleware('role:admin');
    Route::get('agentes/{agente}', [AgenteController::class, 'show']);
    Route::apiResource('clientes',    ClienteController::class);
    // Custom inventario routes MUST come before apiResource to avoid {inventario} wildcard conflict
    Route::get('inventario/kardex',          [InventarioController::class, 'kardex']);
    Route::get('inventario/stock-estancado', [InventarioController::class, 'stockEstancado']);
    Route::get('inventario/campana-costos',  [InventarioController::class, 'campanaCostos']);
    Route::get('inventario/precios-pendientes', [InventarioController::class, 'preciosPendientes']);
    Route::get('inventario/precios-matriz',     [InventarioController::class, 'preciosMatriz'])->middleware('role:admin');
    Route::get('inventario/exportar-kardex', [InventarioController::class, 'exportarKardex']);
    Route::get('inventario/matriz',          [MatrizInventarioController::class, 'index']);
    Route::get('inventario/exportar',        [MatrizInventarioController::class, 'exportar']);
    Route::get('inventario', [InventarioController::class, 'index']);
    Route::post('inventario', [InventarioController::class, 'store']);
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
    Route::post('configuracion/sync-logo-facturacion', [ConfiguracionController::class, 'syncLogoFacturacion'])->middleware('role:admin');

    // ── Facturación electrónica — emisión ─────────────────────────────────────
    // Único camino de emisión síncrona: encola y drena esa misma fila. Cualquier
    // usuario autenticado puede pedirlo (el cajero entrega la boleta en el acto);
    // el resto de la cola la drena `facturacion:procesar-cola` cada minuto.
    Route::post('comprobantes-cola/emitir-ahora', [ComprobanteColaController::class, 'emitirAhora']);

    // Link público firmado (WhatsApp): cualquier autenticado puede generarlo, igual
    // que puede "emitir ahora" — es el mismo cajero entregando el comprobante.
    Route::post('comprobantes-cola/{id}/link', [ComprobanteColaController::class, 'link']);

    // ── Facturación electrónica — nota de crédito, anulación y descarga (admin) ─
    // Todas operan sobre `comprobantes_cola` (ticket 005), no sobre la tabla
    // Greenter `comprobantes`. La NC/anulación SOLO encolan o confirman contra la
    // API; nunca cambian el estado local antes de que la API responda.
    Route::middleware('role:admin')->group(function () {
        Route::get('comprobantes-cola',                       [ComprobanteColaController::class, 'index']);
        Route::post('comprobantes-cola/{id}/nota-credito',   [ComprobanteColaController::class, 'notaCredito']);
        Route::post('comprobantes-cola/{id}/anular',         [ComprobanteColaController::class, 'anular']);
        Route::get('comprobantes-cola/{id}/descargar/{tipo}', [ComprobanteColaController::class, 'descargar']);
    });

    // ── Facturación electrónica — configuración multi-emisor ──────────────────
    // `configure-sunat` va antes del wildcard `{facturacionConfig}` para que no
    // lo capture como id.
    Route::middleware('role:admin')->group(function () {
        Route::post('facturacion-config/configure-sunat',        [FacturacionConfigController::class, 'configureSunat']);
        Route::get('facturacion-config',                         [FacturacionConfigController::class, 'index']);
        Route::post('facturacion-config',                        [FacturacionConfigController::class, 'store']);
        Route::get('facturacion-config/{facturacionConfig}',     [FacturacionConfigController::class, 'show']);
        Route::match(['put', 'patch'], 'facturacion-config/{facturacionConfig}', [FacturacionConfigController::class, 'update']);
        Route::delete('facturacion-config/{facturacionConfig}',  [FacturacionConfigController::class, 'destroy']);
    });

    // ── RENIEC DNI ────────────────────────────────────────────────────────────
    Route::get('dni/{dni}',                 [DniController::class, 'consultar']);

    Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar'])->middleware('role:admin');

    // ── Agentes — acciones adicionales ───────────────────────────────────────
    Route::get('agentes/{id}/historial',               [AgenteController::class, 'historial'])->middleware('role:admin');
    Route::get('agentes/{agente}/ventas',              [AgenteController::class, 'ventas'])->middleware('role:admin');
    Route::get('agentes/{agente}/comisiones',          [AgenteController::class, 'comisiones'])->middleware('role:admin');
    Route::patch('agentes/{id}/fechas-laborales',      [AgenteController::class, 'editarFechasLaborales'])->middleware('role:admin');
    Route::post('agentes/{id}/token-seguridad',        [AgenteController::class, 'tokenSeguridad'])->middleware('role:admin');
    Route::get('agentes/{id}/adelantos',               [AgenteController::class, 'adelantos'])->middleware('role:admin');
    Route::post('agentes/{id}/adelantos',              [AgenteController::class, 'registrarAdelanto'])->middleware('role:admin');
    Route::delete('agentes/{id}/adelantos/{adelantoId}', [AgenteController::class, 'eliminarAdelanto'])->middleware('role:admin');
    Route::get('agentes/{id}/liquidacion-asistencias', [AsistenciaController::class, 'liquidacionAsistencias'])->middleware('role:admin');
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

    // ── Monitor Postpago ─────────────────────────────────────────────────────
    Route::get('postpago/resumen',   [PostpagoController::class, 'resumen'])->middleware('role:admin');
    Route::get('postpago/ventas',    [PostpagoController::class, 'ventas'])->middleware('role:admin');
    Route::get('postpago/exportar',  [PostpagoController::class, 'exportar'])->middleware('role:admin');

    // ── CRM ──────────────────────────────────────────────────────────────────
    Route::get('crm/dashboard',              [LeadController::class, 'dashboard']);
    Route::get('crm/pipeline',               [LeadController::class, 'pipeline']);
    Route::apiResource('leads',              LeadController::class);
    Route::get('leads/{lead}/interacciones', [LeadController::class, 'interacciones']);
    Route::post('leads/{lead}/interacciones',[LeadController::class, 'agregarInteraccion']);

    // ── CRM: temperatura calculada (paridad legacy crm_clientes/crm_interacciones) ──
    Route::get('crm/temperatura',           [CrmTemperaturaController::class, 'index']);
    Route::get('crm/temperatura/exportar',  [CrmTemperaturaController::class, 'exportar']);
    Route::get('crm/temperatura/{dni}',     [CrmTemperaturaController::class, 'porDni']);

    // ── Estadísticas (admin ve todo; tienda ve solo su propia tienda) ─────────
    Route::get('estadisticas/ventas',       [EstadisticasController::class, 'ventas'])->middleware('role:admin,tienda');
    Route::get('estadisticas/exportar',     [EstadisticasController::class, 'exportar'])->middleware('role:admin,tienda');
    Route::get('estadisticas/productividad',[EstadisticasController::class, 'productividad'])->middleware('role:admin,tienda');
    Route::get('estadisticas/ranking/subfiltros', [EstadisticasController::class, 'subfiltrosRanking'])->middleware('role:admin,tienda');
    Route::get('estadisticas/ranking',      [EstadisticasController::class, 'rankingAgentes'])->middleware('role:admin,tienda');

    // ── Reporte BCP ───────────────────────────────────────────────────────────
    Route::get('reporte-bcp',         [ReporteBcpController::class, 'index'])->middleware('role:admin');
    Route::post('reporte-bcp',        [ReporteBcpController::class, 'store'])->middleware('open.shift');
    Route::get('reporte-bcp/tiendas', [ReporteBcpController::class, 'tiendas']);

    // ── Usuarios ──────────────────────────────────────────────────────────────
    Route::apiResource('usuarios', UsuarioController::class)->middleware('role:admin');

    // ── Tiendas ───────────────────────────────────────────────────────────────
    // Endpoint ligero accesible a todos los autenticados (para selects/dropdowns)
    Route::get('tiendas/select', fn() => response()->json(
        \App\Models\Tienda::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre'])
    ));
    Route::apiResource('tiendas', TiendaController::class)->middleware('role:admin');

    // ── Bipay ─────────────────────────────────────────────────────────────────
    Route::get('bipay/saldo',          [BipayController::class, 'saldo']);
    Route::get('bipay/transacciones',  [BipayController::class, 'transacciones']);
    Route::get('bipay/transacciones/exportar', [BipayController::class, 'exportarTransacciones'])->middleware('role:admin');
    Route::get('bipay/locks-activos',  [BipayController::class, 'locksActivos'])->middleware('role:admin');
    Route::post('bipay/recarga',       [BipayController::class, 'recarga'])->middleware('role:admin');
    Route::post('bipay/transferir',    [BipayController::class, 'transferir'])->middleware('role:admin');
    Route::post('bipay/ajustar',       [BipayController::class, 'ajustar'])->middleware('role:admin');
    Route::post('bipay/cuentas',       [BipayController::class, 'crearCuenta'])->middleware('role:admin');
    Route::put('bipay/cuentas/{id}',   [BipayController::class, 'editarCuenta'])->middleware('role:admin');
    Route::delete('bipay/cuentas/{id}', [BipayController::class, 'eliminarCuenta'])->middleware('role:admin');
    Route::post('bipay/cuentas/{id}/vincular-huerfana', [BipayController::class, 'vincularHuerfana'])->middleware('role:admin');
    Route::get('bipay/cajero/estado',       [BipayController::class, 'estadoCajero']);
    Route::post('bipay/cajero/actualizar',  [BipayController::class, 'actualizarCajero']);
    Route::post('bipay/cajero/cierre',      [BipayController::class, 'cierreCajero']);

    // ── Tickets Emitidos ─────────────────────────────────────────────────────
    Route::get('tickets',              [TicketController::class, 'index']);
    Route::get('tickets/exportar',     [TicketController::class, 'exportar']);
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

    // ── Mapa de Calor (admin) ─────────────────────────────────────────────────
    Route::get('heatmap/calendario', [MapaCalorController::class, 'calendario'])->middleware('role:admin');
    Route::get('heatmap/geografico', [MapaCalorController::class, 'geografico'])->middleware('role:admin');
    Route::get('heatmap/horario',    [MapaCalorController::class, 'horario'])->middleware('role:admin');

    // ── Integrador Bitel — gestión (admin / tienda propia) ───────────────────
    Route::get('integrador/credenciales',                        [IntegradorController::class, 'credenciales'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales',                       [IntegradorController::class, 'guardarCredenciales'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales/{codigo}/regenerar-token', [IntegradorController::class, 'regenerarToken'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales/{codigo}/toggle',       [IntegradorController::class, 'toggleActivo'])->middleware('role:admin');
    Route::get('integrador/descargar-agente',                    [IntegradorController::class, 'descargarAgente'])->middleware('role:admin,tienda');
    Route::post('integrador/solicitar-extraccion',               [IntegradorController::class, 'solicitarExtraccion'])->middleware('role:admin');
    Route::post('integrador/solicitar-bitel-historico',          [IntegradorController::class, 'solicitarBitelHistorico'])->middleware('role:admin');
    Route::get('integrador/morosidad',                           [IntegradorController::class, 'morosidad'])->middleware('role:admin');

    // ── Cuadre Bitel ERP (admin) ──────────────────────────────────────────────
    Route::get('cuadre-bitel/panel',            [CuadreBitelController::class, 'panel'])->middleware('role:admin');
    Route::get('cuadre-bitel/rango',            [CuadreBitelController::class, 'rango'])->middleware('role:admin');
    Route::get('cuadre-bitel/global',           [CuadreBitelController::class, 'global'])->middleware('role:admin');
    Route::get('cuadre-bitel/turno',            [CuadreBitelController::class, 'turno'])->middleware('role:admin');
    Route::get('cuadre-bitel/movimientos-dia',  [CuadreBitelController::class, 'movimientosDia'])->middleware('role:admin');
    Route::post('cuadre-bitel/apoyos',          [CuadreBitelController::class, 'confirmarApoyo'])->middleware('role:admin');
    Route::delete('cuadre-bitel/apoyos',        [CuadreBitelController::class, 'eliminarApoyo'])->middleware('role:admin');

    // ── Auditoría Bipay (admin) ───────────────────────────────────────────────
    Route::get('auditoria-bipay',                     [AuditoriaBipayController::class, 'index'])->middleware('role:admin');
    Route::post('auditoria-bipay/cruce',              [AuditoriaBipayController::class, 'ejecutarCruce'])->middleware('role:admin');
    Route::get('auditoria-bipay/webhook',             [AuditoriaBipayController::class, 'webhookConfig'])->middleware('role:admin');
    Route::put('auditoria-bipay/webhook',             [AuditoriaBipayController::class, 'guardarWebhook'])->middleware('role:admin');
    Route::post('auditoria-bipay/resolver-conflicto', [AuditoriaBipayController::class, 'resolverConflicto'])->middleware('role:admin');
    Route::get('auditoria-bipay/{id}/detalles',       [AuditoriaBipayController::class, 'detalles'])->middleware('role:admin');
    Route::post('auditoria-bipay/{id}/ajustar',       [AuditoriaBipayController::class, 'ajustar'])->middleware('role:admin');

    // ── Cliente Activo CRM (cuadre) ───────────────────────────────────────────
    Route::get('clientes-crm/{dni}', [ClienteCrmController::class, 'buscar']);
    Route::post('clientes-crm',      [ClienteCrmController::class, 'guardar']);

    // ── SUNAT RUC ─────────────────────────────────────────────────────────────
    Route::get('ruc/{ruc}', [RucController::class, 'consultar']);

    // ── Documentos del agente (foto perfil / DNI) ─────────────────────────────
    Route::get('agentes/{id}/documentos',             [AgenteDocumentoController::class, 'ver'])->middleware('role:admin');
    Route::post('agentes/{id}/documentos',            [AgenteDocumentoController::class, 'subir'])->middleware('role:admin');
    Route::delete('agentes/{id}/documentos/{campo}',  [AgenteDocumentoController::class, 'eliminar'])->middleware('role:admin');

    // ── Export plantilla Neiry ────────────────────────────────────────────────
    Route::get('asistencias/exportar-neiry', [AsistenciaNeiryController::class, 'exportar'])->middleware('role:admin');

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
    Route::get('asistencias/matriz',                  [AsistenciaController::class, 'matriz'])->middleware('role:admin');
    Route::post('asistencias/excepcion-jornada',      [AsistenciaController::class, 'excepcionJornada'])->middleware('role:admin');
    Route::patch('asistencias/{id}',                  [AsistenciaController::class, 'editar'])->middleware('role:admin');
    Route::delete('asistencias/{id}',                 [AsistenciaController::class, 'eliminar'])->middleware('role:admin');
});
