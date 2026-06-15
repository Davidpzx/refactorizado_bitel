# Análisis Profundo de Paridad Legacy → Nuevo (v3) — 2026-06-15

**Objetivo:** identificar TODO lo que le falta a la reescritura (`refactorizado_bitel`, Laravel + React) para
funcionar IGUAL que el legacy (`refactor_principal`, PHP/MySQL/PDO).

**Método (orquesta multi-agente):**
- **Codex** (gpt-5.5, reasoning high, full access) — 5 olas de auditoría leyendo el código real de ambos repos.
- **Claude** — inventario, orquestación, y **verificación contra código** de cada hallazgo crítico antes de aceptarlo.
- **Gemini 3.1** — validación de lectura de legacy (limitado: su MCP solo lee dentro de `refactor_principal`).

**Diferencia con docs previos (`PARIDAD_MASTER.md`, `GAPS_PENDIENTES_v2.md`):** aquellos midieron paridad de
*esqueleto* (¿existe la ruta/página?) y reportaron ~88-90%. Esta pasada auditó **lógica, seguridad e integridad de
datos** contra el código ACTUAL (post-commit 10a00f5) y encontró una capa de gaps **CRÍTICOS no detectada antes**,
sobre todo de **autorización** y **atribución de identidad**.

**Leyenda de confianza:** ✔ = verificado por Claude contra el código · ◑ = reportado por Codex (consistente en
varias corridas, alta probabilidad) · ? = a verificar.

---

## Resumen ejecutivo

La paridad de pantallas/endpoints es alta (~90%), pero **NO hay paridad operativa ni de seguridad**. Hay 3 clases de
problema, en orden de gravedad:

1. **Seguridad/autorización (TIER 0):** la API no tiene control de roles ni de propiedad. Un usuario `tienda` puede,
   llamando la API directamente, administrar usuarios/tiendas/agentes/comisiones/planilla y tocar datos de otras
   tiendas. El legacy sí lo impedía.
2. **Integridad de datos (TIER 0/1):** la identidad del vendedor se atribuye mal (`usuarios.id` usado como
   `agente_id`), y eliminar un reporte no devuelve el stock. Esto corrompe inventario, comisiones y planilla.
3. **Lógica de negocio incompleta (TIER 1/2):** comisiones operativas sin efecto, recálculo de asistencia ausente,
   desglose de salidas perdido, y varias pantallas a medias (jefe de tienda, ficha RRHH, boletas, editores de rangos).

> **Nota positiva:** el gap crítico previo **T1.1 (descuento de stock de chips) YA ESTÁ RESUELTO** en el código actual
> (`ReporteController::descontarChips/reponerChips`). ✔

---

## 🔴 TIER 0 — Seguridad e integridad (BLOQUEANTES, nuevos)

### T0.1 ✔ — La API no aplica autorización por rol
- **Legacy:** cada página admin valida sesión + rol al entrar (p.ej. `gerencia/usuarios.php:6` corta si no es admin).
- **Nuevo:** todas las rutas bajo `routes/api.php:68` solo usan `auth:sanctum`; no hay middleware de rol. Solo
  `AdminRoute.tsx` (frontend) protege la navegación, y es evadible llamando la API directo. Dentro de los controllers,
  casi ninguno checa `$user->rol` (excepción aislada: `ReporteController::fijarCosto:717`).
- **Impacto:** escalamiento de privilegios. Un usuario `tienda` puede crear admins, editar planillas, comisiones,
  tiendas, configuración e inventario por API.
- **Implementar:** middleware `role:admin` (y `role:admin|tienda` donde toque) aplicado a los grupos de rutas; o
  gates/policies por controller. Derivar permisos del token, no del front.

### T0.2 ✔ — `usuarios.id` se usa como `agente_id` (atribución de ventas equivocada)
- **Legacy:** el vendedor de cada venta es un `agentes.id` real, seleccionable por venta.
- **Nuevo:** `NuevoReportePage.tsx:368,381` setea `agente_id = usuario.id`. El backend (`ReporteController::store:46`,
  `procesarVentas:372`) confía en ese valor y lo guarda como `ventas.vendedor_id`. Pero `usuarios` y `agentes` son
  **tablas distintas con PKs independientes** — probado por `AuthController`: el login no devuelve `agente_id` y
  `verifyPin:78-86` resuelve el agente **por DNI** (id distinto).
- **Impacto:** las ventas se atribuyen al `agentes.id` equivocado (o inexistente) → comisiones y planilla mal
  calculadas. **Corrompe datos en cada cuadre.**
- **Implementar:** que el front/back deriven el `agente_id` real (vía DNI del usuario logueado o un `agente_id` en la
  sesión) y permitir seleccionar vendedor por venta (ver T1.4).

### T0.3 ◑ — Mismo patrón en BCP y Postulantes
- **Nuevo:** `ReporteBcpController` guarda `reportes_bcp.agente_id = usuarios.id`, pero `PlanillaController:374` lo
  compara contra `agentes.id` → comisión BCP acreditada a otra persona o excluida. Igual patrón al crear agente desde
  `PostulanteController` (~:221).
- **Implementar:** unificar la resolución de identidad agente en todo el sistema (helper único usuario→agente por DNI).

### T0.4 ✔ — `destroy()` de reporte no revierte stock
- **Legacy:** `gerencia/eliminar_reporte.php` revierte stock de equipos/chips al anular.
- **Nuevo:** `ReporteController::destroy:240-247` solo hace `$reporte->delete()` (previa guardia de "aprobado"). No
  llama `revertirVentas()`. El stock de equipos queda descontado y los chips no se reponen.
- **Impacto:** descuadre permanente de inventario cada vez que se elimina un reporte con ventas.
- **Implementar:** llamar `revertirVentas($reporte)` dentro de una transacción antes de borrar (reusar el helper que
  ya existe para `reprocesar`).

### T0.5 ✔ — IDOR sobre reportes y asistencias
- **Nuevo:** `aprobarEdicion:316`, `solicitarEdicion`, `actualizarDestino`, `reprocesar`, `destroy`, `show` no validan
  propiedad ni rol → cualquier autenticado opera reportes ajenos. `asistencias/salvavidas` y `mis-tardanzas`
  (`AsistenciaController:~1269`) aceptan cualquier DNI sin verificar que pertenezca al usuario.
- **Implementar:** policies de propiedad/rol; `aprobarEdicion`/`destino`/`reprocesar` solo admin; salvavidas/tardanzas
  ligados al agente del usuario autenticado.

### T0.6 ◑ — Se eliminó el gate de asistencia
- **Legacy:** el personal de tienda debe tener marcación de entrada vigente para operar.
- **Nuevo:** `ProtectedRoute.tsx` solo comprueba que exista token; no hay equivalente del bloqueo por turno.
- **Implementar:** middleware/guard que exija turno abierto para rol `tienda` en endpoints operativos.

---

## 🟠 TIER 1 — Lógica de negocio crítica

### T1.1 ◑ — Comisiones operativas sin efecto real
- **Legacy:** `guardar_tarifas_ajax.php` (% recargas, montos bipay/krece/payjoy) y `guardar_rangos_ajax.php`
  (`comisiones_rangos`), `configurar_comisiones.php` (rangos PLAN/EQUIPO por productividad).
- **Nuevo:** existen endpoints (`ConfigComisionesController`) pero **no hay editor UI** para los rangos PLAN/EQUIPO ni
  bipay/krece/payjoy (`ComisionesPage.tsx:254` solo edita tarifas simples). `ComisionPlanController::recalcularMasivo`
  solo recalcula postpago/prepago, no operativas.
- **Impacto:** el gerente no puede ajustar ganancias de recargas/financieras desde el sistema nuevo.

### T1.2 ✔ — Edición de reportes no usa `reprocesar` ni audita cambio de vendedor
- **Legacy:** `procesar_edicion.php:304` registra `edicion_critica`/`edicion_restaurada` al cambiar el vendedor
  (anti-robo de comisión) y reprocesa stock/ventas.
- **Nuevo:** existe `ReporteController::reprocesar` (revierte + reescribe, correcto), pero `EditarReportePage.tsx:44`
  **no lo llama** — solo hace `PATCH` de cabecera. No se pueden corregir productos/cantidades/vendedor, y no hay
  auditoría anti-fraude por cambio de vendedor.
- **Implementar:** conectar la UI de edición al endpoint `reprocesar`; añadir registro `edicion_critica` cuando cambie
  `vendedor_id`.

### T1.3 ◑ — Recálculo de asistencia ausente en edición admin
- **Legacy:** `admin_editar_asistencia.php:36` recalcula `minutos_tardanza`/deuda/comodín según horario oficial;
  `acciones_asistencia.php` maneja excepción PERDONAR (borra negativos), aprobar horas extra, asignar refrigerio.
- **Nuevo:** `AsistenciaController::editar (~1361)` guarda valores crudos sin recalcular. La UI (`AsistenciasPage`) no
  expone edición de horas, aprobación de extras ni asignación de refrigerio. Posible mismatch de columna:
  `horas_extras` (nuevo) vs `horas_extras_aprobadas` (legacy) — **? a verificar**.
- **Implementar:** portar la lógica de recálculo; UI de edición completa; alinear nombre de columna.

### T1.4 ◑ — Un solo vendedor por reporte (falta vendedor por venta)
- **Legacy:** `procesar_reporte.php:~180` permite vendedor distinto por equipo/línea/apoyo.
- **Nuevo:** `procesarVentas:372` aplica un único `$agenteId` a todas las ventas.
- **Implementar:** aceptar `vendedor_id` por ítem en el payload y UI.

### T1.5 ✔ — Desglose de salidas perdido
- **Legacy:** captura salidas con tipo/monto/motivo (tabla `reporte_salidas`).
- **Nuevo:** `NuevoReportePage.tsx:122` captura el desglose pero solo envía `total_salidas`; `ReporteController` no
  persiste el detalle (no hay `reporte_salidas`).
- **Implementar:** persistir el desglose de salidas.

### T1.6 ◑ — Financieras: posible mismatch de esquema
- **Nuevo:** `PanelFinancierasController:139` consulta por `clave/valor` mientras la migración crea `tipo/monto` →
  error SQL o monto fijo S/5. **? verificar contra la migración real.**

---

## 🟡 TIER 2 — Funcionalidad operativa incompleta

| ID | Gap | Detalle |
|----|-----|---------|
| T2.1 ◑ | **Mi Historial** | Falta panel "Jefe de tienda" (equipo: presencias/faltas/tardanza) e historial diario de comisiones. Solo muestra reportes + salvavidas. |
| T2.2 ◑ | **Ver Agente** | Faltan: ficha RRHH (familiares/estudios/experiencia/emergencia), historial de boletas (imprimir/pagar/eliminar), certificado/constancia, reset de dispositivo, consulta de token activo. Solo tiene token + fechas + adelantos. |
| T2.3 ◑ | **Inventario alta** | `store` crea 1 IMEI por llamada; sin alta multi-IMEI ni `series_info` de chips; sin ajuste maestro (reset a 0 / carga física con log `AJUSTE`); `destroy` borra sin movimiento en bitácora. |
| T2.4 ◑ | **Traslados por lote** | Confirmación individual; falta confirmación atómica de `codigo_lote` (legacy usa savepoints por ítem). |
| T2.5 ◑ | **verify-pin** | Solo valida DNI+PIN de `agentes`; falta jerarquía admin/gerente/jefe (tabla `usuarios`) y turno abierto. Riesgo de seguridad. |
| T2.6 ◑ | **Corrección de stock** | `BitacoraStockController::corregir:87` acepta cualquier DNI activo, sin PIN/rol/turno/pertenencia (legacy `validar_autorizacion.php`). |
| T2.7 ◑ | **fijar_precio_agente** | Verificar que el flujo blindado (agente fija solo `precio_normal` ≥ mínimo, nunca costo) exista; las rutas de precio admin tocan costo. |

---

## 🟢 TIER 3 — Menores / formato / seguridad leve

| ID | Gap |
|----|-----|
| T3.1 ✔ | **Impresión de ticket rota:** `TicketImpresionPage.tsx:30` llama `/tickets/{id}` sin el prefijo `/v1/` (baseURL=`/api`) → 404. Toda la app usa `/v1/...`. |
| T3.2 ◑ | **QR a servicio externo:** `QrDisplayPage.tsx:18` envía el token a `api.qrserver.com` (PNG externo) en vez de generar el QR server-side como el legacy (`generar_qr_asistencia.php`). Fuga de token a 3ros. |
| T3.3 ◑ | **markQr:** firma con `app.key` en vez de `QR_SECRET_KEY`; falta anti-colisión de escaneos simultáneos. |
| T3.4 ◑ | **Chips soft-fail:** si faltan chips, la venta se confirma descontando solo lo disponible (`descontarChips:465`); al revertir, repone todo al lote canónico perdiendo procedencia (`reponerChips:521`). |
| T3.5 ◑ | **Exports:** estadísticas corporativo (ranking tiendas) y kardex/asistencias difieren de formato legacy (CSV vs Excel; faltan columnas `minutos_deuda`, `omitio_refrigerio`). |
| T3.6 ◑ | **recalcular_comisiones_masivo:** el nuevo siempre descuenta S/1 fijo y no reproduce reglas de migración/upgrade/eSIM/diferencia de planes. |

---

## Estado técnico (reportado por Codex, a confirmar en CI)
- `npm run build`: ✔ correcto.
- `php artisan test`: **75 pasan, 3 fallan** (Bipay timestamps + colisión de tabla en `PlanillaOnlineTest`).
- `npm run lint`: **27 errores, 4 advertencias** (incluye componentes React definidos en render).
- Migraciones nuevas (4) aún **pendientes de aplicar en VPS** (ver handoff).

---

## Plan de implementación sugerido (orden por riesgo)

**Fase A — Seguridad/Integridad (bloqueante):**
1. T0.1 middleware de roles en la API + policies de propiedad (T0.5).
2. T0.2/T0.3 resolver identidad agente correcta (helper usuario→agente por DNI) en reportes, BCP, postulantes.
3. T0.4 `destroy()` que revierta stock.
4. T0.6 gate de asistencia para rol tienda. T2.5/T2.6 endurecer verify-pin y corrección de stock.

**Fase B — Lógica de negocio:**
5. T1.2 edición vía `reprocesar` + auditoría de cambio de vendedor. T1.4 vendedor por venta.
6. T1.1 editores UI de comisiones operativas + recálculo operativo.
7. T1.3 recálculo de asistencia + UI de acciones. T1.5 desglose de salidas. T1.6 esquema financieras.

**Fase C — Completar pantallas:**
8. T2.1 panel jefe de tienda + comisiones diarias. T2.2 ficha agente completa. T2.3 inventario alta/ajuste.
   T2.4 traslado por lote.

**Fase D — Pulido:**
9. T3.x: ticket URL, QR server-side, exports, recálculo masivo, tests/lint en verde.

---

## Apéndice — Lo que SÍ está en paridad (confirmado)
Descuento/reposición de stock de **chips** en cuadre/reprocesar (T1.1 viejo, resuelto ✔), fórmula del cuadre,
guardia anti-duplicados, borradores nube/local, comisión server-side, `reprocesar` con reversión de stock,
`destroy` ticket + PATCH parcial, marcar-entregado con auditoría, registrar excepción de asistencia + eliminar,
CRUD cuentas Bipay, ranking con categoría/subfiltros (`subfiltrosRanking`), adelantos, fijar precio agente (endpoint),
y el grueso de CRUDs (tiendas/usuarios/clientes/agentes/inventario/traslados/chips/comprobantes/CRM).
