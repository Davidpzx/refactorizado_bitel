# Spec — Gap P1: PDF de impresión de reporte perdió el detalle por categoría/vendedor/DNI/badges

Fecha: 2026-07-03
Estado: DISEÑO FINAL

## Alcance confirmado

Gap fuente: `docs/comparacion/gap_tienda_reportes_2026-07-02.md` fila "Imprimir/exportar reporte a PDF..."
(sección `reportes/`, gap #2 del top-5): el PDF generado por `ConstanciaController::reporte()` +
`resources/views/constancias/reporte.blade.php` es genérico (`# / Tipo Venta / Subtipo / Monto`) y
**no replica**:
- las secciones tituladas por categoría del legacy (`imprimir_reporte.php`): VENTAS POSTPAGO, VENTAS
  PREPAGO, EQUIPOS/ACCESORIOS,
- columna Vendedor,
- columna DNI/Cel. Cliente,
- badges de migración/upgrade/remate/extranjero,
- comisión generada y ganancia,
- el bloque de "Observaciones del día" con su propia sección.

**Ticket de impresión** (`TicketImpresionPage.tsx` + `TicketController`): verificado contra
`imprimir_ticket_ingreso.php` — ya tiene paridad completa de detalle (fecha, cajero, cliente, DNI/cel,
concepto, desglose de pago mixto, vuelto). La única diferencia real es el selector de tamaño térmico
58/80mm por usuario, que es el gap **P2 "formato de ticket"** explícitamente excluido de esta tarea.
No se toca `TicketImpresionPage.tsx` ni `TicketController`.

## Mecanismo: se mantiene DomPDF + Blade (sin librería nueva)

El refactor ya genera PDFs reales vía `Barryvdh\DomPDF\Facade\Pdf::loadView(...)` (usado también para
traslados, agente, boleta). Se sigue el mismo mecanismo para `constancias.reporte`; no se introduce
ninguna librería de export nueva.

## Esquema de datos (confirmado leyendo migraciones + modelos, no JSON legacy)

El legacy guardaba cada línea de venta como JSON en `reporte_categorias.detalle`. El refactor lo
normalizó en `ventas` (1 fila por venta) + subtablas opcionales `venta_equipos` / `venta_lineas`
(relación `hasOne`, no `hasMany` — una venta tiene a lo más un `equipo` o una `linea`):

- `ventas`: `tipo_venta` (`EQUIPO|ACCESORIO|POSTPAGO|PREPAGO|OTROS_FLUJO`, string libre sin ENUM en la
  migración; en el MySQL real de VPS el ENUM legacy no admite `'APOYO'` como valor, así que
  `ReporteController::procesarVentas()` lo persiste como `tipo_venta='OTROS_FLUJO'` + `subtipo='APOYO'`),
  `subtipo`, `vendedor_id` (agente que hizo la venta, **no** `reportes.agente_id` que es quien cuadró
  caja), `cliente_id` (FK a `clientes`), `cross_selling`, `tienda_destino`, `monto_total`,
  `comision_generada`, `es_remate`, `es_extranjero`.
- El modelo `Venta` tiene un accessor `tipoVenta()` que revierte `OTROS_FLUJO`+`subtipo='APOYO'` a
  `'APOYO'` al leer — por eso el PDF debe construirse leyendo `Venta` vía Eloquent (no `DB::table`
  crudo), así el agrupamiento por categoría ve `'APOYO'` correctamente en ambos entornos.
- `venta_equipos`: `producto_nombre_snap`, `imei_serial_snap`, `tipo_pago`, `financiera`, `precio_venta`,
  `costo_snap`, `ganancia_snap`, `por_cobrar_financiera`.
- `venta_lineas`: `plan_nombre_snap`, `tipo_alta`, `cantidad`, `cobrado_unitario`, `comision_unitaria`,
  `es_migracion`, `es_upgrade`, `es_esim`, `plan_anterior`.
- `clientes.dni_ruc` es el DNI/RUC (vía `venta.cliente_id`); no existe columna `dni_cliente` en `ventas`.
- `agentes.nombres` es el nombre a mostrar como "Vendedor" (mismo criterio que ya usa el header del PDF
  para `agente_nombre`, sin concatenar `apellidos` — consistente con el resto del código).
- `reporte_salidas`: `tipo` (`adelanto|gasto|pasaje|otro`), `monto`, `observacion` — ya se carga en
  `ReporteController::show()` para la vista on-screen pero el PDF actual no la usa.

**Hallazgo colateral (fuera de alcance, no se toca):** `ReporteDetallePage.tsx` (pantalla on-screen,
línea 700) filtra `otros = ventas.filter(v => v.tipo_venta === 'OTROS_FLUJO')` sin ningún filtro para
`'APOYO'` — las ventas de apoyo no se muestran en ninguna sección de la pantalla actual. Es un bug
preexistente de la UI on-screen, no del PDF; no está en el alcance de esta tarea (que es solo el PDF) y
no se corrige aquí.

## Diseño del PDF (`resources/views/constancias/reporte.blade.php`)

Se reconstruye para agrupar `Venta::with(['equipo','linea','cliente'])->where('reporte_id', $id)->get()`
por `tipo_venta` (post-accessor), replicando las secciones tituladas del legacy adaptadas al esquema
normalizado:

1. **Cabecera** — sin cambios (empresa, # reporte, fecha, tienda, cajero que cuadró, estado, caja
   inicial, total calculado, efectivo entregado, diferencia).
2. **Observaciones del día** — bloque propio (encabezado destacado), solo si `reporte.obs_dia` no está
   vacío. Restaura exactamente lo que el gap señala como perdido.
3. **1. VENTAS POSTPAGO** (`tipo_venta==='POSTPAGO'`) — columnas: Plan, Tipo Alta (traduce `MNP`→`PORT.`
   como el legacy), Cant., Cobrado, Vendedor, DNI/Cel. Cliente, Badges (Migración/Upgrade/eSIM/Remate/
   Extranjero/Cross→tienda_destino si `cross_selling`), Comisión. Fila de total (cobrado + comisión).
4. **2. VENTAS PREPAGO** (`tipo_venta==='PREPAGO'`) — mismas columnas que Postpago (el esquema no
   restringe migración/upgrade/eSIM a un tipo, a diferencia del legacy que omitía esa columna en
   prepago; se muestra igual en ambas para no ocultar datos reales).
5. **3. EQUIPOS Y ACCESORIOS** (`tipo_venta` en `EQUIPO`/`ACCESORIO`) — columnas: Producto, IMEI/Serial,
   Precio (si `tipo_pago==='CUOTAS'` usa `efectivo_inicial` de `ventas`, si no `precio_venta` de
   `venta_equipos` — mismo criterio que legacy), Tipo Pago, Financiera, Vendedor, DNI Cliente, Ganancia
   (`ganancia_snap`), Comisión. Fila de total (precio + ganancia + comisión).
6. **4. OTROS INGRESOS / SERVICIOS** (`tipo_venta==='OTROS_FLUJO'`, ya excluye `APOYO` gracias al
   accessor) — columnas: Concepto (`subtipo`), Vendedor, DNI Cliente (si aplica), Monto, Comisión.
7. **5. APOYO A OTRAS TIENDAS** (`tipo_venta==='APOYO'`) — columnas: Plan, Tipo Alta, Cant., Cobrado,
   Tienda Destino, Vendedor, DNI/Cel. Cliente, Comisión. Sección nueva respecto al PDF actual (el legacy
   sí la tenía como "5. VENTAS PARA OTRAS TIENDAS").
8. **6. SALIDAS Y GASTOS** — de `reporte_salidas` (tipo/monto/observación), solo si hay filas. Antes no
   se usaba en el PDF pese a estar disponible.
9. **7. CUADRE FINANCIERO Y EFECTIVO** — se mantiene la tabla actual (caja inicial, total calculado,
   efectivo entregado, diferencia) y se agregan dos filas nuevas: **Comisión Total Generada**
   (`sum(ventas.comision_generada)`) y **Ganancia Total (equipos)** (`sum(venta_equipos.ganancia_snap)`)
   — ambas nombradas explícitamente como perdidas en el gap doc.

Cada sección se omite por completo si no tiene filas (mismo criterio que el legacy `<?php if (!empty($x))
?>`).

## Cambios de código

### `app/Models/Venta.php`
Agregar relación `vendedor(): BelongsTo` → `Agente::class` con FK `vendedor_id` (no existía; hoy no hay
forma idiomática de resolver el nombre del vendedor sin una query manual). Es la única adición de
relación, análoga a `equipo()`/`linea()`/`cliente()` ya existentes.

### `app/Http/Controllers/Api/ConstanciaController.php` — `reporte(int $id)`
- Mantiene la query actual de cabecera (`DB::table('reportes as r')...`).
- Reemplaza `DB::table('ventas')->where('reporte_id', $id)->get()` por
  `Venta::with(['equipo', 'linea', 'cliente', 'vendedor'])->where('reporte_id', $id)->orderBy('id')->get()`.
- Agrega `$salidas = DB::table('reporte_salidas')->where('reporte_id', $id)->orderBy('id')->get();`.
- Pasa `$ventas` y `$salidas` a la vista (antes solo pasaba `$ventas` planas).

### `resources/views/constancias/reporte.blade.php`
Reescritura completa siguiendo el diseño de secciones de arriba, con el mismo estilo visual (paleta
azul/gris, tablas con `th`/`td`) que ya usa el archivo actual, para no introducir un segundo lenguaje
visual dentro del mismo documento.

## Tests (`backend/tests/Feature/ConstanciaReporteDetalleTest.php`)

El render es HTML (Blade) → PDF vía DomPDF; DomPDF solo convierte HTML ya correcto a binario, así que lo
verificable con test automatizado es el HTML que produce la vista con datos reales, no el binario PDF
final. Se cubre así:

1. **Smoke test HTTP**: `GET /api/v1/constancias/reporte/{id}` responde 200 con
   `Content-Type: application/pdf` y cuerpo no vacío, para un reporte con ventas de los 5 tipos.
2. **Test de contenido HTML** (el que realmente prueba el gap): construir en el test los mismos datos
   que arma el controller (`Venta::with(...)->get()` + `$salidas`) y renderizar
   `view('constancias.reporte', [...])->render()` directamente, para un reporte sembrado con:
   - una venta POSTPAGO con `es_migracion=true`, `cross_selling=true`+`tienda_destino`, vendedor y
     cliente distintos del creador del reporte,
   - una venta PREPAGO con `es_remate=true`, `es_extranjero=true`,
   - una venta EQUIPO a CUOTAS con `financiera`, `ganancia_snap`,
   - una venta OTROS_FLUJO con `subtipo` libre,
   - una venta APOYO con `tienda_destino`,
   - una fila en `reporte_salidas`,
   - `reporte.obs_dia` no vacío.
   Asserts sobre el HTML resultante: contiene el nombre del vendedor de cada venta (no el del creador
   del reporte), el DNI del cliente, las palabras "Migración"/"Remate"/"Extranjero"/el `tienda_destino`
   de cross-selling, el nombre de la financiera, el monto de ganancia, el monto de comisión total, el
   texto de `obs_dia`, y los 5 títulos de sección ("VENTAS POSTPAGO", "VENTAS PREPAGO", "EQUIPOS Y
   ACCESORIOS", "OTROS INGRESOS", "APOYO A OTRAS TIENDAS").
   No requiere seguridad de PDF: es exactamente "los datos que alimentan la vista", verificado sin
   decodificar binario.

**Verificación manual requerida (no cubierta por tests automatizados):** la paginación/salto de página
del PDF real (`page-break-inside`), el layout visual en A4 impreso, y que DomPDF no trunque tablas largas
— eso requiere abrir el PDF generado y mirarlo; se documenta honestamente como pendiente de ojo humano.

## Fuera de alcance (explícitamente)

- Selector de tamaño de ticket térmico 58/80mm (gap P2, ver arriba).
- Bug de `ReporteDetallePage.tsx` que no muestra ventas `APOYO` en pantalla (hallazgo colateral, no PDF).
- Autorización de acceso a `ConstanciaController::reporte()` (hoy no valida rol/tienda, a diferencia de
  `ReporteController::show()`) — preexistente, no introducido ni agravado por este cambio.
