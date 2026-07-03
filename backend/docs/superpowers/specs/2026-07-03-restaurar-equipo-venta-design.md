# DESIGN - Gap P0 #3: `restaurar` no anula la venta asociada

Fecha: 2026-07-03
Estado: Final (decisiones tomadas por el usuario, sin opciones abiertas)

Ver también el DRAFT con la investigación legacy/refactor completa: `2026-07-03-restaurar-equipo-venta-DRAFT.md`.

## Decisión adoptada

**Opción B (anulación lógica)**, con bloqueo por planilla pagada. Se descarta la paridad
estricta con el legacy (que borraba la venta) porque el usuario prioriza trazabilidad
financiera sobre la réplica exacta del comportamiento legacy.

## Cambios en `InventarioController::restaurar(int $id)`

1. **Localizar la venta asociada al equipo.**
   `VentaEquipo::where('inventario_tienda_id', $id)->latest('id')->first()`.
   Se usa `latest('id')` (no `first()`) porque, al conservar filas en vez de borrarlas,
   un mismo `inventario_tienda_id` puede tener más de una fila histórica si el equipo
   fue vendido → restaurado → vuelto a vender. La fila más reciente es la venta activa
   que corresponde al estado `VENDIDO` que se está restaurando.
   Si no hay `VentaEquipo`, el equipo se restaura igual que hoy (sin tocar `ventas`) —
   cubre el caso de equipos cuyo `inventario_tienda_id` nunca se asoció a una venta
   normalizada.

2. **Bloqueo por planilla pagada.**
   Si existe venta asociada, se resuelve su período mediante `ventas.reporte_id →
   reportes.fecha` (paridad con el criterio que ya usa `PlanillaController` para
   agregar comisiones: `join reportes` + filtro de fecha) y su agente vía
   `ventas.vendedor_id`.

   Se considera "planilla cerrada/pagada" si existe una fila en `pagos_planilla` con:
   - `agente_id = venta.vendedor_id`
   - `estado = 'PAGADO'`
   - `reporte.fecha` entre `fecha_inicio` y `fecha_fin` (inclusive)

   Justificación de este criterio: `pagos_planilla` (creada por
   `ConstanciaController::crearBoleta`, migración
   `2026_06_11_000004_create_pagos_planilla_table`) es la única tabla del refactor que
   materializa una planilla "cerrada" — es un snapshot por agente + rango de fechas con
   estado `PENDIENTE|PAGADO`. No referencia `venta_id`/`reporte_id` directamente (es un
   agregado de boleta, no un detalle línea a línea), así que el único cruce posible con
   una venta puntual es por agente + fecha del reporte dentro del rango de la boleta.
   Esto es consistente con cómo `PlanillaController::calcularComisionesEquipo/Planes/Online`
   ya agregan comisiones: `ventas JOIN reportes` + `whereBetween('reportes.fecha', ...)`
   agrupado por `vendedor_id`.

   Si hay match → **422** con mensaje claro (p.ej. *"No se puede restaurar: la comisión de
   esta venta ya fue pagada en la planilla del {fecha_inicio} al {fecha_fin} (boleta
   #{id})."*) y **no se modifica nada** (ni equipo, ni venta, ni historial).

   Chequeo defensivo con `Schema::hasTable('pagos_planilla')` (igual que
   `AgenteController`/`ConstanciaController`) para no romper en entornos donde la
   migración aún no corrió.

3. **Si no está bloqueada, todo lo demás ocurre dentro de una única transacción
   (`DB::transaction`)** — antes el `update()` del equipo no estaba en transacción
   porque era la única escritura; ahora que también escribimos `ventas`, ambas deben
   ser atómicas:
   - `inventario_tiendas`: `estado='DISPONIBLE'`, `fecha_venta=null`,
     `vendido_por_id=null` (sin cambios respecto a hoy).
   - Si hay venta asociada: `ventas.comision_estado='ANULADA'`,
     `ventas.comision_generada=0`. **Se conserva** la fila de `ventas` y
     `venta_equipos` (no se borran) — trazabilidad por decisión explícita del usuario,
     aunque el legacy sí borraba.

4. **Auditoría en `historial_inventario`** (tabla que `restaurar` ya usa, sin columnas
   nuevas): se amplía el texto de `observacion` para incluir el detalle de la
   anulación cuando aplica — venta id y admin que ejecuta la acción (vía
   `Auth::user()->id`/`nombre`, ya que `historial_inventario.agente_id` referencia
   `agentes` — la tabla de vendedores, no de usuarios/admins — y un admin puede no
   tener fila en `agentes`; por eso el "quién" va en el texto, no en una columna
   nueva). El insert se mantiene en el mismo `try/catch` best-effort que ya existía
   (una falla de auditoría no debe bloquear la restauración ya confirmada en la
   transacción).

   No se agregan columnas (`anulada_por`, `anulada_en`, `motivo_anulacion`) a `ventas`:
   `comision_estado='ANULADA'` + el registro en `historial_inventario` ya cubren
   "qué pasó, cuándo (fecha_hora) y quién (texto)"; agregar columnas específicas de
   anulación solo para este flujo sería prematuro sin otro caso de uso que las necesite.

## Puntos de agregación auditados (dashboards/reportes que podían seguir sumando ventas ANULADAS)

| Controlador | Método | Sumaba `ganancia_snap`/`monto_total`/`comision_generada` sin filtrar `ANULADA` | Acción |
|---|---|---|---|
| `DashboardController` | `kpis()` | Sí — `ganancia_total` (líneas ~41-50, join `venta_equipos`+`venta_lineas` sin filtro de estado) | **Corregido**: añadido `->where('v.comision_estado', '!=', 'ANULADA')` |
| `DashboardController` | `anomalias()` | No — solo agrega `reportes.diferencia`/`estado`, no toca `ventas` | Sin cambios |
| `EstadisticasController` | `ventas()` | Sí — `$base` compartido por totales/por_tienda/series/top_planes/top_equipos suma `monto_total` y cuenta filas sin filtrar | **Corregido**: filtro agregado al `$base` (afecta las 5 sub-consultas que lo reutilizan) |
| `EstadisticasController` | `productividad()` | Sí — suma `comision_generada` por vendedor sin filtrar | **Corregido**: filtro agregado a la consulta |
| `EstadisticasController` | `rankingAgentes()` | Sí — mismo patrón que `productividad()` para el drill-down por categoría | **Corregido** |
| `EstadisticasController` | `subfiltrosRanking()` | No suma dinero, solo lista nombres de producto/plan distintos para poblar un `<select>`; incluir una anulada no infla ningún total | Sin cambios (se documenta por completitud) |
| `EstadisticasController` | `exportar()` | Sí — totales/tiendas/agentes del XLSX suman `monto_total`/`comision_generada` sin filtrar | **Corregido** |
| `HistorialController` | `kpis()` | Sí — mismo patrón `ganancia_total` que `DashboardController::kpis` | **Corregido** |
| `HistorialController` | `index()` | No — solo pagina `reportes.*`, no toca `ventas` | Sin cambios |
| `HistorialController` | `exportar()` | Sí — itera `reporte->ventas` (todas, sin filtrar estado) y suma `monto`/`comision` en subtotales por bloque | **Corregido**: se excluyen del recorrido las ventas con `comision_estado === 'ANULADA'` |
| `PanelFinancierasController` | `index()` | No — ya filtra explícitamente `comision_estado` a `PENDIENTE`/`APROBADA` (o el valor pedido por query string); nunca incluye `ANULADA` salvo que se pida expresamente | Sin cambios |
| `PanelFinancierasController` | `confirmarDesembolso()`/`revertirDesembolso()` | No aplica — operan por `id` de venta con `where comision_estado = 'PENDIENTE'/'APROBADA'` explícito | Sin cambios |
| `PlanillaController` | (referencia) | Ya excluía `ANULADA` en las 4 consultas de comisión (`calcularComisionesEquipo/Planes/Online` + otra) desde antes de este cambio | Sin cambios |

Fuera de alcance de esta auditoría (no estaban en la lista pedida, se documentan por si se
retoman después): `ReporteController::index/show` usan `withCount('ventas')`/carga de
relación para exhibir el número de ventas de un reporte en el listado admin — es un
conteo operativo (cuántas líneas tiene el reporte), no un agregado monetario, así que no
se tocó.
