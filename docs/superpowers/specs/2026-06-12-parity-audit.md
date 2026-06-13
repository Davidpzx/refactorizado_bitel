# Auditoría de Paridad 1:1 — Legacy PHP → Refactor (Laravel 11 + React 18)

**Fecha:** 2026-06-12
**Legacy:** `C:\xampp\htdocs\refactor_principal` · **Refactor:** `C:\xampp\htdocs\refactorizado_bitel`
**Objetivo:** Verificar paridad pantalla-por-pantalla (visual + funcional) y producir una lista de brechas accionable y priorizada. El refactor está ~90% migrado; esto es la última milla.

## Método

Por cada pantalla:
1. **Gemini** (`ask-gemini`) extrae un checklist de paridad del `.php` legacy (layout, KPIs/badges, lógica de negocio, I/O de endpoints, edge cases).
2. **Claude** lee el `.tsx` (frontend) + el `Controller.php` (backend Laravel) del refactor.
3. **Claude** diffea y registra cada brecha: `VISUAL` / `FUNCIONAL` / `NINGUNA`, con severidad (ALTA/MEDIA/BAJA) y agente sugerido (Codex backend / Claude frontend).

## Severidad
- **ALTA** — riesgo operativo/financiero o pérdida de dato; rompe el flujo de negocio.
- **MEDIA** — comportamiento divergente notable; degrada UX o control.
- **BAJA** — cosmético o edge case poco frecuente.

---

## Mapa de pantallas (legacy → refactor)

| # | Cluster | Legacy `.php` | Refactor `.tsx` | Backend Controller |
|---|---------|---------------|-----------------|--------------------|
| 1 | Asistencias | `asistencia.php`, `procesar_asistencia.php`, `api/registrar_marcacion*.php`, `api/autorizar_dispositivo.php`, `api/registrar_asistencia_qr.php`, `api/verificar_asistencia_hoy.php` | `asistencias/TerminalAsistenciaPage` | `AsistenciaController`, `DispositivoController` |
| 1 | Asistencias | `gerencia/panel_asistencias.php`, `admin_editar_asistencia.php`, `acciones_asistencia.php` | `asistencias/AsistenciasPage` | `AsistenciaController@index/editar` |
| 1 | Asistencias | `tienda/qr_asistencia.php`, `api/generar_qr_asistencia.php` | `asistencias/QrDisplayPage` | `AsistenciaController@qrStream` |
| 2 | Reportes/Cuadre | `reportes/nuevo_reporte.php` + ajax (`procesar_reporte`, `ajax_guardar_borrador`, `ajax_bipay_saldo`, `ajax_salvavidas`, `ajax_guardar_ticket*`) | `reportes/NuevoReportePage` (+ `cuadre/TicketIngresoModal`) | `ReporteController`, `ReporteBorradorController`, `BipayController` |
| 2 | Reportes/Cuadre | `reportes/editar_reporte.php`, `procesar_edicion`, `solicitar_edicion`, `aprobar_edicion`, `autorizar_edicion` | `reportes/EditarReportePage` | `ReporteController` |
| 2 | Reportes/Cuadre | `reportes/mi_historial.php`, `ver_reporte.php`, `imprimir_reporte.php` | `reportes/MiHistorialPage`, `ReporteDetallePage` | `ReporteController`, `ConstanciaController` |
| 2 | Reportes/Cuadre | `gerencia/historial_completo.php` | `reportes/ReportesPage`, `historial/HistorialPage` | `ReporteController`, `HistorialController` |
| 3 | Inventario/Traslados | `tienda/ver_inventario.php`, `api_inventario.php`, `registrar_stock`, `guardar_stock` | `inventario/InventarioPage`, `InventarioForm` | `InventarioController` |
| 3 | Inventario/Traslados | `tienda/matriz_inventario.php`, `descargar_matriz_excel.php` | `inventario/MatrizInventarioPage` | `MatrizInventarioController` |
| 3 | Inventario/Traslados | `tienda/ajax_kardex_inventario.php`, `exportar_kardex_inventario.php` | `inventario/KardexInventarioPage` | `InventarioController@kardex` |
| 3 | Inventario/Traslados | `tienda/ver_bitacora_stock.php`, `procesar_correccion_stock.php` | `inventario/BitacoraStockPage` | `BitacoraStockController` |
| 3 | Inventario/Traslados | `tienda/cambiar_codigo_chip`, `eliminar_chip`, `obtener_historial_chip` | `inventario/ChipsGestionPage` | `ChipsController` |
| 3 | Inventario/Traslados | `tienda/procesar_traslado*.php`, `gestionar_solicitud_traslado`, `confirmar_traslado*`, `constancia_traslado` | `traslados/TrasladosPage`, `TrasladoChipsPage` | `TrasladoController`, `TrasladoChipsController` |
| 4 | Finanzas | `gerencia/panel_bipay.php`, `reportes/ajax_bipay_saldo.php` | `bipay/PanelBipayPage` | `BipayController` |
| 4 | Finanzas | `gerencia/panel_financieras.php`, `confirmar_desembolso.php` | `admin/PanelFinancierasPage` | `PanelFinancierasController` |
| 4 | Finanzas | `gerencia/reporte_bcp.php` | `bcp/ReporteBcpPage` | `ReporteBcpController` |
| 4 | Finanzas | `gerencia/comisiones_empresa.php`, `configurar_comisiones.php`, `recalcular_comisiones_masivo.php` | `comisiones/ComisionesPage` | `ComisionPlanController` |
| 5 | Agentes/Planilla | `gerencia/gestionar_agentes.php`, `ver_agente.php`, `editar_agente.php`, `guardar_agente.php`, `historial_agente.php` | `agentes/AgentesPage`, `VerAgentePage`, `AgenteForm` | `AgenteController` |
| 5 | Agentes/Planilla | `gerencia/planilla_agentes.php`, `ajax_planilla.php`, `exportar_excel_agentes_pro.php` | `planilla/PlanillaPage` | `PlanillaController` |
| 5 | Agentes/Planilla | `public_onboarding.php`, `gerencia/aprobar_postulante.php`, `consulta_dni.php` | `PostulacionPublicaPage`, `admin/PostulacionesPage` | `PostulanteController`, `DniController` |
| 6 | Admin/Config/Stock | `gerencia/usuarios.php`, `guardar_usuario`, `editar_usuario_ajax`, `eliminar_usuario` | `admin/UsuariosPage` | `UsuarioController` |
| 6 | Admin/Config/Stock | `gerencia/tiendas.php`, `editar_tienda.php` | `admin/TiendasPage` | `TiendaController` |
| 6 | Admin/Config/Stock | `gerencia/configuracion_empresa.php` | `admin/ConfiguracionPage` | `ConfiguracionController` |
| 6 | Admin/Config/Stock | `gerencia/revisar_stock.php`, `fijar_precio.php`, `ajax_fijar_costo_rapido.php` | `admin/RevisarStockPage` | `InventarioController`/`ReporteController@fijarCosto` |
| 6 | Admin/Config/Stock | `gerencia/revisar_fotos_asistencia.php` | `admin/RevisarFotosPage` | `AsistenciaController@fotosPendientes/photoAction` |
| 6 | Admin/Config/Stock | `gerencia/diagnostico_tiendas.php` | `admin/DiagnosticoPage` | `DiagnosticoController` |
| 6 | Admin/Config/Stock | `gerencia/tickets_emitidos.php`, `imprimir_boleta.php`, `accion_boleta.php` | `tickets/TicketsPage`, `TicketImpresionPage` | `TicketController`, `ConstanciaController` |
| 7 | Dashboard/Sidebar | `gerencia/panel_gerencia.php`, `index.php` | `DashboardPage` | `DashboardController` |
| 7 | Dashboard/Sidebar | `includes/header.php` (sidebar badges vivos) | `AppLayout` + Control Center | `ControlCenterController` |
| 7 | Dashboard/Sidebar | `auth/login.php` | `auth/LoginPage` | `AuthController` |
| 7 | Estadísticas | `gerencia/estadisticas_ventas.php`, `api/obtener_ranking_agentes.php` | `estadisticas/EstadisticasPage` | `EstadisticasController` |

> Endpoints AJAX puros (procesadores sin UI) se auditan junto a su pantalla padre.

---

## Cluster 1 — Asistencias  ✅ AUDITADO

**Veredicto:** Paridad funcional alta (~90%). El backend (`AsistenciaController`) replica con fidelidad: GPS radio dinámico + "beneficio de la duda" (`distancia - accuracy`), `radioEfectivo *0.8` si accuracy<20, códigos `WEAK_GPS`/`OUT_OF_RANGE`, `registrarIntentoFallido`, cooldowns (`inicio_refrigerio` 60min / resto 5min), QR HMAC ±2 bloques (±10s), 60s tolerancia revocable, `QR_STORE_MISMATCH`, foto zero-retention con compresión a ≤150KB y `foto_marcacion=null` al aprobar, log de fraude de dispositivo, salvavidas semanal, edición admin de tiempos. Frontend tiene huella `kyro-hw-[hash]-[dni]` (cookie 1año + LS), bypass PIN, selector de sede, toggles turno corrido/completo (solo en entrada), fallback GPS→QR/Foto/Token.

### Brechas

| ID | Tipo | Sev | Brecha | Dónde corregir |
|----|------|-----|--------|----------------|
| A1 | FUNCIONAL | MEDIA | **Anti-spoofing "salto imposible"** ausente. Legacy calcula velocidad entre marcaciones; si >200 km/h marca `requiere_revision=1`. El refactor no lo implementa. | Codex → `AsistenciaController@mark` |
| A2 | FUNCIONAL | MEDIA | **Recuperación de `deuda_dias`** ausente. Legacy reduce días de deuda del agente si trabaja en día de descanso o acumula horas extra ≥1.5 jornada. `calcularDeudaYExtra` calcula min_extra/deuda pero no toca `deuda_dias`. | Codex → `AsistenciaController` |
| A3 | FUNCIONAL | BAJA | **60s tolerancia-revocable QR inerte desde el front.** El backend la soporta, pero `TerminalAsistenciaPage` no captura ni envía `hora_intento_gps`/`hora_apertura_camera` en la ruta QR, así que el momento siempre es `NOW()` (se pierde el beneficio). | Claude → `TerminalAsistenciaPage` |
| A4 | VISUAL/FUNC | MEDIA | **QR es input manual de texto, no escáner de cámara.** Legacy `modalQR` escanea el QR con la cámara (jsQR). El refactor solo permite pegar/escribir el token. Degrada UX y obliga a transcribir. | Claude → `EscanerQR` (añadir lectura de cámara) |
| A5 | VISUAL | BAJA | **Token de emergencia** solo accesible vía fallback. Legacy lo expone también como enlace "Usar Token" en la pantalla principal (campo 6 díg colapsable). | Claude → `TerminalAsistenciaPage` |

**Pendiente de auditar en Cluster 1:** `AsistenciasPage` (panel admin) y `QrDisplayPage` vs `panel_asistencias.php` / `qr_asistencia.php` (look glass premium aún marcado pendiente en memoria).

---

## Cluster 2 — Reportes / Cuadre de Caja  ✅ AUDITADO (núcleo backend)

**Veredicto:** El frontend del cuadre fue migrado con detalle (T1–T10: fórmula esperado, sección apoyo, motor de comisiones en vivo, validación stock chips, tickets de ingreso, borrador con fallback localStorage). **Pero el backend `ReporteController@store` no replica reglas de negocio críticas que en el legacy eran server-authoritative.** Aquí está la mayor concentración de riesgo del sistema.

### Brechas

| ID | Tipo | Sev | Brecha | Dónde corregir |
|----|------|-----|--------|----------------|
| B1 | FUNCIONAL | **ALTA** | ✅ **RESUELTO (worktree `fix/cuadre-backend-paridad`).** Legacy `procesar_reporte.php` (líneas 150-165) descuenta `inventario_tiendas` con guardia `rowCount()!==1 ⇒ throw ⇒ rollBack`; chips son *soft* (solo `error_log`). `store()` no tocaba `stock_actual` (confirmado: 0 refs en `ReporteController`/`VentaController`). **Fix aplicado:** decremento transaccional de equipos/accesorios con guardia exacta (throw→rollBack→422 `STOCK_GUARD`), columnas opcionales (estado/fecha_venta/vendido_por_id/reporte_venta_id) guardadas con `Schema::hasColumn`. **Pendiente:** decremento *soft* de chips (postpago unitario / prepago bulk) — requiere flags migración/upgrade/eSIM que el payload aún no envía (ver B2). | Claude ✅ |
| B2 | FUNCIONAL | **ALTA** | **Comisión aún NO recalculada en servidor (parcial).** Legacy (190-211) calcula server-side con tramos exactos: postpago `max(0, base−chip)`; remate (postpago,no-upgrade,0<cobrado<20)→0; upgrade Δ=fee−plan_anterior ≥20→20/≥10→10/else 0; prepago `max(0,base−chip)`; equipo contado `comision_especial` o 0; cuotas/accesorio 0; **apoyo paquete cobrado×0.075**; apoyo upgrade **flat 10**; otros_flujo 0. **Estado (worktree):** Codex añadió a la validación los flags `es_migracion/es_upgrade/es_esim/plan_anterior` y persiste `es_esim`, pero `comision_generada` sigue siendo `comision_unitaria` del cliente × cantidad → **NO server-authoritative**. **Bloqueo confirmado:** el payload NO envía `plan_id`; el front YA tiene el objeto `plan` (calcula con `comision_dni_n`/`fee_monto`, NuevoReportePage L503/510) → falta **enviar `plan_id`** + crear `ComisionService` que haga lookup a `comisiones_planes` y aplique tramos. Fase siguiente (frontend+backend). | Claude → `plan_id` en payload + `ComisionService` |
| B3 | FUNCIONAL | MEDIA | **CORRECCIÓN:** el legacy **NO** calcula `efectivo_esperado` en servidor — lo toma del cliente (`floatval($campos['efectivo_esperado'])`, línea 54); la fórmula vive en el JS del front. El refactor **sí recalcula** en backend con `Σefectivo_inicial + caja_inicial − salidas`, divergiendo del front legacy (que **excluye `caja_inicial`** del esperado y lo usa solo para "total en cajón"). → `efectivo_esperado`/`diferencia` almacenados pueden no coincidir con lo mostrado. **Fix correcto:** alinear el backend a la fórmula del front del refactor (verificar `NuevoReportePage`) **o** confiar en el valor del cliente como el legacy. NO añadir más matemática server-side. Requiere leer la fórmula del front primero. | Claude → tras leer front |
| B4 | FUNCIONAL | MEDIA | **Flujo de edición incompleto.** Legacy `procesar_edicion`: revierte stock → borra categorías/salidas → re-procesa todo + detecta `edicion_critica` (reasignación de vendedor). Refactor: `aprobarEdicion` solo cambia estado a `borrador`; `update()` solo permite editar observaciones/efectivo/destino/estado (no re-editar ventas, sin revert de stock, sin log de fraude de edición). | Claude/Codex → `ReporteController` |
| B5 | FUNCIONAL | MEDIA | ✅ **RESUELTO (worktree).** Legacy (líneas 39-48) impide >1 reporte por (agente, tienda, fecha). **Fix aplicado:** guardia `exists()` antes de la transacción → 422 `DUPLICATE_REPORT`. | Claude ✅ |
| B6 | FUNCIONAL | MEDIA | **`fijarCosto` lee `reporte_categorias`** (tabla JSON legacy) que `store()` nunca puebla (usa esquema normalizado `venta_equipos`). → La campana de costos rápido apunta a una tabla huérfana en el nuevo esquema. Incoherencia de modelo de datos. | Codex → `ReporteController@fijarCosto` |
| B7 | FUNCIONAL | BAJA | **Umbral de aprobación** `abs(diferencia)>10` vs radar de anomalías legacy `>S/5`. Inconsistencia de umbral (afecta badge anomalías del Control Center). | Codex |
| B8 | FUNCIONAL | BAJA | **`destino_efectivo`** default `'TIENDA'` vs legacy `EN_CAJA`/`ENTREGADO`; legacy enruta obs a `obs_cuadre_entregado`/`obs_cuadre_caja`. Verificar que el front mapea bien y que `obs` obligatoria cuando `ENTREGADO`. | Claude/Codex |

| B9 | FUNCIONAL | **ALTA** | ✅ **RESUELTO (worktree).** **Bug latente:** el front envía `tipo_venta='APOYO'` (NuevoReportePage L59/728) pero el enum de validación del backend NO incluía `APOYO` → toda venta de apoyo era **rechazada (422)**. Codex añadió `APOYO` al enum + `tienda_destino` `required_if` + persistencia. | Codex ✅ |
| B10 | FUNCIONAL | MEDIA | **`comision_ext_n` no se usa.** El front (NuevoReportePage L497) admite que solo usa `comision_dni_n` incluso para extranjeros; legacy usa `comision_ext_n` para extranjeros. Cae bajo la solución de B2 (motor server-side con `es_extranjero`). | Junto con B2 |

> **Nota de arquitectura:** el cambio de `reporte_categorias` (JSON) → tablas normalizadas (`ventas`/`venta_equipos`/`venta_lineas`) es intencional y correcto; NO es brecha. Las brechas son reglas de negocio que se perdieron en la traducción, no el esquema.

**Pendiente de auditar en Cluster 2:** `MiHistorialPage`, `ReporteDetallePage`, `EditarReportePage` (UI), `ReporteBorradorController`, `BipayController` (consola integrada en cuadre).

---

## Cluster 3 — Inventario / Traslados  ✅ AUDITADO

**Veredicto:** Paridad ALTA (~95%). `TrasladoController` replica 1:1 la máquina de estados legacy: estado inicial `PENDIENTE` (admin/`es_gerencia`) vs `PENDIENTE_APROBACION` (agente regular); modo masivo con `codigo_lote`; traslado total→`estado=TRASLADO` / parcial→split de registro; `gestionar` (aprobar `PEND_APROB→PENDIENTE` sin tocar stock; rechazar/cancelar→revertir `DISPONIBLE`, lote-aware); `confirmar` (`PENDIENTE→CONFIRMADO`, elimina origen, crea destino `DISPONIBLE`, **merge de accesorios** por nombre); todo bajo `lockForUpdate` + transacciones + auth DNI/PIN. `BitacoraStockController` y `ChipsController` ya decrementan con `historial_inventario`.

### Brechas

| ID | Tipo | Sev | Brecha | Dónde corregir |
|----|------|-----|--------|----------------|
| C1 | FUNCIONAL | BAJA | Verificar que `procesar_correccion_stock` (SUMA/RESTA) en `BitacoraStockController` registre `imei_serial` + `precio_en_ese_momento` y `producto_id=NULL` para chips (anti-colisión de IDs entre tablas), igual que legacy. | Verificar `BitacoraStockController` |
| C2 | FUNCIONAL | BAJA | Kardex legacy reconstruye datos de venta con cadena `COALESCE` (inventario_tiendas → historial_inventario RESTA → reporte_categorias por imei_serial, incl. `es_cuota`). Verificar que `InventarioController@kardex` replique esa reconstrucción. | Verificar `InventarioController@kardex` |
| C3 | FUNCIONAL | BAJA | `api_inventario` delete es físico + audita `ELIMINACION` con DNI autorizador. Verificar que el delete del refactor audite en `historial_inventario`. | Verificar `InventarioController@destroy` |
| C4 | COSMÉTICO | BAJA | Páginas `inventario/matriz`, `chips-gestion`, `bitacora` marcadas pendientes de look glass premium en memoria. Revisar estética. | Claude (UX) |

> Nota: `confirmar()` llama `$origen->delete()` antes de leer `$origen->tipo/producto_nombre` para el merge; funciona porque Eloquent conserva los atributos en memoria tras delete — OK, pero frágil. Considerar capturar atributos antes del delete.

---

## Cluster 4 — Finanzas (Bipay / Financieras / BCP / Comisiones)  ✅ AUDITADO

**Veredicto:** Bipay y BCP con paridad ALTA; **Financieras roto por el split de modelo de datos.**
- **Bipay (`BipayController`)** ✅ fiel: cuentas madre/hijo (`tipo`), `saldo_bipay`/`saldo_anypay`/`saldo_actual`, `DECLARACION_DIA`/`CIERRE_DIA`, **cooldown 4 min** (tienda declarante) + **aleatorio 1-3 min** (tiendas hermanas), `umbral_alerta`+`alerta_enviada`, last-write-wins, `tiendasEstado`. Consola cajero integrada (estadoCajero/actualizarCajero/cierreCajero).
- **BCP (`ReporteBcpController`)** ✅ fiel: meta **200 ops/día**, alerta `having total_ops < 200`, agrega `queda_efectivo`/`queda_tarjeta`.

### Brechas

| ID | Tipo | Sev | Brecha | Dónde corregir |
|----|------|-----|--------|----------------|
| D1 | FUNCIONAL | **ALTA** | **`PanelFinancierasController` lee `reporte_categorias`** (tabla JSON legacy, `rc.tipo='equipos_accesorios'`, `detalle->vendedor_id/por_cobrar_financiera`) que el nuevo `store()` **NUNCA puebla** (usa `venta_equipos`). → Las ventas de equipos a cuotas del sistema nuevo **NO aparecen** en el panel de financieras; el flujo de desembolso opera sobre datos inexistentes. | Codex/Claude → reescribir queries a esquema normalizado |
| D2 | FUNCIONAL | MEDIA | **`confirmarDesembolso` no libera/calcula la comisión** del agente. Legacy: al confirmar, libera comisión (default `EQUIPO_ESTANDAR` S/5, o rangos en planilla) al `detalle`. El refactor solo cambia `comision_estado→APROBADA`. → La comisión de equipos a cuotas nunca se activa para el agente. | Codex (junto a B2) |
| D3 | FUNCIONAL | BAJA | **Bipay admin** sin endpoints de **transferencia entre cuentas** ni **ajuste manual** (legacy los tenía; recarga sí existe). | Codex |
| D4 | COSMÉTICO | BAJA | `comisiones`, `bcp` marcadas pendientes de look glass premium. | Claude (UX) |

> ### ⚠️ HALLAZGO SISTÉMICO — Split de modelo de datos (`reporte_categorias` vs normalizado)
> El sistema nuevo tiene DOS modelos de datos coexistiendo incoherentemente para las ventas:
> - **`ReporteController@store`** escribe **normalizado** (`ventas`/`venta_equipos`/`venta_lineas`).
> - **`PanelFinancierasController` (D1)**, **`ReporteController@fijarCosto` (B6)** y la **reconstrucción de venta del kardex (C2)** LEEN de **`reporte_categorias`** (JSON legacy).
> → Estos módulos están **desconectados de las ventas reales** del sistema nuevo. **Decisión de arquitectura requerida:** migrar esos lectores al esquema normalizado (recomendado) **o** hacer que `store()` también escriba `reporte_categorias` (doble escritura, no recomendado). Es la brecha más importante después de B1/B2. Afecta: Financieras, campana de costos, kardex.

---

## Cluster 5 — Agentes / Planilla / RRHH  ✅ AUDITADO

**Veredicto:** Planilla con paridad ALTA. `PlanillaController` replica el modelo CD08: `sueldo_dias_lab=(base/30)×días`; `total_remuneracion=G+H+I+J+K`; comisión equipo `SUM(comision_generada EQUIPO)` con **fallback S/5**; comisión planes (postpago/prepago, excl. `es_remate`+`PAQUETE`); **tardanzas auto desde asistencias** (S/1/min − comodín) ✅ (sync integrada, confirmado); faltas `FALTA_INJUSTIFICADA ×2×(base/30)`; adelantos; `override_comisiones` manual; export `.xlsx` PhpSpreadsheet.

### Brechas

| ID | Tipo | Sev | Brecha | Dónde corregir |
|----|------|-----|--------|----------------|
| E1 | FUNCIONAL | MEDIA | **Comisión online divergente.** Legacy = `ganancia_recargas%` × recargas **+** `COMISION_BCP` × ops BCP (de `config_comisiones` y `reportes_bcp`). Refactor = `SUM(OTROS_FLUJO.comision_generada)` que hoy es ~0 (otros_flujo no comisiona). → Los agentes pierden su comisión de recargas/BCP. | Codex → `calcularComisionesOnline` |
| E2 | FUNCIONAL | MEDIA | **Falta el export "ficha técnica" multi-hoja** (`exportar_excel_agentes_pro`): hoja "Personal" + 1 hoja por agente con datos RRHH completos (contacto, previsional, antecedentes, carga familiar, formación, experiencia, emergencia) + hipervínculos internos. El refactor solo tiene el export de la PLANILLA, no la ficha de personal. | Codex → nuevo export en `AgenteController` |
| E3 | FUNCIONAL | BAJA | Verificar que `PostulanteController@aprobar` cree el agente con TODOS los campos de `postulantes_temp` + **PIN default = últimos 4 del DNI** si no se especifica (paridad `aprobar_postulante`). | Verificar `PostulanteController` |
| E4 | COSMÉTICO | BAJA | `planilla` ya migrada a premium (commit reciente); verificar `VerAgente`/ficha. | Claude (UX) |

---

## Cluster 7 — Dashboard / Sidebar Control Center / Login  ✅ AUDITADO (núcleo)

**Veredicto:** **Control Center con paridad ALTA — los 8 badges vivos del legacy están todos** (`ControlCenterController`): `anomalias_caja` (descuadre >S/5, ≥3 días, 7d — umbral legacy exacto), `precios_pendientes`, `traslados_pendientes` (equipos+chips, lote-aware), `postulantes_pendientes`, `financieras_pendientes`, `alertas_bcp` (<200 ops, `alerta_vista=0`), `alertas_bipay` (≤umbral), `notificaciones_sistema` (campana + marcar leído/borrar). Polling desde `AppLayout` (Fase 1).

| ID | Tipo | Sev | Brecha | Dónde |
|----|------|-----|--------|-------|
| F1 | FUNCIONAL | MEDIA | `financieras_pendientes` cuenta sobre `reporte_categorias` (orphan, ver D1/sistémico) → badge **undercount** con datos nuevos. | Junto a D1 |
| F2 | — | — | Verificar AppLayout: dropdowns interactivos de aprobación rápida de traslados + anomalías (parpadeo/pulso) y login (paridad visual). | Claude (UX) |

## Cluster 6 — Admin / Config / Stock  ✅ AUDITADO (spot-check)

**Veredicto:** Páginas CRUD admin presentes y de bajo riesgo de divergencia. `RevisarFotos` usa `AsistenciaController@fotosPendientes/photoAction` (zero-retention validado en Cluster 1). `RevisarStock`/`fijarCosto`: **afectado por el split de datos** (B6 — lee `reporte_categorias`). Resto (usuarios, tiendas, configuración, diagnóstico, tickets) son CRUD estándar; spot-verify pendiente.

| ID | Tipo | Sev | Brecha | Dónde |
|----|------|-----|--------|-------|
| G1 | FUNCIONAL | MEDIA | `RevisarStock`/campana de costos opera sobre `reporte_categorias` (B6) → no ve los equipos del esquema normalizado. | Junto a D1 |
| G2 | — | BAJA | Spot-verify: `usuarios` (hash, roles), `tiendas` (radio/coords/cuenta_bipay), `configuracion_empresa`, `diagnostico_tiendas`, `tickets_emitidos` (impresión 80mm). Bajo riesgo. | Verificar |

---

# 🎯 RESUMEN EJECUTIVO DE REMEDIACIÓN (auditoría 7/7 clusters)

**Conclusión:** El refactor tiene paridad **funcional ALTA (~88%)** y faltan pocas reglas de negocio, no módulos enteros. El backend de Asistencias, Traslados, Bipay, BCP, Planilla y el Control Center son **fieles 1:1**. El riesgo se concentra en el **cuadre** y en un **split de modelo de datos**.

### Prioridad 0 — CRÍTICO (integridad financiera/datos)
1. **B1 stock** ✅ hecho · **B5 anti-dup** ✅ hecho · **B9 APOYO** ✅ hecho (merged `83b616e`).
2. **SISTÉMICO (D1/B6/F1/G1/C2):** unificar modelo de datos. `PanelFinancieras`, `fijarCosto`, `RevisarStock`, kardex y el badge financieras leen `reporte_categorias` (orphan); `store()` escribe normalizado. → Migrar lectores a `ventas`/`venta_equipos` (recomendado).
3. **B2 comisión server-side** (parcial) — falta `plan_id` en payload + `ComisionService`.

### Prioridad 1 — ALTO impacto funcional
4. **B3** fórmula esperado (no sumar `caja_inicial`; alinear al front). 5. **B4** flujo de edición 1:1 (revert stock + reproceso + edicion_critica). 6. **D2** liberar comisión al confirmar desembolso. 7. **E1** comisión online (recargas% + BCP). 8. **A1** anti-spoofing 200km/h · **A2** recuperación deuda_dias.

### Prioridad 2 — Paridad de detalle
9. **E2** export ficha técnica multi-hoja. 10. **A4** escáner QR cámara · **A3/A5** token/hora_intento. 11. **D3** Bipay transferencia/ajuste. 12. **B10** comision_ext_n.

### Prioridad 3 — UX glass premium (pendientes de estética)
`asistencias/*`, `terminal-asistencia`, `comisiones`, `comprobantes`, `bcp`, `traslados-chips`, `inventario/matriz`, `chips-gestion`, `diagnostico`, `revisar-stock`, `revisar-fotos`, `qr-display`, `auth/login`.
