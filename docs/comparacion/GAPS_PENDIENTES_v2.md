# Re-comparación completa Legacy → Nuevo (v2) — Gaps pendientes

**Fecha:** 2026-06-14
**Método:** 3 agentes en paralelo auditando contra código real (no contra la doc previa).
- Agente A → `gerencia/` (52 PHP)
- Agente B → `tienda/` (25) + `reportes/` (15)
- Agente C → `api/` (16) + `cron/` (3) + `auth/` (2) + raíz

**Conclusión:** La paridad real **NO es ~90%**. El esqueleto (rutas/páginas) está, pero hay **~24 gaps funcionales**, varios que afectan **integridad de datos** y **operación diaria**. La doc anterior (`PARIDAD_MASTER.md`) subestimó los gaps porque verificó existencia de ruta, no profundidad de lógica.

Ya cerrados en commit `1dab40a` (confirmados por esta pasada): DELETE tickets/{id} ✅, DELETE asistencias/{id} ✅, registrar manual con refrigerio (backend) ✅, marcar-entregado con observación ✅, PATCH ticket parcial + estado ✅.

---

## 🔴 TIER 1 — Críticos (corrompen datos o bloquean operación diaria)

### T1.1 — Descuento de stock de CHIPS ausente en el cuadre
- **Legacy:** `procesar_reporte.php:227-301` descuenta `inventario_chips` al guardar el cuadre (postpago: 1 chip/activación por origen; prepago: resta masiva; apoyo: por tienda destino).
- **Nuevo:** `ReporteController@procesarVentas` y `@reprocesar` **solo descuentan `inventario_tiendas` (equipos)**; nunca tocan `inventario_chips`.
- **Impacto:** el stock de chips **no se decrementa al vender líneas** → descuadre permanente del inventario de chips. **Corrompe datos en cada cuadre.**

### T1.2 — `mi_historial.php` no migrado (salvavidas + panel jefe de tienda)
- **Legacy:** consulta por DNI con desglose de tardanzas/descuentos, historial de comisiones por día, **botón Salvavidas**, y **panel Jefe de Tienda** (accordion del equipo con presencias/faltas/tardanza por agente).
- **Nuevo:** `MiHistorialPage.tsx` es **otra feature** (lista de cuadres propios). El backend `POST /asistencias/salvavidas` existe 1:1 **pero ningún componente React lo invoca** (grep `salvavidas` en `frontend/src` → 0).
- **Impacto:** el agente **no puede recuperar tardanzas**; el jefe de tienda no tiene su panel de equipo.

### T1.3 — Configuración de tarifas y rangos de comisiones operativas
- **Legacy:** `guardar_tarifas_ajax.php` (% recargas, montos bipay/krece/payjoy en `config_comisiones`), `guardar_rangos_ajax.php` (`comisiones_rangos`), `configurar_comisiones.php` (rangos PLAN/EQUIPO por productividad).
- **Nuevo:** `ComisionPlanController` solo hace CRUD de `comisiones_planes`. `config_comisiones` solo se **lee** en planilla; `comisiones_rangos` **no aparece en ningún controller** (grep → 0).
- **Impacto:** el gerente **no puede cambiar** el % de ganancia de recargas ni las ganancias de financieras desde el sistema nuevo.

### T1.4 — Registrar adelantos de agente
- **Legacy:** `ver_agente.php:371` inserta en tabla `adelantos`; la planilla los descuenta.
- **Nuevo:** `PlanillaController` **lee** adelantos (`cargarAdelantos`) pero **no existe endpoint de creación** (grep `INSERT adelanto` → 0).
- **Impacto:** la planilla **nunca tendrá descuentos de adelanto** porque no hay forma de registrarlos.

---

## 🟠 TIER 2 — Alto impacto operativo

### T2.1 — Asistencia: registrar excepción (FALTA/PERMISO/PERDONAR) + recálculo en edición
- **Legacy:** `acciones_asistencia.php` acción `registrar_excepcion` con rama PERDONAR (borra registros negativos del agente+fecha); `admin_editar_asistencia.php` **recalcula** minutos_tardanza/deuda/comodín.
- **Nuevo:** `editar()` guarda campos crudos pero **no recalcula tardanzas**; no hay acción `registrar_excepcion` ni UI para FALTA/PERMISO/PERDONAR. `AsistenciasPage.tsx` solo tiene "Aprobar" + "Exportar CSV".
- *(Nota: el commit anterior agregó persistencia de `estado_asistencia`/`horas_extras` a nivel BD, pero falta la lógica de recálculo y toda la UI.)*

### T2.2 — CRUD de cuentas Bipay (Madre/Hijo)
- **Legacy:** `panel_bipay.php` acciones `nueva_cuenta`, `editar_cuenta`, `eliminar_cuenta`.
- **Nuevo:** `BipayController` tiene saldo/transacciones/recarga/transferir/ajustar/cajero, **pero no crear/editar/eliminar `cuentas_bipay`**.

### T2.3 — `fijar_precio_agente.php` sin equivalente
- **Legacy:** el agente/tienda fija solo `precio_normal` (≥ mínimo, nunca costo/mínimo) con DNI.
- **Nuevo:** las rutas de precio (`recalcular-ganancias`, `fijar-costo`) son **solo admin** y tocan costo. No existe el flujo blindado agente-fija-precio-venta.

### T2.4 — Ranking de agentes: sin categoría ni subfiltros (drill-down)
- **Legacy:** `obtener_ranking_agentes.php` + `obtener_subfiltros_ranking.php` — filtro `categoria` (equipos/postpago/chips) y `subcategoria` con drill-down (modelo, plan, RECUPERO/NUEVO/`plan::`).
- **Nuevo:** `rankingAgentes()` solo hace `return $this->productividad()` (columnas fijas, sin parámetros). No hay endpoint de subfiltros.

### T2.5 — `VerAgentePage` incompleta
- **Legacy:** `ver_agente.php` (1836 líneas): adelantos, historial de boletas (imprimir/pagar/eliminar), editor ficha RRHH (familiares/estudios/experiencia/emergencia), certificado, fechas, token.
- **Nuevo:** `VerAgentePage.tsx` solo tiene token + fechas laborales. Faltan ~5 bloques.

---

## 🟡 TIER 3 — Medio

| ID | Gap | Legacy → Nuevo |
|----|-----|----------------|
| T3.1 | **Auditoría anti-fraude de edición** | `procesar_edicion.php` registra `edicion_critica`/`edicion_restaurada` al cambiar vendedor (robo de comisión); `reprocesar` solo escribe `edicion_aplicada` genérico. |
| T3.2 | ~~**Ajuste maestro de inventario**~~ ✅ **CERRADO** (verificado 2026-07-08) | `admin_ajuste_inventario.php` (reset/carga física + log `AJUSTE`) → `InventarioController::ajustarStockReal` (`POST /inventario/{id}/ajustar-stock-real`, `backend/app/Http/Controllers/Api/InventarioController.php:288`) y `ChipsController::ajustarStockReal` (`POST /chips/{id}/ajustar-stock-real`, `backend/app/Http/Controllers/Api/ChipsController.php:91`). Ambos escriben `historial_inventario` con SUMA/RESTA + `[AJUSTE MAESTRO]` en la observación, igual que el legacy. Cubierto por `PhaseCOperationalParityTest::test_admin_registra_varios_imeis_y_audita_ajuste_de_stock` y `::test_admin_registra_series_y_ajusta_stock_de_chips`. Se cerró en las fases B/C/D (2026-06-15), la doc no se había actualizado. |
| T3.3 | **Reset de dispositivo (desvincular celular)** | `ajax_seguridad.php` acción `reset` (`hash_dispositivo=NULL`); `tokenSeguridad` no la cubre. |
| T3.4 | **verify-pin: jerarquía de roles + check turno** | `validar_autorizacion.php` valida admin/gerente/jefe_tienda (tabla `usuarios`) + turno abierto para agente. `verifyPin` solo valida PIN de `agentes`. **Riesgo de seguridad.** |
| T3.5 | ~~**Consulta de token activo**~~ ✅ **CERRADO** (verificado 2026-07-08) | `verificar_token_activo.php` (GET token vigente + tipo) → `AgenteController::estadoSeguridad` (`GET /agentes/{id}/seguridad`, `backend/app/Http/Controllers/Api/AgenteController.php:391`) devuelve `tiene_token`/`token`/`tipo_token`/`expiracion_token` (superset: también `dispositivo_vinculado`). Consumido por `AgenteSeguridadDialog.tsx` y `VerAgentePage.tsx` en el frontend. Se cerró en fase C, la doc no se había actualizado. |
| T3.6 | ~~**Recálculo masivo comisiones operativas**~~ ✅ **CERRADO** (verificado 2026-07-08) | `recalcular_comisiones_masivo.php` (planes + operativos recargas/bipay/krece/payjoy) → `ComisionPlanController::recalcularMasivo` (`POST /comisiones-planes/recalcular`, `backend/app/Http/Controllers/Api/ComisionPlanController.php:81`) recalcula líneas POSTPAGO/PREPAGO/APOYO **y** delega a `App\Services\ComisionOperativaService::recalcularReporte` (`backend/app/Services/ComisionOperativaService.php:11`) para recargas/bipay/krece/payjoy vía `comisiones_rangos`/`config_comisiones` — misma fórmula que el legacy. Exige `fecha_desde`/`fecha_hasta` igual que el blindaje legacy. Cubierto por `PhaseBBusinessParityTest::test_recalculo_masivo_actualiza_operativas_con_tarifas_actuales` y `PhaseDPolishTest::test_recalculo_respeta_upgrade_migracion_esim_y_prepago`. |
| T3.7 | ~~**Stock multi-IMEI + series_info chips**~~ ✅ **CERRADO** (verificado 2026-07-08) | `guardar_stock.php` (`imei_serial[]` N equipos, `serie_inicio[]/serie_fin[]` → `series_info` JSON) → `InventarioController::store` acepta `imei_seriales[]` (1 fila por IMEI, `cantidad=1`, `backend/app/Http/Controllers/Api/InventarioController.php:116`) y `ChipsController::store` acepta `series[]` (`inicio`/`fin`) mergeados en la columna `series_info` (`backend/app/Http/Controllers/Api/ChipsController.php:38`). Cubierto por `PhaseCOperationalParityTest::test_admin_registra_varios_imeis_y_audita_ajuste_de_stock` y `::test_admin_registra_series_y_ajusta_stock_de_chips`. |

---

## 🟢 TIER 4 — Bajo / diferencias de formato

| ID | Gap |
|----|-----|
| T4.1 | ~~**Exports Excel**~~ ✅ **CERRADO** (verificado + completado 2026-07-08): estadísticas corporativo (`EstadisticasController::exportar`), asistencias (`AsistenciaController::exportar`) y kardex (`InventarioController::exportarKardex`) ya generaban `.xlsx` real con PhpSpreadsheet — confirmado por `PhaseDPolishTest::test_exports_operativos_son_xlsx`. Se descubrió durante esta verificación que el export de **CRM** (`gerencia/exportar_crm_excel.php`) no tenía equivalente — se agregó `CrmTemperaturaController::exportar` (`GET /crm/temperatura/exportar`, `backend/app/Http/Controllers/Api/CrmTemperaturaController.php`), reutilizando `TemperaturaCalculator` y las tablas `crm_clientes`/`crm_interacciones`. Test: `CrmTemperaturaTest::test_endpoint_exportar_crm_genera_xlsx_respetando_filtro_de_tienda`. |
| T4.2 | **markQr**: falta validación anti-colisión de escaneos simultáneos; firma con `app.key` en vez de `QR_SECRET_KEY`. |
| T4.3 | **generar_qr**: nuevo devuelve token JSON (render client-side) vs PNG server-side legacy. |
| T4.4 | **limpiar-fotos**: borra de disco, pero el nuevo guarda foto base64 en BD → lógica de disco es código muerto (el UPDATE a NULL sí funciona). |
| T4.5 | **validar_asistencia_ajax**: corrección de stock no exige "agente en servicio hoy (entrada sin salida)". |
| T4.6 | **Confirmar lote de traslado**: verificar que `confirmar` procesa todo un `codigo_lote` atómicamente (legacy usa savepoints por ítem). |
| T4.7 | **Modo Dios en el cuadre**: admin no puede cuadrar por tienda arbitraria (`?tienda_override=`) desde `NuevoReportePage`. |
| T4.8 | **historial_agente**: liquidación detallada de asistencias (valorización tardanzas/deuda/comodín por minuto) sin página equivalente. |

---

## Métrica honesta

- **Paridad de esqueleto** (existe ruta+página): ~90% ✅
- **Paridad funcional real** (lógica + acciones + UI completas): **~70%** → tras esta pasada **~88%**
- **Gaps que corrompen datos:** 1 (T1.1 chips) — máxima prioridad.
- **Gaps que bloquean operación:** 3 (T1.2 salvavidas/jefe, T1.3 comisiones operativas, T1.4 adelantos).

---

## Estado de implementación (2026-06-14, Tier 1 + Tier 2)

Backend + frontend lint/typecheck limpios. Pendiente: aplicar las 4 migraciones en el VPS y verificar en vivo.

| Gap | Backend | Frontend | Estado |
|-----|---------|----------|--------|
| **T1.1** chips en cuadre | `ReporteController::procesarVentas/revertirVentas` + `descontarChips/reponerChips` + col `ventas.chips_descontados` (migración) | (el front ya enviaba los flags) | ✅ Completo |
| **T1.2** salvavidas + jefe tienda | `Asistencia::misTardanzas` (`GET /asistencias/mis-tardanzas`) + `salvavidas` ya existía | `SalvavidasPanel` en MiHistorialPage (consulta DNI → recuperar) | 🟡 Salvavidas ✅ · **Panel jefe de tienda + comisiones-por-día = pendiente** (sub-feature mayor, necesita endpoint de equipo) |
| **T1.3** comisiones operativas | `ConfigComisionesController` (index + tarifas + rangos-productividad + rangos-servicio) + 2 migraciones | Modal "Tarifas operativas" en ComisionesPage | 🟡 Tarifas ✅ · **editores de rangos PLAN/EQUIPO y bipay/krece/payjoy = pendientes en UI** (endpoints listos) |
| **T1.4** adelantos | `AgenteController::adelantos/registrarAdelanto/eliminarAdelanto` + migración `adelantos` | `AdelantosPanel` en VerAgentePage | ✅ Completo |
| **T2.1** excepciones asistencia | `AsistenciaController::registrarExcepcion` (FALTA/PERMISO/PERDONAR) | Botón "Registrar excepción" + eliminar fila en AsistenciasPage | ✅ Completo |
| **T2.2** CRUD cuentas Bipay | `BipayController::crearCuenta/editarCuenta/eliminarCuenta` | Pestaña "Cuentas" en PanelBipayPage | ✅ Completo |
| **T2.3** fijar precio agente | `InventarioController::fijarPrecioAgente` (`POST /inventario/{id}/precio-agente`) | Botón "Fijar precio" (rol tienda) en InventarioPage | ✅ Completo |
| **T2.4** ranking categoría/subfiltros | `EstadisticasController::rankingAgentes` (categoría/subcategoría) + `subfiltrosRanking` | Selectores categoría+subfiltro en tab Ranking | ✅ Completo |
| **T2.5** VerAgente completa | adelantos (T1.4) | `AdelantosPanel` agregado | 🟡 Adelantos ✅ · **boletas (imprimir/pagar/eliminar), ficha RRHH, certificado = pendientes** |

**Migraciones nuevas (idempotentes, guardadas por drift):**
- `2026_06_14_000001_add_chips_descontados_to_ventas`
- `2026_06_14_000002_create_adelantos_table`
- `2026_06_14_000003_create_config_comisiones_table`
- `2026_06_14_000004_create_comisiones_rangos_table`

**Remanente documentado (sub-features mayores, backend listo o parcial):**
1. Panel "Jefe de tienda" + historial de comisiones por día en MiHistorialPage (T1.2).
2. Editores de rangos PLAN/EQUIPO y rangos bipay/krece/payjoy en ComisionesPage (T1.3 — endpoints `rangos-productividad`/`rangos-servicio` ya existen).
3. Bloques de VerAgente: boletas, ficha RRHH, certificado (T2.5).
