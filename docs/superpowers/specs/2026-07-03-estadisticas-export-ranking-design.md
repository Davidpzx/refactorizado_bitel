# Diseño — Estadísticas de Ventas: export completo + ranking consistente

**Fecha:** 2026-07-03
**Rama:** `m2-estadisticas-ranking`
**Módulo:** 2 — Estadísticas de Ventas (2 gaps P1)
**Fuente de verdad:** `docs/comparacion/gap_gerencia_financiero_2026-07-02.md` §6, y legacy `E:\laragon\www\sis_bipay\gerencia\estadisticas_ventas.php`.

## Problema

Dos gaps P1 en `EstadisticasController`:

1. **Export Excel reducido + sin reasignación cross_selling.** El export del refactor
   sólo tiene hojas Resumen/Tiendas/Agentes con columnas fijas; el legacy exporta además
   Top Equipos, Top Accesorios, Top Planes, separa `EQ. CUOTAS`/`EQ. CONTADO`, agrega
   fila `TOTAL GLOBAL` y en la hoja de agentes **columnas dinámicas por plan de chip**.
   Además, ni el export ni el desglose por tienda respetan la **reasignación de ventas de
   apoyo inter-tienda**: cuando `ventas.cross_selling = 1` y hay `tienda_destino`, la venta
   debe contar para `tienda_destino`, no para la tienda del reporte (legacy §49-68, 414-490).

2. **Ranking de agentes inconsistente.** `productividad()` / `rankingAgentes()` cuentan
   ventas `es_remate` / `UPGRADE` / `PAQUETE`, mientras que `PlanillaController` (comisión de
   planes) y `PostpagoController` SÍ las excluyen en el mismo backend. El legacy también las
   excluye del ranking por agente (§250-253). Inconsistencia interna.

## Decisiones de diseño

### A. Exclusión de ranking compartida (gap 2)

Fuente única de verdad: `app/Support/RankingVentaScope.php`. Encapsula la condición que
ya usa `PlanillaController::calcularComisionesPlanes` (§346: "Excluye es_remate=1, subtipo
PAQUETE") más la exclusión de UPGRADE que ese controlador aplica por línea:

- `excluirRematesYPaquetes($q, $alias)` → `es_remate = false` **y** `subtipo IS NULL OR subtipo != 'PAQUETE'`.
- `excluirUpgrades($q, $alias)` → `whereNotExists` sobre `venta_lineas.tipo_alta LIKE '%UPGRADE%'` (UPGRADE vive a nivel línea).
- `aplicar($q, $alias)` → ambas.

Trabaja sobre `Illuminate\Database\Query\Builder` (los dos controladores usan `DB::table`,
no Eloquent), recibiendo el alias de la tabla `ventas` (`'ventas'` en Planilla, `'v'` en
Estadísticas). Para **no duplicar una tercera variante**, `PlanillaController` (rama sin
rangos de `calcularComisionesPlanes`, única que aplica ambas condiciones a nivel query) pasa
a usar `RankingVentaScope::excluirRematesYPaquetes`. La rama con rangos conserva su manejo
de UPGRADE/es_remate por fila (necesario para el conteo escalonado por rango) y no se toca.

Se aplica `RankingVentaScope::aplicar` a: `productividad()`, `rankingAgentes()` (rama general
y categorizada) y la hoja "Agentes" del export.

### B. Reasignación cross_selling → tienda_destino (gap 1)

Expresión de tienda efectiva (SQL, reutilizada en `ventas()` y `exportar()`):

```sql
CASE WHEN v.cross_selling = 1 AND v.tienda_destino IS NOT NULL AND v.tienda_destino <> ''
     THEN v.tienda_destino ELSE r.tienda_id END
```

Se agrupa el desglose por tienda por esta expresión (alias `tienda_id` para no romper el
contrato JSON existente). El filtro de acceso (`where r.tienda_id = $tienda`, fail-closed con
centinela `__SIN_TIENDA__`) **no se toca**: sigue filtrando por la tienda de origen del
reporte; sólo cambia la etiqueta/atribución del conteo, igual que la tabla web legacy.

### C. Export Excel a paridad (gap 1)

Hojas del `.xlsx` (paridad con las 5 secciones legacy):

1. **Resumen** — KPIs período/tienda (se conserva, se añade split cuotas/contado).
2. **Tiendas** — Posición, Tienda, Postpago, Prepago, Eq. Cuotas, Eq. Contado, Accesorios,
   Total + fila `TOTAL GLOBAL`; atribución con tienda efectiva (cross_selling).
3. **Agentes** — Posición, Agente, Tienda, Postpago, Eq. Cuotas, Eq. Contado, Accesorios,
   `[columnas dinámicas por plan de chip]`, Total + fila `TOTAL GLOBAL`; exclusión de ranking
   aplicada (es_remate/UPGRADE/PAQUETE).
4. **Top Equipos** — #, Equipo, Ventas (15).
5. **Top Accesorios** — #, Accesorio, Ventas (15).
6. **Top Planes** — #, Plan, Ventas (15), excluyendo paquete/upgrade.

Se mantiene `.xlsx` real (mejora técnica sobre el `.xls`-HTML legacy) y la firma/headers de
`streamDownload` actuales. Permisos sin cambio: `role:admin,tienda`, con `tiendaScope`
fail-closed intacto (tests `EstadisticasTiendaAccesoTest` deben seguir verdes).

## Mapeo legacy (JSON) → refactor (normalizado)

| Concepto legacy | Refactor |
|---|---|
| `reporte_categorias.tipo='postpago'` item | `ventas.tipo_venta='POSTPAGO'` |
| `chip_prepago` item + `cantidad` | `ventas.tipo_venta='PREPAGO'` + `venta_lineas.cantidad` |
| `equipos_accesorios` tipo_item/tipo_pago | `venta_equipos.tipo_item`/`tipo_pago` |
| plan de chip (columna dinámica) | `venta_lineas.plan_nombre_snap` (PREPAGO) |
| `es_upgrade` | `venta_lineas.tipo_alta LIKE '%UPGRADE%'` |
| `es_remate` | `ventas.es_remate` |
| plan contiene "paquete" | `ventas.subtipo='PAQUETE'` |
| `cross_selling='SI'` + `tienda_destino` | `ventas.cross_selling` + `ventas.tienda_destino` |

## Tests (TDD)

`tests/Feature/EstadisticasExportRankingTest.php`:
- Ranking excluye venta `es_remate`.
- Ranking excluye venta con línea `UPGRADE`.
- Ranking excluye venta `subtipo='PAQUETE'`.
- Reasignación: venta cross_selling cuenta para `tienda_destino` en `por_tienda`.
- Export: 200 + content-type xlsx; hojas esperadas presentes.
- Export: permiso tienda (fail-closed) intacto.

Regresión: `EstadisticasTiendaAccesoTest` sigue verde.

## Fuera de alcance

Frontend (no existe consumidor de estadísticas en este worktree). Filtro "TIPO DE VENTA" de
Top Agentes y popovers de la vista web (no son export ni ranking).
