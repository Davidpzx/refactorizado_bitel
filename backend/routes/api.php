<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AppTerminalController;
use App\Http\Controllers\Api\AsistenciaController;
use App\Http\Controllers\Api\AsistenciaPresenciaController;
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
use App\Http\Controllers\Api\CrmEstadisticasController;
use App\Http\Controllers\Api\CrmTemperaturaController;
use App\Http\Controllers\Api\CuadreBitelController;
use App\Http\Controllers\Api\IntegradorController;
use App\Http\Controllers\Api\RucController;
use Illuminate\Support\Facades\Route;

// ── Health (público) ─────────────────────────────────────────────────────────
// SEC-12: no exponer entorno/nombre de app — Dokploy solo necesita el 200.
Route::get('/v1/health', fn() => response()->json(['status' => 'ok']));

// ── Auth (público) ───────────────────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login',      [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('verify-pin', [AuthController::class, 'verifyPin'])->middleware(['throttle:20,1', 'throttle:verify-pin']);
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
    // SEC-13: throttle propio más estricto — el grupo comparte 60/min con status/mark/mark-qr,
    // suficiente para llenar disco lentamente con fotos si se abusa del endpoint público.
    Route::post('mark-photo',   [AsistenciaController::class, 'markPhoto'])->middleware('throttle:10,1');
    // APP-04 — ping de presencia (app nativa; autenticado por device_hash + agente).
    Route::post('ping-ubicacion', [AsistenciaPresenciaController::class, 'pingUbicacion']);
    // APP-08 — consentimiento de rastreo, requerido por ping-ubicacion antes de aceptar pings.
    Route::post('consentimiento-ubicacion', [AsistenciaPresenciaController::class, 'registrarConsentimiento']);
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

// ── App Terminal — distribución del APK de la app de asistencia (público) ────
// APP-09a. `subir` (protegida, admin) vive en el grupo autenticado más abajo.
Route::prefix('v1/app-terminal')->group(function () {
    Route::get('version',    [AppTerminalController::class, 'version'])->middleware('throttle:60,1');
    Route::get('descargar',  [AppTerminalController::class, 'descargar'])->middleware('throttle:30,1');
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
    Route::get('dashboard/anomalias', [DashboardController::class, 'anomalias'])->middleware('role:administrador,gerente');
    Route::get('dashboard/exportar',  [DashboardController::class, 'exportar'])->middleware(['role:administrador,gerente', 'throttle:exports']);
    Route::get('control-center',      [ControlCenterController::class, 'index']);
    Route::post('marcar-notificacion', [ControlCenterController::class, 'marcarNotificacion']);

    // ── Historial Completo (admin ve todo; tienda ve solo su propia tienda) ───
    Route::get('historial',           [HistorialController::class, 'index'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('historial/kpis',      [HistorialController::class, 'kpis'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('historial/exportar',  [HistorialController::class, 'exportar'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:exports']);

    // ── Bitácora de Stock ─────────────────────────────────────────────────────
    Route::get('bitacora-stock',           [BitacoraStockController::class, 'index']);
    Route::get('bitacora-stock/kpis',      [BitacoraStockController::class, 'kpis']);
    Route::get('bitacora-stock/exportar',  [BitacoraStockController::class, 'exportar'])->middleware('throttle:exports');
    Route::post('bitacora-stock/corregir', [BitacoraStockController::class, 'corregir'])->middleware('role:admin');

    // ── Reportes — rutas especiales ANTES del apiResource ────────────────────
    // R3: +agente (scoping "solo lo mío" por agente_id — ver R3)
    Route::get('reportes/mis-reportes', [ReporteController::class, 'misReportes'])->middleware('role:administrador,gerente,jefe_tienda,agente');
    Route::get('reportes/vendedores', [ReporteController::class, 'vendedores']);
    // R3: +agente (scoping "solo lo mío" por agente_id — ver R3)
    Route::get('reportes/borrador', [ReporteBorradorController::class, 'show'])->middleware(['open.shift', 'role:administrador,gerente,jefe_tienda,agente']);
    Route::post('reportes/borrador', [ReporteBorradorController::class, 'store'])->middleware(['open.shift', 'role:administrador,gerente,jefe_tienda,agente']);
    Route::delete('reportes/borrador', [ReporteBorradorController::class, 'destroy'])->middleware(['open.shift', 'role:administrador,gerente,jefe_tienda,agente']);
    Route::post('reportes/borrador/eliminar', [ReporteBorradorController::class, 'destroy'])->middleware(['open.shift', 'role:administrador,gerente,jefe_tienda,agente']);

    Route::patch('reportes/{reporte}/destino-efectivo', [ReporteController::class, 'actualizarDestino']);
    Route::post('reportes/{reporte}/agregar-venta',    [ReporteController::class, 'agregarVenta']);
    Route::delete('reportes/{reporte}/ventas/{venta}', [ReporteController::class, 'eliminarVenta']);
    Route::patch('reportes/{reporte}/cabecera',        [ReporteController::class, 'actualizarCabecera']);
    Route::post('reportes/{reporte}/solicitar-edicion', [ReporteController::class, 'solicitarEdicion']);
    Route::post('reportes/{reporte}/aprobar-edicion',        [ReporteController::class, 'aprobarEdicion']);
    Route::post('reportes/{reporte}/denegar-edicion',         [ReporteController::class, 'denegarEdicion']);
    Route::put('reportes/{reporte}/reprocesar',             [ReporteController::class, 'reprocesar']);
    Route::get('reportes/{reporte}/historial',              [ReporteController::class, 'historial']);
    Route::get('reportes/{reporte}/exportar-excel',          [ReporteController::class, 'exportarExcel'])->middleware('throttle:exports');
    Route::post('reporte-categorias/{id}/fijar-costo',      [ReporteController::class, 'fijarCosto']);

    Route::get('reportes', [ReporteController::class, 'index'])->middleware('role:admin');
    // R3: +agente (crea el suyo — ver R3)
    Route::post('reportes', [ReporteController::class, 'store'])->middleware(['role:admin,tienda,gerente,agente', 'open.shift']);
    Route::get('reportes/{reporte}', [ReporteController::class, 'show']);
    Route::match(['put', 'patch'], 'reportes/{reporte}', [ReporteController::class, 'update']);
    Route::delete('reportes/{reporte}', [ReporteController::class, 'destroy']);

    // ── Otros recursos ────────────────────────────────────────────────────────
    // Custom agentes routes BEFORE apiResource to avoid {agente} wildcard conflict
    // Endpoint ligero accesible a todos los autenticados (para selects/dropdowns).
    // SEC-11: el DNI completo del padrón NO viaja — TrasladosPage matchea por los
    // últimos 4 dígitos (dni_ultimos4) contra lo que el agente autorizador teclea.
    Route::get('agentes/select', fn() => response()->json(
        \App\Models\Agente::where('estado', 'ACTIVO')->orderBy('nombres')->get(['id', 'nombres', 'dni'])
            ->map(fn ($a) => [
                'id' => $a->id,
                'nombres' => $a->nombres,
                'dni_ultimos4' => substr((string) $a->dni, -4),
            ])
    ));
    Route::get('agentes/exportar',              [MatrizInventarioController::class, 'exportarAgentes'])->middleware(['role:admin', 'throttle:exports']);
    Route::get('agentes/exportar-ficha',        [AgenteController::class, 'exportarFichaTecnica'])->middleware(['role:admin', 'throttle:exports']);
    // show() valida tienda_base internamente (admin ve todo, no-admin solo su propia tienda)
    Route::apiResource('agentes', AgenteController::class)->except(['show'])->middleware('role:admin');
    Route::get('agentes/{agente}', [AgenteController::class, 'show']);
    Route::apiResource('clientes',    ClienteController::class)->middleware('role:admin,tienda'); // SEC-03
    // Custom inventario routes MUST come before apiResource to avoid {inventario} wildcard conflict
    // SEC-03: todo el dominio inventario (costos, stock, altas) queda restringido a admin/tienda;
    // las rutas que ya eran role:admin siguen siéndolo (el middleware compone: intersección).
    Route::get('inventario/kardex',          [InventarioController::class, 'kardex'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/stock-estancado', [InventarioController::class, 'stockEstancado'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/capital-invertido', [InventarioController::class, 'capitalInvertido'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/campana-costos',  [InventarioController::class, 'campanaCostos'])->middleware('role:administrador,gerente,jefe_tienda');
    // precios-pendientes es solo lectura del listado que la propia tienda registró sin
    // precio (paridad legacy tienda/guardar_stock.php) — la tienda necesita verlo para
    // saber qué le falta que gerencia fije. La anticorrupción (plan 16) aplica a FIJAR
    // el precio (precios-matriz/inventario update), no a esta lectura.
    Route::get('inventario/precios-pendientes', [InventarioController::class, 'preciosPendientes'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/precios-matriz',     [InventarioController::class, 'preciosMatriz'])->middleware('role:administrador,gerente');
    Route::get('inventario/exportar-kardex', [InventarioController::class, 'exportarKardex'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:exports']);
    Route::get('inventario/matriz',          [MatrizInventarioController::class, 'index'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/exportar',        [MatrizInventarioController::class, 'exportar'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:exports']);
    Route::get('inventario', [InventarioController::class, 'index'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::post('inventario', [InventarioController::class, 'store'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('inventario/{inventario}', [InventarioController::class, 'show'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::match(['put', 'patch'], 'inventario/{inventario}', [InventarioController::class, 'update'])->middleware('role:administrador,gerente');
    Route::delete('inventario/{inventario}', [InventarioController::class, 'destroy'])->middleware('role:admin');
    Route::post('inventario/{id}/ajustar-stock-real', [InventarioController::class, 'ajustarStockReal'])->middleware('role:admin');
    Route::post('inventario/{id}/restaurar',            [InventarioController::class, 'restaurar'])->middleware('role:admin');
    Route::post('inventario/{id}/recalcular-ganancias', [InventarioController::class, 'recalcularGanancias'])->middleware('role:admin');
    Route::post('inventario/{id}/precio-agente',        [InventarioController::class, 'fijarPrecioAgente'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::apiResource('ventas',      VentaController::class)->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03
    Route::apiResource('comprobantes', ComprobanteController::class)->middleware('role:administrador,gerente,jefe_tienda');

    // ── Comisiones de Planes ──────────────────────────────────────────────────
    // R3 (decisión): este endpoint es el catálogo de TARIFAS por plan (config global,
    // igual para todos), no las comisiones ganadas por un agente — no hay "solo las
    // suyas" que aplicar aquí. El agente ve sus totales propios via GET
    // reportes/mis-reportes (ya scopeado por agente_id); no existe un endpoint de
    // "comisiones-del-agente" separado, así que se documenta el uso de mis-reportes
    // como la vía para "sus comisiones" en vez de crear uno nuevo.
    Route::get('comisiones-planes',                    [ComisionPlanController::class, 'index'])->middleware('role:administrador,gerente,jefe_tienda,agente');
    Route::post('comisiones-planes',                   [ComisionPlanController::class, 'store'])->middleware('role:admin');
    Route::put('comisiones-planes/{comisionesPlan}',   [ComisionPlanController::class, 'update'])->middleware('role:admin');
    Route::delete('comisiones-planes/{comisionesPlan}',[ComisionPlanController::class, 'destroy'])->middleware('role:admin');
    Route::post('comisiones-planes/recalcular',        [ComisionPlanController::class, 'recalcularMasivo'])->middleware('role:admin');
    Route::get('config-comisiones',                        [ConfigComisionesController::class, 'index'])->middleware('role:administrador,gerente');
    Route::put('config-comisiones/tarifas',                [ConfigComisionesController::class, 'guardarTarifas'])->middleware('role:administrador,gerente');
    Route::put('config-comisiones/rangos-productividad',   [ConfigComisionesController::class, 'guardarRangosProductividad'])->middleware('role:administrador,gerente');
    Route::put('config-comisiones/rangos-servicio',        [ConfigComisionesController::class, 'guardarRangosServicio'])->middleware('role:administrador,gerente');

    // ── Configuración Empresa ─────────────────────────────────────────────────
    Route::get('configuracion',             [ConfiguracionController::class, 'show'])->middleware('role:administrador,gerente');
    Route::get('configuracion/con-logo',    [ConfiguracionController::class, 'showConLogo'])->middleware('role:administrador,gerente');
    Route::put('configuracion',             [ConfiguracionController::class, 'update'])->middleware('role:administrador,gerente');
    Route::post('configuracion/logo',       [ConfiguracionController::class, 'updateLogo'])->middleware('role:administrador,gerente');
    Route::delete('configuracion/logo',     [ConfiguracionController::class, 'deleteLogo'])->middleware('role:administrador,gerente');
    Route::post('configuracion/sync-logo-facturacion', [ConfiguracionController::class, 'syncLogoFacturacion'])->middleware('role:administrador,gerente');

    // ── App Terminal — subir nueva versión del APK (SOLO administrador — plan 16) ─
    Route::post('app-terminal/subir', [AppTerminalController::class, 'subir'])->middleware('role:administrador');

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

    // ── Facturación electrónica — configuración multi-emisor (SOLO administrador — plan 16) ──
    // `configure-sunat` va antes del wildcard `{facturacionConfig}` para que no
    // lo capture como id.
    Route::middleware('role:administrador')->group(function () {
        Route::post('facturacion-config/configure-sunat',        [FacturacionConfigController::class, 'configureSunat']);
        Route::get('facturacion-config',                         [FacturacionConfigController::class, 'index']);
        Route::post('facturacion-config',                        [FacturacionConfigController::class, 'store']);
        Route::get('facturacion-config/{facturacionConfig}',     [FacturacionConfigController::class, 'show']);
        Route::match(['put', 'patch'], 'facturacion-config/{facturacionConfig}', [FacturacionConfigController::class, 'update']);
        Route::delete('facturacion-config/{facturacionConfig}',  [FacturacionConfigController::class, 'destroy']);
    });

    // ── RENIEC DNI ────────────────────────────────────────────────────────────
    Route::get('dni/{dni}',                 [DniController::class, 'consultar'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:30,1']);

    Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar'])->middleware('role:administrador,gerente,jefe_tienda');

    // ── Agentes — acciones adicionales ───────────────────────────────────────
    Route::get('agentes/{id}/historial',               [AgenteController::class, 'historial'])->middleware('role:admin');
    Route::get('agentes/{agente}/ventas',              [AgenteController::class, 'ventas'])->middleware('role:admin');
    Route::get('agentes/{agente}/comisiones',          [AgenteController::class, 'comisiones'])->middleware('role:admin');
    Route::patch('agentes/{id}/fechas-laborales',      [AgenteController::class, 'editarFechasLaborales'])->middleware('role:admin');
    // Anticorrupción (plan 16): token de emergencia/seguridad es SOLO admin/gerente.
    Route::post('agentes/{id}/token-seguridad',        [AgenteController::class, 'tokenSeguridad'])->middleware('role:administrador,gerente');
    Route::get('agentes/{id}/adelantos',               [AgenteController::class, 'adelantos'])->middleware('role:admin');
    Route::post('agentes/{id}/adelantos',              [AgenteController::class, 'registrarAdelanto'])->middleware('role:admin');
    Route::delete('agentes/{id}/adelantos/{adelantoId}', [AgenteController::class, 'eliminarAdelanto'])->middleware('role:admin');
    Route::get('agentes/{id}/liquidacion-asistencias', [AsistenciaController::class, 'liquidacionAsistencias'])->middleware('role:admin');
    Route::get('agentes/{id}/perfil-rrhh',              [AgenteController::class, 'perfilRrhh'])->middleware('role:administrador,gerente');
    Route::put('agentes/{id}/perfil-rrhh',              [AgenteController::class, 'actualizarPerfilRrhh'])->middleware('role:administrador,gerente');
    Route::get('agentes/{id}/boletas',                  [AgenteController::class, 'boletas'])->middleware('role:admin');
    Route::get('agentes/{id}/seguridad',                [AgenteController::class, 'estadoSeguridad'])->middleware('role:admin');
    Route::post('agentes/{id}/reset-dispositivo',       [AgenteController::class, 'resetDispositivo'])->middleware('role:admin');

    // ── Planilla ─────────────────────────────────────────────────────────────
    Route::get('planilla/{mes}/exportar',           [PlanillaController::class, 'exportarExcel'])->middleware(['role:administrador,gerente', 'throttle:exports']);
    Route::get('planilla/{mes}',                    [PlanillaController::class, 'calcular'])->middleware('role:administrador,gerente');
    Route::post('planilla/ajuste',                  [PlanillaController::class, 'guardarAjuste'])->middleware('role:administrador,gerente');
    Route::post('planilla/ajuste/reset-comisiones', [PlanillaController::class, 'resetarComisiones'])->middleware('role:administrador,gerente');

    // ── Monitor Postpago ─────────────────────────────────────────────────────
    Route::get('postpago/resumen',   [PostpagoController::class, 'resumen'])->middleware('role:admin');
    Route::get('postpago/ventas',    [PostpagoController::class, 'ventas'])->middleware('role:admin');
    Route::get('postpago/exportar',  [PostpagoController::class, 'exportar'])->middleware(['role:admin', 'throttle:exports']);

    // ── CRM (SEC-03: el CRM completo — leads, pipeline, temperatura — es admin/tienda) ──
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('crm/dashboard',              [LeadController::class, 'dashboard']);
        Route::get('crm/pipeline',               [LeadController::class, 'pipeline']);
        Route::get('crm/estadisticas-resumen',   [CrmEstadisticasController::class, 'resumen']);
        Route::apiResource('leads',              LeadController::class);
        Route::get('leads/{lead}/interacciones', [LeadController::class, 'interacciones']);
        Route::post('leads/{lead}/interacciones',[LeadController::class, 'agregarInteraccion']);

        // ── CRM: temperatura calculada (paridad legacy crm_clientes/crm_interacciones) ──
        Route::get('crm/temperatura',           [CrmTemperaturaController::class, 'index']);
        Route::get('crm/temperatura/exportar',  [CrmTemperaturaController::class, 'exportar'])->middleware('throttle:exports');
        Route::get('crm/temperatura/{dni}',     [CrmTemperaturaController::class, 'porDni']);
    });

    // ── Estadísticas (admin ve todo; tienda ve solo su propia tienda) ─────────
    Route::get('estadisticas/ventas',       [EstadisticasController::class, 'ventas'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('estadisticas/exportar',     [EstadisticasController::class, 'exportar'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:exports']);
    Route::get('estadisticas/productividad',[EstadisticasController::class, 'productividad'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('estadisticas/ranking/subfiltros', [EstadisticasController::class, 'subfiltrosRanking'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('estadisticas/ranking',      [EstadisticasController::class, 'rankingAgentes'])->middleware('role:administrador,gerente,jefe_tienda');

    // ── Reporte BCP ───────────────────────────────────────────────────────────
    Route::get('reporte-bcp',         [ReporteBcpController::class, 'index'])->middleware('role:admin');
    Route::post('reporte-bcp',        [ReporteBcpController::class, 'store'])->middleware('open.shift');
    Route::get('reporte-bcp/tiendas', [ReporteBcpController::class, 'tiendas']);

    // ── Usuarios ──────────────────────────────────────────────────────────────
    Route::apiResource('usuarios', UsuarioController::class)->middleware('role:administrador,gerente');
    // SEC-16: revocación manual de todas las sesiones (compromiso sospechado).
    Route::post('usuarios/{usuario}/revocar-tokens', [UsuarioController::class, 'revocarTokens'])->middleware('role:administrador,gerente');

    // ── Tiendas ───────────────────────────────────────────────────────────────
    // Endpoint ligero accesible a todos los autenticados (para selects/dropdowns)
    Route::get('tiendas/select', fn() => response()->json(
        \App\Models\Tienda::where('activo', true)->orderBy('nombre')->get(['id', 'codigo', 'nombre'])
    ));
    Route::apiResource('tiendas', TiendaController::class)->middleware('role:administrador,gerente');

    // ── Bipay ─────────────────────────────────────────────────────────────────
    Route::get('bipay/saldo',          [BipayController::class, 'saldo'])->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03
    Route::get('bipay/transacciones',  [BipayController::class, 'transacciones'])->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03
    Route::get('bipay/transacciones/exportar', [BipayController::class, 'exportarTransacciones'])->middleware(['role:admin', 'throttle:exports']);
    Route::get('bipay/locks-activos',  [BipayController::class, 'locksActivos'])->middleware('role:admin');
    Route::post('bipay/recarga',       [BipayController::class, 'recarga'])->middleware('role:admin');
    Route::post('bipay/transferir',    [BipayController::class, 'transferir'])->middleware('role:admin');
    Route::post('bipay/ajustar',       [BipayController::class, 'ajustar'])->middleware('role:admin');
    Route::post('bipay/cuentas',       [BipayController::class, 'crearCuenta'])->middleware('role:admin');
    Route::put('bipay/cuentas/{id}',   [BipayController::class, 'editarCuenta'])->middleware('role:admin');
    Route::delete('bipay/cuentas/{id}', [BipayController::class, 'eliminarCuenta'])->middleware('role:admin');
    Route::post('bipay/cuentas/{id}/vincular-huerfana', [BipayController::class, 'vincularHuerfana'])->middleware('role:admin');
    Route::get('bipay/cajero/estado',       [BipayController::class, 'estadoCajero'])->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03
    Route::post('bipay/cajero/actualizar',  [BipayController::class, 'actualizarCajero'])->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03
    Route::post('bipay/cajero/cierre',      [BipayController::class, 'cierreCajero'])->middleware('role:administrador,gerente,jefe_tienda'); // SEC-03

    // ── Tickets Emitidos (SEC-03: admin/gerente/jefe_tienda; scoping por tienda en el controller) ──
    // R3: +agente (crea los suyos) — fuera del grupo de abajo porque ese grupo es admin/gerente/jefe_tienda.
    Route::post('tickets', [TicketController::class, 'store'])
        ->middleware(['open.shift', 'role:administrador,gerente,jefe_tienda,agente']);
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('tickets',              [TicketController::class, 'index']);
        Route::get('tickets/exportar',     [TicketController::class, 'exportar'])->middleware('throttle:exports');
        Route::get('tickets/{id}',         [TicketController::class, 'show']);
        Route::patch('tickets/{id}',       [TicketController::class, 'update']);
        Route::delete('tickets/{id}',      [TicketController::class, 'destroy']);
    });

    // ── Postulaciones (admin/gerente) ─────────────────────────────────────────
    Route::get('postulaciones',          [PostulanteController::class, 'index'])->middleware('role:administrador,gerente');
    Route::get('postulaciones/{id}',     [PostulanteController::class, 'show'])->middleware('role:administrador,gerente');
    Route::post('postulaciones/{id}/aprobar', [PostulanteController::class, 'aprobar'])->middleware('role:administrador,gerente');
    Route::patch('postulaciones/{id}',   [PostulanteController::class, 'update'])->middleware('role:administrador,gerente');
    Route::delete('postulaciones/{id}',  [PostulanteController::class, 'destroy'])->middleware('role:administrador,gerente');

    // ── Diagnóstico (SOLO administrador — plan 16) ────────────────────────────
    Route::get('diagnostico',                       [DiagnosticoController::class, 'index'])->middleware('role:administrador');

    // ── Panel Financieras ─────────────────────────────────────────────────────
    Route::get('financieras',                               [PanelFinancierasController::class, 'index'])->middleware('role:administrador,gerente');
    Route::post('financieras/{id}/confirmar-desembolso',    [PanelFinancierasController::class, 'confirmarDesembolso'])->middleware('role:administrador,gerente');
    Route::post('financieras/{id}/revertir-desembolso',     [PanelFinancierasController::class, 'revertirDesembolso'])->middleware('role:administrador,gerente');

    // ── Chips (gestión interna) — SEC-03: admin/gerente/jefe_tienda; ajustar-stock-real sigue admin ──
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('chips',                         [ChipsController::class, 'index']);
        Route::post('chips',                        [ChipsController::class, 'store']);
        Route::post('chips/{id}/cambiar-codigo',    [ChipsController::class, 'cambiarCodigo']);
        Route::delete('chips/{id}',                 [ChipsController::class, 'destroy']);
        Route::get('chips/{id}/historial',          [ChipsController::class, 'historial']);
        Route::post('chips/{id}/ajustar-stock-real',[ChipsController::class, 'ajustarStockReal'])->middleware('role:admin');
    });


    // ── Traslados de Equipos (SEC-03: admin/gerente/jefe_tienda crean/reciben/consultan; scoping origen/destino en el controller) ──
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('traslados/pendientes-aprobacion', [TrasladoController::class, 'pendientesAprobacion']);
        Route::get('traslados',                       [TrasladoController::class, 'index']);
        Route::post('traslados/lote/{codigoLote}/confirmar', [TrasladoController::class, 'confirmarLote']);
        Route::get('traslados/{id}',                  [TrasladoController::class, 'show']);
        Route::post('traslados',                      [TrasladoController::class, 'store']);
        Route::post('traslados/{id}/confirmar',       [TrasladoController::class, 'confirmar']);

        // ── Traslados de Chips ────────────────────────────────────────────────
        Route::get('traslados-chips',                       [TrasladoChipsController::class, 'index']);
        Route::post('traslados-chips',                      [TrasladoChipsController::class, 'store']);
        Route::post('traslados-chips/{id}/confirmar',       [TrasladoChipsController::class, 'confirmar']);
        Route::get('inventario-chips',                      [TrasladoChipsController::class, 'inventario']);
    });

    // Anticorrupción (plan 16): APROBAR traslado es SOLO admin/gerente — el jefe de
    // tienda crea/recibe pero no aprueba.
    Route::post('traslados/{id}/gestionar', [TrasladoController::class, 'gestionar'])->middleware('role:administrador,gerente');
    Route::post('traslados-chips/{id}/gestionar', [TrasladoChipsController::class, 'gestionar'])->middleware('role:administrador,gerente');

    // ── Constancias PDF (SEC-03: admin/gerente/jefe_tienda; generan documentos con datos sensibles) ──
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('constancias/traslado',    [ConstanciaController::class, 'traslado']);
        Route::get('constancias/agente/{id}', [ConstanciaController::class, 'agente']);
        Route::get('constancias/reporte/{id}',[ConstanciaController::class, 'reporte']);
        Route::get('constancias/boleta/{id}',   [ConstanciaController::class, 'boleta']);
        Route::post('constancias/boleta',       [ConstanciaController::class, 'crearBoleta']);
        Route::patch('constancias/boleta/{id}', [ConstanciaController::class, 'accionBoleta']);
    });

    // ── Mapa de Calor (admin) ─────────────────────────────────────────────────
    Route::get('heatmap/calendario', [MapaCalorController::class, 'calendario'])->middleware('role:admin');
    Route::get('heatmap/geografico', [MapaCalorController::class, 'geografico'])->middleware('role:admin');
    Route::get('heatmap/horario',    [MapaCalorController::class, 'horario'])->middleware('role:admin');

    // ── Integrador Bitel — gestión (admin / tienda propia) ───────────────────
    Route::get('integrador/credenciales',                        [IntegradorController::class, 'credenciales'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales',                       [IntegradorController::class, 'guardarCredenciales'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales/{codigo}/regenerar-token', [IntegradorController::class, 'regenerarToken'])->middleware('role:admin,tienda');
    Route::post('integrador/credenciales/{codigo}/toggle',       [IntegradorController::class, 'toggleActivo'])->middleware('role:administrador');
    Route::get('integrador/descargar-agente',                    [IntegradorController::class, 'descargarAgente'])->middleware('role:admin,tienda');
    Route::post('integrador/solicitar-extraccion',               [IntegradorController::class, 'solicitarExtraccion'])->middleware('role:administrador');
    Route::post('integrador/solicitar-bitel-historico',          [IntegradorController::class, 'solicitarBitelHistorico'])->middleware('role:administrador');
    Route::get('integrador/morosidad',                           [IntegradorController::class, 'morosidad'])->middleware('role:administrador');

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

    // ── Cliente Activo CRM (cuadre) — SEC-03: admin/gerente/jefe_tienda ───────
    Route::middleware('role:administrador,gerente,jefe_tienda')->group(function () {
        Route::get('clientes-crm/{dni}', [ClienteCrmController::class, 'buscar']);
        Route::post('clientes-crm',      [ClienteCrmController::class, 'guardar']);
    });

    // ── SUNAT RUC ─────────────────────────────────────────────────────────────
    Route::get('ruc/{ruc}', [RucController::class, 'consultar'])->middleware(['role:administrador,gerente,jefe_tienda', 'throttle:30,1']);

    // ── Documentos del agente (foto perfil / DNI) ─────────────────────────────
    Route::get('agentes/{id}/documentos',             [AgenteDocumentoController::class, 'ver'])->middleware('role:admin');
    Route::post('agentes/{id}/documentos',            [AgenteDocumentoController::class, 'subir'])->middleware('role:admin');
    Route::delete('agentes/{id}/documentos/{campo}',  [AgenteDocumentoController::class, 'eliminar'])->middleware('role:admin');

    // ── Export plantilla Neiry ────────────────────────────────────────────────
    Route::get('asistencias/exportar-neiry', [AsistenciaNeiryController::class, 'exportar'])->middleware(['role:administrador,gerente', 'throttle:exports']);

    // ── Asistencias (panel admin) ─────────────────────────────────────────────
    // Anticorrupción (plan 16): todo lo que MODIFICA el registro de asistencia
    // (manual, faltas/permisos, corregir horario, aprobar fotos) es SOLO admin/gerente.
    // Lo de SOLO LECTURA (presencia, listado, matriz, fotos-pendientes, fraude) lo
    // ve también el jefe_tienda: role:administrador,gerente,jefe_tienda (R4).
    // APP-04 — semáforo de presencia en vivo (agentes en turno + último ping).
    Route::get('asistencias-admin/presencia',        [AsistenciaPresenciaController::class, 'presencia'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('asistencias',                        [AsistenciaController::class, 'index'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::post('asistencias',                       [AsistenciaController::class, 'registrar'])->middleware('role:administrador,gerente');
    Route::post('asistencias/{id}/aprobar',          [AsistenciaController::class, 'aprobar'])->middleware('role:admin');
    Route::get('asistencias/exportar',               [AsistenciaController::class, 'exportar'])->middleware(['role:administrador,gerente', 'throttle:exports']);
    Route::get('asistencias/fotos-pendientes',       [AsistenciaController::class, 'fotosPendientes'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::get('asistencias/fraude-dispositivos',    [AsistenciaController::class, 'fraudeDispositivos'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::post('asistencias/{id}/photo-action',     [AsistenciaController::class, 'photoAction'])->middleware('role:administrador,gerente');
    Route::get('attendance/qr-stream/{tienda_id}',   [AsistenciaController::class, 'qrStream']);
    Route::get('asistencias/mis-tardanzas',           [AsistenciaController::class, 'misTardanzas']);
    Route::get('asistencias/mi-historial',             [AsistenciaController::class, 'miHistorial']);
    Route::post('asistencias/salvavidas',             [AsistenciaController::class, 'salvavidas']);
    Route::post('asistencias/excepcion',              [AsistenciaController::class, 'registrarExcepcion'])->middleware('role:administrador,gerente');
    Route::get('asistencias/matriz',                  [AsistenciaController::class, 'matriz'])->middleware('role:administrador,gerente,jefe_tienda');
    Route::post('asistencias/excepcion-jornada',      [AsistenciaController::class, 'excepcionJornada'])->middleware('role:administrador,gerente');
    Route::patch('asistencias/{id}',                  [AsistenciaController::class, 'editar'])->middleware('role:administrador,gerente');
    Route::delete('asistencias/{id}',                 [AsistenciaController::class, 'eliminar'])->middleware('role:admin');
});
