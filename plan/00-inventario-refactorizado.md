# Inventario REAL del sistema refactorizado — verificado contra código

**Fecha:** 2026-07-08 · **Agente:** worker dev3 (inventario) · **Método:** lectura directa de
`backend/routes/api.php` (389 líneas), listado de controllers/models/migrations/services,
`frontend/src/App.tsx` + `pages/`, y contraste contra los 3 docs de paridad de `docs/comparacion/`.

**Nota de skills:** las skills `headroom` y `superpowers` NO existen en el entorno de esta cuenta
worker (no aparecen en la lista de skills invocables). Se continuó sin ellas.

**Stack real verificado:** Laravel **12** (`composer.json: laravel/framework ^12.0`, PHP ^8.2) +
React **19.2** + TypeScript 6.0 + Vite 5.4. Iconos: **lucide-react** (única librería de iconos en
`package.json`). Estado/datos: `@tanstack/react-query` + store propio (`src/store`).
*(El propio CLAUDE.md dice "Laravel 11 + React 18" en un párrafo y "Laravel 12 + React 19" en otro —
lo correcto es 12/19.)*

---

## 1. Módulos implementados VERIFICADOS

| Módulo | Backend (controller / service) | Frontend (página) | Estado real |
|---|---|---|---|
| Auth + PIN + dispositivo | `AuthController`, `DispositivoController`, middleware `role:`/`open.shift` | `LoginPage` | **Completo** (verify-pin, fingerprint, throttle) |
| Dashboard + Control Center | `DashboardController`, `ControlCenterController` | `DashboardPage`, `ControlCenterPanel` | **Completo** (kpis, anomalías, export Excel) |
| Reportes / cuadre diario | `ReporteController` (~1400+ líneas), `ReporteBorradorController`, `HistorialController` | `ReportesPage`, `NuevoReportePage`, `ReporteDetallePage`, `EditarReportePage`, `MiHistorialPage`, `HistorialPage` | **Completo** (borrador, edición autorizada, reprocesar, export Excel, auditoría `edicion_critica`/`edicion_restaurada`, chips descontados, modo Dios — test `ModoDiosTest`) |
| Inventario equipos | `InventarioController`, `MatrizInventarioController`, `BitacoraStockController` | `InventarioPage`, `MatrizInventarioPage`, `KardexInventarioPage`, `BitacoraStockPage`, `RevisarStockPage` | **Completo** (kardex, matriz, precios, restaurar+anular venta, precio-agente rol tienda, stock estancado) |
| Chips | `ChipsController`, `TrasladoChipsController` | `ChipsGestionPage` | **Completo** (historial, ajuste stock real, comando `inventario:migrar-chips-mal-guardados`) |
| Traslados equipos+chips | `TrasladoController`, `TrasladoChipsController`, `ConstanciaController` | `TrasladosPage` (chips redirige aquí) | **Completo** (identidad emisor, gerente directo, lote atómico, constancia PDF; 5 tests de traslados) |
| Agentes / RRHH | `AgenteController`, `AgenteDocumentoController`, `HistorialAgenteService`, `AgenteService` | `AgentesPage`, `VerAgentePage`, `AgenteForm` | **Completo** (adelantos, perfil RRHH, boletas, documentos, token seguridad, reset dispositivo, baja con auditoría `historial_agentes`, ficha técnica export) |
| Asistencias | `AsistenciaController` (~1400 líneas), `AsistenciaNeiryController`, crons `SalidaAutomaticaAsistencias`/`LimpiarFotosAsistencia`/`AutoRetornoAgentes` | `AsistenciasPage`, `ControlAsistenciasPage` (matriz), `TerminalAsistenciaPage`, `QrDisplayPage`, `RevisarFotosPage`, `HistorialLiquidacionPage` | **Completo** (GPS+geocerca editable, QR stream, foto, facial, salvavidas, excepciones FALTA/PERMISO/PERDONAR, excepción jornada, turno corrido, log de ediciones) |
| Planilla / comisiones | `PlanillaController`, `ComisionPlanController`, `ConfigComisionesController`, `ComisionService`, `ComisionOperativaService` | `PlanillaPage`, `ComisionesPage` | **Completo** (CD08, adelantos, ajustes, tarifas operativas + rangos productividad/servicio con UI) |
| Bipay | `BipayController`, `AuditoriaBipayController` + `AuditoriaBipayService`, cron `AuditoriaNocturnaBipay` | `PanelBipayPage`, `BipayConsole` | **Completo** (CRUD cuentas, huérfanas, locks, cajero, cierre, auditoría nocturna + webhooks Discord/Slack) |
| Cuadre Bitel ERP | `CuadreBitelController`, `CuadreBitelService` | `CuadreBitelPage` (ruta redirige a `/panel-bipay`) | **Completo** (panel, rango, global, turno, apoyos) |
| Integrador Bitel on-premise | `IntegradorController` (M2M: agente-config, recibir-saldo/morosidad/histórico) | `IntegradorPage` | **Parcial por diseño — módulo CONGELADO** (decisión 2026-07-04): endpoints y credenciales operativos, `descargarAgente` devuelve 503 si faltan binarios; entrega del agente pendiente como sub-proyecto |
| CRM | `LeadController`, `CrmTemperaturaController` + `Crm/TemperaturaCalculator`, `ClienteCrmController` | `CrmPage` (tabs pipeline + temperatura) | **Completo** (coexistencia deliberada leads.estado + temperatura legacy) |
| Estadísticas / ranking | `EstadisticasController`, `RankingVentaScope` | `EstadisticasPage` | **Completo** (categoría/subfiltros drill-down, exclusión remate/upgrade centralizada, export con medallas) |
| Financieras | `PanelFinancierasController` | `PanelFinancierasPage` | **Completo** (confirmar/revertir desembolso con lock + auditoría + diálogo preview) |
| Tickets | `TicketController` | `TicketsPage`, `TicketImpresionPage` | **Completo** (térmico 58/80mm por usuario, vinculado a venta) |
| Postulaciones | `PostulanteController` | `PostulacionPublicaPage` (pública), `PostulacionesPage` (admin) | **Completo** |
| Postpago monitor | `PostpagoController` | `PostpagoPage` | **Completo** |
| Mapa de calor | `MapaCalorController` | `MapaCalorPage` | **Completo** (calendario, geográfico, horario) |
| Comprobantes / facturación | `ComprobanteController`, `GreenterService` | `ComprobantesPage` | **Completo** (reenviar; Greenter para SUNAT) |
| Reporte BCP | `ReporteBcpController` | `ReporteBcpPage` | **Completo** |
| Usuarios / Tiendas / Config | `UsuarioController`, `TiendaController`, `ConfiguracionController`, `DiagnosticoController` | `UsuariosPage`, `TiendasPage`, `ConfiguracionPage`, `DiagnosticoPage` | **Completo** (radio geocerca, dirección/teléfono, logo empresa) |
| Consultas externas | `DniController` (RENIEC + caché crm_clientes), `RucController` (SUNAT) | consumidos desde formularios | **Completo** |
| Clientes | `ClienteController` | `ClientesPage`, `ClienteForm` | **Completo** (CRUD apiResource) |

44 controllers API · 9 services (+1 en `Crm/`) · 5 comandos console (crons) · **~66 tests Feature**.

---

## 2. Endpoints API reales (agrupados; `routes/api.php`, prefijo `/api/v1`)

Total ≈ **185 endpoints** (150+ rutas explícitas + 7 apiResource). El CLAUDE.md dice "17 rutas" — **obsoleto por ~10x**.

- **Públicos:** `GET health` · `POST auth/login`, `auth/verify-pin` · `POST autorizar-dispositivo` · `POST postulaciones`, `GET postulaciones/tiendas` · terminal asistencia: `GET attendance/status/{dni}`, `POST attendance/mark|mark-qr|mark-photo`, `POST asistencias/turno-corrido` · integrador M2M: `POST integrador/agente-config|recibir-saldo|recibir-morosidad|recibir-bitel-historico` (auth propia por token/API-key).
- **Auth (sanctum):** `GET auth/me`, `POST auth/logout`.
- **Dashboard:** `kpis`, `anomalias`, `exportar`, `control-center`, `marcar-notificacion`.
- **Reportes:** apiResource + `mis-reportes`, `vendedores`, borrador (GET/POST/DELETE), `destino-efectivo`, `agregar-venta`, `eliminar-venta`, `cabecera`, `solicitar/aprobar/denegar-edicion`, `reprocesar`, `historial`, `exportar-excel`, `fijar-costo`.
- **Historial / Bitácora:** `historial` (+kpis, exportar; rol admin,tienda) · `bitacora-stock` (+kpis, exportar, corregir).
- **Agentes:** apiResource + `select`, `exportar`, `exportar-ficha`, `historial`, `ventas`, `comisiones`, `fechas-laborales`, `token-seguridad`, `adelantos` (GET/POST/DELETE), `liquidacion-asistencias`, `perfil-rrhh` (GET/PUT), `boletas`, `seguridad`, `reset-dispositivo`, `documentos` (GET/POST/DELETE).
- **Inventario:** CRUD + `kardex`, `stock-estancado`, `campana-costos`, `precios-pendientes`, `precios-matriz`, `exportar-kardex`, `matriz`, `exportar`, `ajustar-stock-real`, `restaurar`, `recalcular-ganancias`, `precio-agente` · **Chips:** CRUD + `cambiar-codigo`, `historial`, `ajustar-stock-real` · `inventario-chips`.
- **Traslados:** equipos (index, show, store, confirmar, gestionar, `pendientes-aprobacion`, `lote/{codigo}/confirmar`) · chips (index, store, confirmar, gestionar) · constancias PDF (traslado, agente, reporte, boleta GET/POST/PATCH).
- **Planilla / comisiones:** `planilla/{mes}` (+exportar), `ajuste`, `reset-comisiones` · `comisiones-planes` CRUD + `recalcular` · `config-comisiones` (GET) + `tarifas`, `rangos-productividad`, `rangos-servicio` (PUT).
- **Asistencias (admin):** index, registrar, aprobar, exportar, `fotos-pendientes`, `photo-action`, `qr-stream/{tienda}`, `mis-tardanzas`, `mi-historial`, `salvavidas`, `excepcion`, `matriz`, `excepcion-jornada`, PATCH/DELETE `{id}`, `exportar-neiry`.
- **Bipay:** `saldo`, `transacciones` (+exportar), `locks-activos`, `recarga`, `transferir`, `ajustar`, cuentas (POST/PUT/DELETE + `vincular-huerfana`), cajero (estado/actualizar/cierre) · **Auditoría Bipay:** index, `cruce`, webhook (GET/PUT), `resolver-conflicto`, `{id}/detalles`, `{id}/ajustar`.
- **Cuadre Bitel:** `panel`, `rango`, `global`, `turno`, `movimientos-dia`, apoyos (POST/DELETE).
- **Integrador (gestión):** credenciales (GET/POST/regenerar-token/toggle), `descargar-agente`, `solicitar-extraccion`, `solicitar-bitel-historico`, `morosidad`.
- **CRM:** `crm/dashboard`, `crm/pipeline`, leads apiResource + interacciones (GET/POST), `crm/temperatura` (+`/{dni}`), `clientes-crm/{dni}` (GET/POST).
- **Estadísticas:** `ventas`, `exportar`, `productividad`, `ranking`, `ranking/subfiltros`.
- **Otros:** `postpago/*` (3) · `heatmap/*` (3) · `financieras` (+confirmar/revertir desembolso) · `tickets` CRUD+exportar · `postulaciones` admin (5) · `reporte-bcp` (3) · `dni/{dni}`, `ruc/{ruc}` · `diagnostico` · `configuracion` (5) · usuarios/tiendas/clientes/ventas/comprobantes apiResource + `tiendas/select`, `agentes/select`, comprobantes `reenviar`.

---

## 3. Tablas del esquema nuevo (44 migraciones)

**Framework:** users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs, personal_access_tokens.

**Negocio:** usuarios, agentes, clientes, planilla_ajustes, leads, interacciones_crm, tiendas, reportes, ventas, venta_equipos, venta_lineas, inventario_tiendas, comprobantes, comisiones_planes, asistencias, historial_inventario, historial_reportes, inventario_chips, traslados_stock, traslados_chips, postulantes_temp, tickets_emitidos, pagos_planilla, reportes_borradores, adelantos, config_comisiones, comisiones_rangos, reporte_salidas, reporte_comisiones_operativas, venta_chip_movimientos, asistencia_intentos_fallidos, log_fraude_dispositivo, excepciones_jornada, historial_agentes, log_ediciones_asistencia.

**Integrador (migración 2026-07-02, 14 tablas):** integrador_credenciales, bitel_movimientos_diarios, bitel_operaciones_detalle, bitel_apoyos, bitel_historico_queue, solicitudes_extraccion, clientes_estado, lineas_morosidad, tesoreria_clasificacion, auditoria_cierres, sys_config, log_resolucion_atribucion, crm_clientes, crm_interacciones.

**Total ≈ 58 tablas de negocio + 9 framework.** Migraciones idempotentes (guards `hasTable`/`hasColumn`).

**Legacy sin equivalente:** según `gap_db_schema_2026-07-02.md` quedaban solo 2 tablas sin plan
(`log_ediciones_asistencia`, `excepciones_jornada`) — **ambas ya tienen migración y consumidores**
(2026-07-03 y 2026-07-04). A nivel de código **no queda ninguna tabla legacy sin equivalente**.
⚠️ Lo pendiente es **operativo**: confirmar `php artisan migrate:status` en el VPS (ver §5.1).

---

## 4. Páginas React (36 páginas, `App.tsx` con lazy loading)

| Ruta | Página | Acceso | Estado |
|---|---|---|---|
| `/login` | LoginPage | público | Completo |
| `/terminal` | TerminalAsistenciaPage | público | Completo |
| `/postular` | PostulacionPublicaPage | público | Completo |
| `/tickets/imprimir/:id` | TicketImpresionPage | público | Completo |
| `/` | DashboardPage | auth | Completo |
| `/mi-historial` | MiHistorialPage | auth | Completo (salvavidas + panel equipo jefe de tienda, scoping fail-closed) |
| `/reportes/nuevo`, `/:id`, `/:id/editar` | Nuevo/Detalle/EditarReportePage | auth | Completo |
| `/clientes`, `/inventario`, `/traslados`, `/inventario/matriz`, `/chips-gestion`, `/bitacora-stock`, `/reporte-bcp`, `/tickets`, `/integrador`, `/historial`, `/estadisticas` | páginas homónimas | auth (tienda scoped en backend) | Completo (Integrador: UI completa, módulo backend congelado) |
| `/traslados-chips` → `/traslados`, `/cuadre-bitel` → `/panel-bipay` | redirects | — | — |
| `/reportes`, `/panel-bipay`, `/agentes`, `/agentes/:id`, `/asistencias`, `/asistencias/control`, `/asistencias/liquidacion`, `/asistencias/qr`, `/revisar-fotos`, `/revisar-stock`, `/planilla`, `/comisiones`, `/inventario/kardex`, `/financieras`, `/crm`, `/postpago`, `/mapa-calor`, `/diagnostico`, `/comprobantes`, `/admin/postulaciones`, `/usuarios`, `/tiendas`, `/configuracion` | 23 páginas admin | AdminRoute | Completo |

`VerAgentePage` incluye adelantos, boletas, perfil RRHH (27 refs a boletas/perfil-rrhh verificadas).
`ComisionesPage` consume `rangos-productividad`/`rangos-servicio` (editores de rangos presentes).

---

## 5. DISCREPANCIAS docs ↔ código (sección crítica)

Sorpresa: la mayoría de discrepancias son en sentido **inverso** — los docs son más pesimistas que el código, porque hubo commits posteriores a su fecha.

1. **⚠️ ÚNICA PENDIENTE REAL (operativa, no de código):** ejecución en el **VPS de producción** de
   (a) `php artisan migrate` para las migraciones nuevas (la de 14 tablas del integrador +
   posteriores de julio) y (b) `php artisan inventario:migrar-chips-mal-guardados --force`
   (dry-run por defecto; sin correr, chips históricos siguen invisibles). Ningún doc del repo
   contiene evidencia (`migrate:status`) de que se haya hecho. **No verificable desde local.**
2. **VERIFICACION_PARIDAD_2026-07-04 §8.1 dice `edicion_restaurada` "❌ ABIERTO / no portado" → FALSO hoy:**
   implementado en `ReporteController` (líneas 68, 370, 1254-1262) + test `ReporteRestauracionComisionTest`.
3. **VERIFICACION_PARIDAD §6 dice `log_ediciones_asistencia` "❌ sin migración ni plan" → FALSO hoy:**
   migración `2026_07_04_000002` existe y `AsistenciaController` escribe en ella.
4. **GAPS_PENDIENTES_v2 marca T1.2 (panel jefe de tienda), T1.3 (editores de rangos UI) y T2.5
   (boletas/ficha RRHH en VerAgente) como "pendiente UI" → CERRADOS hoy:** MiHistorialPage tiene
   panel de equipo (test `MiHistorialEquipoScopingTest`, commits `2fde424`/`e50da32`), ComisionesPage
   tiene los editores, VerAgentePage tiene boletas + RRHH. El handoff del CLAUDE.md (2026-06-14)
   también quedó obsoleto en estos 3 puntos.
5. **CLAUDE.md dice "17 rutas API" → hay ~185.** Y mezcla Laravel 11/React 18 con 12/19 (real: 12/19).
6. **Decisiones de producto aún abiertas (confirmado, sin cambio):** 4.3 vocabulario de 5 estados de
   `marcar_entregado`; 4.4 bloqueo de eliminar reporte aprobado (sigue en `ReporteController::destroy`,
   con test `ReporteDestroyAprobadoTest` que lo consolida como regla de facto); 4.5 `sys_config` vs
   `configuracion_empresa` (coexisten sin conflicto).
7. **Módulo 7 (integrador on-premise) sigue CONGELADO** por decisión: endpoints M2M y tests existen,
   pero la entrega de binarios del agente (`storage/app/integrador/agente/` → 503 si falta) es
   sub-proyecto futuro con 4 decisiones registradas en el spec 2026-07-04.

---

## 6. Stubs y deuda técnica

- **Cero `TODO`/`FIXME`/`stub`/`not implemented` literales** en `backend/app`, `backend/routes` y
  `frontend/src` (búsqueda estricta; los matches iniciales eran falsos positivos en español:
  "método", "todos").
- Deuda menor conocida (de docs, sin urgencia):
  - `LimpiarFotosAsistencia`: la rama de borrado en disco es código muerto (fotos se guardan
    base64 en BD); el UPDATE sí funciona (T4.4).
  - `markQr` firma con `app.key` en vez de secret dedicado (T4.2) — diferencia de formato, no bug.
  - `CrmTemperaturaController` calcula temperatura de todos los candidatos en memoria y pagina la
    colección (comentario en línea 42) — posible costo con volumen alto.
  - Dos rutas inline con closure en `api.php` (`agentes/select`, `tiendas/select`) — funcionales,
    pero rompen `route:cache` si algún día se usa (los closures no son cacheables).
- Gaps Tier 3/4 del doc v2 no re-verificados uno a uno en esta pasada (T3.2 ajuste maestro
  inventario, T3.5 GET token activo, T3.6 recálculo masivo operativo, T3.7 multi-IMEI/series_info,
  T4.1 exports CSV vs Excel) — **estado desconocido**, tratar como posiblemente abiertos hasta
  verificar (varios pueden haberse cerrado en las fases B/C/D de 2026-06-15).

---

## 7. PENDIENTE (no cubierto en esta pasada)

- Re-verificación individual de los gaps Tier 3/4 listados arriba (§6 último punto).
- Ejecución/estado real de migraciones en el VPS (imposible desde local; requiere SSH).
- Lectura profunda de `backend/app/Services/*` línea a línea (se verificó existencia y consumidores,
  no la lógica interna de cada service).
- `routes/web.php` y `routes/console.php` no inventariados en detalle (API es la superficie principal;
  los 5 comandos console sí están listados en §1).
