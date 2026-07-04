# Spec — Gaps P1: export Excel de auditoría por-reporte + export general del Dashboard

Fecha: 2026-07-03
Estado: DISEÑO FINAL

Fuente del gap: `docs/comparacion/gap_gerencia_financiero_2026-07-02.md`, filas "Exportar Auditoría Excel de UN
reporte individual" (sección 2) y "Exportación Excel GENERAL del dashboard" (sección 3). Legacy leído completo:
`E:\laragon\www\sis_bipay\gerencia\exportar_excel.php` (480 líneas) y el bloque `EXPORTACIÓN GENERAL A EXCEL`
de `E:\laragon\www\sis_bipay\gerencia\panel_gerencia.php:97-189`.

## Gap 1 — Export Excel por-reporte (`exportar_excel.php`)

### Permisos

Legacy: admin (todo) o `tienda` dueña del reporte (`rep.tienda_id === session.tienda_id`). El refactor ya
tiene `ReporteController::autorizarPropietarioOAdmin()` (admin o `usuario_id` creador) usado por
`show`/`destroy`/`actualizarCabecera`/etc. Se reutiliza tal cual — **no** se reimplementa el check por
`tienda_id`, para no introducir un segundo modelo de autorización distinto al resto del controller (ya es una
divergencia documentada y aceptada en el gap: sección 2, fila `eliminar_reporte`).

### Endpoint

`GET /v1/reportes/{reporte}/exportar-excel` → `ReporteController::exportarExcel()`. Mismo controller que
`show()` (no un controller nuevo), mismo helper de autorización, mismo patrón PhpSpreadsheet que
`HistorialController::exportar()`/`EstadisticasController::exportar()`.

### Contenido (paridad de secciones con el legacy, adaptado al esquema normalizado)

Fuente de datos: `$reporte->load(['ventas.equipo', 'ventas.linea', 'ventas.cliente', 'salidas'])` +
`historialReportes()` filtrado a `accion IN ('edicion_reporte','edicion_critica','edicion_restaurada')`
(idéntico filtro SQL al legacy) con `usuario` para el nombre del editor.

Los "vendedores" del legacy (`nomAg()`, mapa `agentes.id → primeras 2 palabras de nombres`) se resuelven con
`DB::table('agentes')->pluck('nombres', 'id')` (una sola query, solo para este reporte).

Ocho bloques, mismo orden que el legacy:

1. **Ventas Postpago** — ventas con `tipo_venta==='POSTPAGO'` y NO apoyo (`cross_selling` false y
   `tipo_venta!=='APOYO'`). Columnas: Plan (`linea.plan_nombre_snap`), Tipo Alta (`linea.tipo_alta`, mapeado
   `MNP→PORT.`), Cant. (`linea.cantidad`), Cobrado (`linea.cobrado_unitario * cantidad`), Vendedor (mapa
   agentes), DNI/Cel Cliente (`cliente.dni_ruc`), Migración/Upgrade (`es_remate→Remate`,
   `linea.es_migracion→Migración`, `linea.es_upgrade→Upgrade`, si no `—`; + sufijo `Extranjero` si
   `es_extranjero`). Fila total con `COUNT` y `SUM(cobrado)`.
2. **Ventas Prepago** — igual que Postpago pero `tipo_venta==='PREPAGO'`, sin columna Migración/Upgrade
   (paridad exacta con el legacy, que tampoco la tenía en esta sección).
3. **Equipos y Accesorios** — `tipo_venta IN ('EQUIPO','ACCESORIO')`. Columnas: Producto
   (`equipo.producto_nombre_snap`), IMEI/Serial (`equipo.imei_serial_snap`), Precio (`venta.monto_total` —
   mismo campo que ya usa `HistorialController::exportar()` y `ReporteDetallePage.tsx::montoVenta()` para
   equipos; se evita reintroducir la distinción CONTADO/CUOTAS del legacy porque el campo `efectivo_inicial`
   actual no es su equivalente semántico y ningún otro punto del refactor la usa así), Tipo Pago
   (`equipo.tipo_pago`), Financiera (`equipo.financiera` o `—`), Vendedor, DNI Cliente. Fila total.
4. **Recargas, Pagos y Otros Servicios** — misma lista que `HistorialController::exportar()`
   (`recarga_bipay`, `pago_servicio`, `pago_krece`, `pago_payjoy`, `tickets_tusamy` — solo si `!=0`) +
   ventas `tipo_venta==='OTROS_FLUJO'` como "Otros Ingresos: {subtipo}". Fila total.
5. **Ventas para Otras Tiendas (Apoyo)** — ventas con `cross_selling===true` o `tipo_venta==='APOYO'`
   (misma condición `esApoyo` que ya usa `HistorialController::exportar()` línea 138). Columnas: Plan, Tipo,
   Cant., Cobrado, Tienda Destino (`venta.tienda_destino`), Vendedor, DNI/Cel Cliente. Fila total con conteo
   de unidades (`cantidad`) y suma de cobrado.
6. **Salidas y Gastos** — `reporte.salidas`: Tipo (`strtoupper`), Monto, Observación. Fila total.
7. **Dinero Digital y Retiros** — filas condicionales (`> 0`) para Yape/Bipay/Transferencia/Retiro Bipay;
   "Sin ingresos digitales" si todo es 0.
8. **Cuadre Financiero** — replica exacta de los 3 bloques del legacy (Movimientos en Sistema → Efectivo
   Neto; Dinero Físico Total en Local → Total Contado en Cajón; Cierre de Entrega y Cuadre → Diferencia +
   Estado CUADRE EXACTO/FALTANTE/SOBRANTE), usando los campos ya existentes en `reportes`
   (`total_calculado`, `yape/bipay/transferencia/retiro_bipay`, `total_salidas`, `caja_inicial`,
   `efectivo_entregado`, `diferencia`). Destino declarado con las 5 etiquetas ya usadas en
   `DashboardPage.tsx::DESTINOS` (BANCO/GERENCIA/EN_CAJA/AGENTE/TIENDA) en vez del binario legacy
   TIENDA/ENTREGADO (el dominio de valores ya cambió en el refactor, documentado en el gap).
9. **Historial de Ediciones** (solo si no está vacío) — Fecha/Editor, Tipo (mapeado igual que el legacy:
   `edicion_critica→"Edición con cambio de comisión"`, `edicion_restaurada→"Comisión restaurada"`,
   default→"Edición de datos"), Detalle.

Ventas con `comision_estado==='ANULADA'` se excluyen de todos los bloques (mismo criterio que
`HistorialController::exportar()`, que ya lo hace por consistencia — el legacy no tenía este concepto porque
no existía anulación de ventas individuales en su momento; se documenta como mejora, no regresión).

Estilo visual: mismos colores de cabecera que el legacy (`hdr-blue #0d6efd`, `hdr-teal #0dcaf0`,
`hdr-green #198754`, `hdr-orange #fd7e14`, `hdr-purple #6610f2`, `hdr-red #dc3545`, `hdr-gray #495057`) vía
`Fill::FILL_SOLID`, fila total con fondo `#d4edda`, siguiendo el patrón de estilos ya usado en
`HistorialController::exportar()`.

Nombre de archivo: `Reporte_Detallado_{id-5digitos}.xlsx` (paridad con `Reporte_Detallado_#00123.xls` del
legacy, adaptado a `.xlsx` real como ya hicieron `HistorialController`/`EstadisticasController`).

### Ruta

```php
Route::get('reportes/{reporte}/exportar-excel', [ReporteController::class, 'exportarExcel']);
```
Dentro del grupo `auth:sanctum` existente de `reportes/{reporte}` (línea ~117 de `routes/api.php`), sin
middleware `role:` adicional — la autorización vive en `autorizarPropietarioOAdmin()` como el resto del
controller.

## Gap 2 — Export general del Dashboard (`Reporte_Ventas_Desglosado.xls`)

### Permisos — decisión de alcance

Legacy: admin directo, o rol `tienda` con `agentes.es_gerencia==='jefe_tienda'` vía modal DNI+PIN
(`panel_gerencia.php:31-45,97-99`). El refactor **no tiene** el sub-rol `jefe_tienda` como gate de
autorización en ningún endpoint existente — `EnsureRole` solo conoce `admin`/`tienda`/`vendedor`
(gap estructural ya documentado como pendiente en `docs/comparacion/GAPS_PENDIENTES_v2.md`, punto T1.2,
"parcial: backend listo, falta UI"). Inventar aquí un mecanismo de autorización nuevo (verificar PIN +
propagarlo a un endpoint de descarga GET stateless) para un solo botón de exportación sería expandir el
alcance de este gap puntual hacia el gap estructural de roles, que es un trabajo aparte.

**Decisión**: este endpoint queda **admin-only** (`role:admin`), replicando el "admin directo" del legacy.
El acceso `jefe_tienda`-vía-PIN se deja explícitamente fuera de alcance y así se documenta; se resolverá
cuando se implemente el sub-rol `jefe_tienda` completo (T1.2). Esto es un subconjunto estricto de los
permisos legacy (nunca más permisivo), por lo que no introduce una regresión de seguridad.

### Endpoint

`GET /v1/dashboard/exportar` → `DashboardController::exportar(Request $request)`, con los mismos filtros que
`kpis()` (`fecha_desde`, `fecha_hasta`, `tienda` — sin el `LIMIT 5`, exporta todo el rango filtrado, igual
que el legacy que exporta el `$whereSql` completo sin límite).

### Formato — igual al legacy: una fila por categoría/producto (NO el formato de bloques de
`HistorialController::exportar`)

El gap ya señala que el formato "bloques" de Historial es distinto y no sustituye este caso de uso. Columnas
(idénticas al legacy `panel_gerencia.php:117-127`):

`ID Cuadre | Fecha | Tienda | Agente | Categoría | Producto / Plan | Atributos / Detalles | Monto Ingresado S/ | Destino Efectivo Físico`

Por cada reporte del rango filtrado, una fila por cada venta asociada (join `ventas.equipo`/`ventas.linea`/
`ventas.cliente`), más una fila agregada por cada servicio no-cero (`recarga_bipay`, etc., igual que en el
export por-reporte). Si el reporte no tiene ninguna venta ni servicio, una fila "SIN VENTAS" (paridad con
`count($categorias)==0` del legacy).

Mapeo Categoría/Producto/Atributos por tipo de venta:

- `POSTPAGO`/`PREPAGO`/`APOYO` → Categoría = tipo (+ "APOYO" si `cross_selling`), Producto =
  `linea.plan_nombre_snap`, Atributos = `"Tipo Alta: {tipo_alta} | Cant: {cantidad}"` + DNI si existe.
- `EQUIPO`/`ACCESORIO` → Categoría = tipo, Producto = `equipo.producto_nombre_snap`, Atributos =
  `"IMEI/Serie: {imei}"` o `"Sin IMEI"` (paridad literal con el legacy línea 154).
- `OTROS_FLUJO` → Categoría = "OTROS FLUJO", Producto = `"Otros Ingresos: {subtipo}"`.
- Servicios agregados de `reportes` → Categoría = nombre del servicio (ver mapa de
  `HistorialController::exportar`), Producto = mismo nombre, Atributos = `"-"`.

Monto: `venta.monto_total` (o el monto del servicio agregado). Destino: `reporte.destino_efectivo ?? 'TIENDA'`.

Nombre de archivo: `Reporte_Ventas_Desglosado_{fecha_desde}_a_{fecha_hasta}.xlsx`.

### Ruta

```php
Route::get('dashboard/exportar', [DashboardController::class, 'exportar'])->middleware('role:admin');
```

## Frontend

### `ReporteDetallePage.tsx`

Botón "Exportar Excel" junto al ya existente "Exportar a PDF" (línea ~721), mismo patrón `descargar()` ya
definido en la página:

```tsx
<Button variant="glassSuccess" size="sm" onClick={() => descargar(`/v1/reportes/${reporte.id}/exportar-excel`, `Reporte_Detallado_${reporte.id}.xlsx`)} className="gap-2">
  <FileSpreadsheet size={15} /> Exportar Excel
</Button>
```

Visible para todos los que pueden ver el reporte (el gate real es el backend); no hace falta ocultarlo por
rol en el frontend porque `autorizarPropietarioOAdmin` ya solo permite ver el reporte a quien puede
exportarlo.

### `DashboardPage.tsx`

Botón "Exportar Excel" en `PageHeader.actions`, junto al botón de Anomalías, visible solo
`usuario?.rol === 'admin'` (igual gate que el botón de Anomalías ya existente). Usa `fetch` + blob download
con los `appliedFilters` actuales, mismo patrón que `HistorialPage.tsx::exportarCSV()`.

## Tests (`backend/tests/Feature/`)

### `ReporteExportarExcelTest.php`

1. Admin exporta reporte de cualquier tienda → 200, `content-type` xlsx.
2. Usuario `vendedor` dueño del reporte (mismo `usuario_id`) → 200.
3. Usuario `vendedor` que NO creó el reporte → 403.
4. Reporte con ventas en las 4 categorías (postpago/prepago/equipo/apoyo) + salidas + historial de edición →
   200 (smoke test de que no explota armando las 9 secciones; no se asertan celdas exactas, solo que el
   archivo se genera y trae contenido en cada hoja vía `PhpOffice\PhpSpreadsheet\IOFactory::load`).

### `DashboardExportarExcelTest.php`

1. Admin exporta → 200, content-type xlsx.
2. `vendedor` (rol tienda) → 403.
3. Filtro de fecha/tienda se respeta (reporte fuera de rango no aparece — se verifica contando filas vía
   `IOFactory::load` sobre el stream).

Fallas preexistentes que no son de este cambio (no tocar): `SecurityParityTest`,
`PhaseCOperationalParityTest`, `ReporteStoreParityTest`.
