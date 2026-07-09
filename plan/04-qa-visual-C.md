# TICKET-026 — QA visual en vivo, Bloque C (7 pantallas)

Metodología: mismo patrón que Bloque A (`plan/04-qa-visual.md`) — backend SQLite +
Playwright temporal en 1440×900, sesión `admin@qa.test`. Diferencias de esta pasada:

- **Puertos alternativos** (Bloque A/B ya ocupaban 8000/5173 en paralelo): backend
  `php artisan serve --port=8001`, frontend `vite --port=5174`. Se agregó
  `http://localhost:5174` a `backend/config/cors.php` (`allowed_origins`) porque estaba
  hardcoded solo a `5173` — sin eso el login fallaba en silencio ("Error al iniciar
  sesión") por bloqueo CORS, no por credenciales. **Se revierte al cerrar esta pasada.**
  Otro worker en paralelo agregó `5175` al mismo array — se dejó intacto.
- **Base de datos aislada**: se copió `backend/database/database.sqlite` a
  `database_qa_c.sqlite` y se apuntó vía variables de entorno de proceso
  (`DB_DATABASE=...`), **sin tocar `backend/.env`** ni el `database.sqlite` compartido,
  para no pisar el trabajo de otros bloques corriendo en paralelo.
- **Datos adicionales**: `QaDemoSeeder` (Bloque A) no cubre comisiones, financieras a
  cuotas, postpago, ni traslados. Se agregó un script puntual
  `backend/qa_seed_c.php` (ejecutado una sola vez con `php artisan tinker
  qa_seed_c.php` contra la BD aislada) que siembra `config_comisiones`,
  `comisiones_rangos`, `comisiones_planes`, 3 ventas EQUIPO/CUOTAS con financiera
  Krece/PayJoy, 5 ventas POSTPAGO con distintos `tipo_alta`, y 3 `traslados_stock` en
  distintos estados. **Se borra al cerrar esta pasada** (no se commitea, mismo criterio
  que Playwright).
- **Tablas que no existen en ningún entorno local** (ni siquiera tras
  `migrate:fresh`): `cuentas_bipay`, `transacciones_bipay`, `reportes_bcp`. No hay
  ninguna migración que las cree — el comentario en
  `2026_07_02_000001_create_integrador_bitel_tables.php` confirma que en producción
  estas tablas **ya existen creadas por el legacy MySQL** y el refactor solo las
  extiende con columnas nuevas. Esto es infraestructura conocida, no un bug de esta
  pasada — pero sí permitió verificar en vivo cómo cada pantalla maneja el caso
  "tabla no existe" (ver hallazgos).

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

## Tabla de veredictos

| # | Pantalla | Ruta refactor | Comparado contra | Veredicto | Notas |
|---|---|---|---|---|---|
| 1 | Comisiones | `/comisiones` | Notas de inventario (sin FireShot identificado para esta vista específica) | **Parcial → confirmado en vivo** | Tabla de planes (POSTPAGO/PREPAGO/EQUIPO) con columnas Tipo/Nombre/Alta/Fee/DNI N1/DNI N3/EXT N1/EXT N3/Acciones, filtro por tipo, botones "Tarifas operativas", "Estrategia y rangos", "Recálculo masivo", "Nuevo plan". Coincide con lo documentado en `docs/comparacion/verificacion-comisiones-empresa.md` (ticket-012, 2026-07-08): funcionalmente completo. |
| 2 | **Comisiones Empresa** | Fusionada en `/comisiones` (2 modales) | `FireShot Capture 019` (Estrategia de Comisiones / rangos) y `FireShot Capture 020` (Comisiones Empresa — página completa, 51 planes + Ganancias Operativas) | **Parcial** | Ver hallazgo #1 abajo — funcionalidad íntegra pero colapsada de 2 páginas legacy siempre visibles a 2 modales on-demand. |
| 3 | Financieras | `/financieras` | `FireShot Capture 021` (ya identificada en Bloque A/inventario) | **Fiel** | 3 KPI cards con borde superior de color (ámbar "Pendiente de cobro" / verde "Confirmado este mes" / índigo "Total facturado"), badges Krece/PayJoy en índigo, precios en amarillo, columna SALDO FINANCIERA en rojo/naranja para pendientes. Coincide con el patrón ya documentado en inventario. Botones "Confirmar Desembolso" / "Revertir" funcionan sobre datos reales sembrados. |
| 4 | Reporte BCP | `/reporte-bcp` | `FireShot Capture 022` (Módulo BCP, match directo) | **Parcial — gap estructural + bug confirmado** | Ver hallazgos #2 y #3 abajo. |
| 5 | Bipay/Anypay | `/panel-bipay` | `FireShot Capture 023` (Panel Bipay/Anypay, estado vacío — match directo) | **Degradada** (estado de tablas faltantes) | Ver hallazgo #4 abajo — con tablas presentes debería revalidarse en VPS/staging con datos reales; lo confirmado aquí es el manejo del caso "sin datos/tablas", que es peor que el legacy. |
| 6 | Postpago/Churn | `/postpago` | Notas de inventario (`panel_postpago.php`) — sin FireShot identificado | **Parcial → confirmado en vivo, buena fidelidad** | KPI cards con borde de color (Activaciones azul, Portabilidades azul, Altas Nuevas verde, Renovaciones violeta, Remates rojo con ícono de alerta, Comisión Activa verde), tabs Activaciones/Riesgo Churn/Analytics, tabla con badges de `tipo_alta` (Portabilidad/Upgrade/Renovación/Alta Nueva) y flags Remate/eSIM. Deviación ya documentada en inventario (ícono `Signal` vs `ph-chart-line-down` legacy) es aceptable, no se encontró nada nuevo. |
| 7 | Traslados | `/traslados` | Sin FireShot de la página de gestión (el legacy no tiene una página dedicada — ver hallazgo #5) | **Mejorada** (confirma inventario) | Tabs Equipos/Chips, filtros por tienda origen/destino y estado, tabla con badges de estado (En Camino azul, Sin Enviar ámbar, Completado verde) y botones de acción contextual (Confirmar/Confirmar lote, Aprobar/Rechazar, Constancia). Acceso rápido cyan "Aprobar Traslados" en el pie del sidebar, fiel al color legacy. `confirm()` nativo en cancelar ya estaba documentado — no se re-probó para no generar acciones destructivas de QA. |

## Hallazgos (para ticket de fix / polish)

### 1. Comisiones Empresa: página legacy siempre visible → 2 modales on-demand en el refactor
**Severidad: Media.** Archivo: `frontend/src/pages/comisiones/ComisionesPage.tsx` (botones
"Tarifas operativas" línea ~524 y "Estrategia y rangos", componentes `TarifasModal` /
`RangosOperativosModal` líneas 268-469).

En legacy, `comisiones_empresa.php` (`FireShot Capture 020`) es una página independiente
en el sidebar (ítem propio "Comisiones Empresa", separado de "Comisiones") que muestra
**en una sola vista continua**: la tabla de 51 planes, la sección "Ganancias Operativas
por Servicio" (Recargas %, Bipay/Krece/PayJoy S/) y 3 tarjetas con borde superior de
color (verde Bipay, rosa Krece, morado PayJoy) para los rangos por monto — todo visible
sin clics adicionales. `configurar_comisiones.php` (`FireShot Capture 019`, "Estrategia
de Comisiones") también es página propia, con secciones de borde superior cyan
(Postpago) y ámbar (Equipos) y banners explicativos sobre cómo funciona cada rango.

El refactor fusiona todo correctamente a nivel funcional (confirmado ya por
`docs/comparacion/verificacion-comisiones-empresa.md`) pero la fusión también colapsó
2 páginas siempre visibles en 1 página + 2 modales que requieren clic para revelarse, y
se perdieron los bordes de color por sección + los banners explicativos (`El rango es
el n° de plan vendido en el mes...`) que en legacy orientan al usuario. No es un
faltante funcional, pero sí una pérdida de "identidad visual por color de sección" —
consistente con el patrón ya señalado en inventario para otras pantallas.

**Nota de verificación:** al inspeccionar el modal "Estrategia y rangos" con
Playwright headless, las filas de BIPAY/KRECE/PAYJOY se veían visualmente en blanco en
la captura, pero `page.evaluate()` confirmó que los `<input>` sí tienen los valores
correctos en el DOM (`monto_min`, `monto_max`, `ganancia`). Es decir, **no hay bug de
datos** — puede ser una particularidad del renderizado headless. Vale una revisión
visual manual rápida en un navegador real antes de descartarlo del todo.

**Sugerencia:** evaluar si "Tarifas operativas" y "Estrategia y rangos" deberían vivir
como tabs de una sola vista (o sub-ruta `/comisiones/estrategia`) en vez de modales, y
recuperar el color-coding por sección (cyan Postpago / ámbar Equipos / verde-rosa-morado
Bipay-Krece-PayJoy) que existía en legacy.

### 2. Reporte BCP: tabla plana vs. agrupación jerárquica por tienda+turno del legacy
**Severidad: Baja-Media.** Archivo: `frontend/src/pages/bcp/ReporteBcpPage.tsx`.

`FireShot Capture 022` (Módulo BCP legacy) agrupa las filas por **fecha + tienda** con
una fila resumen (badge de operaciones totales + ícono de estado verde ✓ / rojo ⚠) y,
debajo, filas indentadas "↳ turno" por cada turno del día con el nombre del agente BCP y
sus incidencias en línea (`Faltó en caja 150.00`, `Fallos de sistema`). El refactor
(confirmado en vivo con 0 registros por las tablas ausentes localmente, y por lectura de
código) renderiza una tabla plana de una fila por registro, sin agrupación ni jerarquía
visual. Esto es una pérdida real de la forma en que gerencia lee estos datos (comparar
turnos del mismo día/tienda de un vistazo).

**Sugerencia:** agrupar la tabla por `sucursal_id` + `fecha` con fila resumen colapsable,
replicando el patrón visual de `FireShot Capture 022`.

### 3. Reporte BCP: `Total Operaciones: undefined` cuando la tabla no existe
**Severidad: Media (bug, no solo estético).**
Backend: `backend/app/Http/Controllers/Api/ReporteBcpController.php:20-26`.
Frontend: `frontend/src/pages/bcp/ReporteBcpPage.tsx:210`.

Cuando `reportes_bcp` no existe, el controlador retorna
`'kpis' => ['total_efectivo' => 0, 'total_tarjeta' => 0, 'total_registros' => 0]`
— **sin la clave `total_operaciones`** (la respuesta normal sí la trae, ver
`ReporteBcpController.php:42-47`). El frontend hace
`String(data.kpis.total_operaciones)` sin fallback, y al ser `undefined` renderiza
literalmente el texto **"undefined"** en la KPI card "Total Operaciones" (ver
captura `03_reporte_bcp.png`). Es un bug real independiente de si la tabla existe en
producción: cualquier`kpis` incompleto que llegue al frontend reproduce el mismo
síntoma.

**Fix sugerido:** agregar `'total_operaciones' => 0` al payload de warning en
`ReporteBcpController.php:24`, y opcionalmente `String(data.kpis.total_operaciones ?? 0)`
en el frontend como defensa adicional.

### 4. Bipay/Anypay: estado "tablas no configuradas" oculta toda la página (vs. legacy que sigue mostrando la UI completa)
**Severidad: Media-Alta.** Archivo: `frontend/src/pages/bipay/PanelBipayPage.tsx:217-224`.

```tsx
const warning = saldoData?.warning
...
if (warning) {
  return (
    <div className="flex items-center gap-3 ...">
      <AlertTriangle size={18} /> {warning}
    </div>
  )
}
```

Cuando `cuentas_bipay`/`transacciones_bipay` no existen (o cualquier otro `warning` que
el backend devuelva), el componente hace un `return` temprano que **reemplaza toda la
página** — no hay título, no hay tabs, no hay botones "Nueva Cuenta"/"Recargar Cuenta",
nada (ver `04_panel_bipay.png`: pantalla casi completamente negra, solo el banner
arriba). Comparar con `FireShot Capture 023`, que es el **estado vacío real del
legacy** (cuentas configuradas pero sin datos, no tablas ausentes): ahí sí se ve la
página completa — header con botones, sección "Declaraciones del Día" con su propio
mensaje vacío estilizado ("Ninguna tienda ha declarado saldo hoy"), sección "Historial
de Transacciones" con filtros y su propio mensaje vacío ("Sin transacciones registradas
aún."). El legacy nunca colapsa la página entera por falta de datos; cada sección
maneja su propio vacío. El propio `ReporteBcpController` (hallazgo #3) sigue este
patrón mejor — devuelve `data: []` y dibuja el shell completo con la tabla vacía —
pero `PanelBipayPage` no lo replica.

Esto es principalmente relevante para: (a) entornos de desarrollo local como este, y
(b) cualquier escenario real donde esas tablas tarden en poblarse o fallen
temporalmente — hoy eso tira la página entera a un banner, cuando podría degradar
igual de bien que Reporte BCP.

**Sugerencia:** que el `warning` se muestre como banner **superior** (igual que en
Reporte BCP) sin reemplazar el resto del árbol de componentes, dejando el resto de la
UI en su estado vacío normal.

### 5. Traslados: confirmado que el legacy no tiene página de gestión dedicada
Se revisó `FireShot Capture 027` (Bitácora de Stock) con la esperanza de encontrar la
pantalla de gestión de traslados del legacy; en realidad esa captura es un **log de
solo lectura** (secciones "Traslados de Equipos — 300 registros", "Traslados de
Accesorios — 199 registros", "Traslados de Chips — 32 registros", todas de solo
visualización). La gestión activa (crear/confirmar/aprobar) vive detrás del botón
"Gestión de Traslados" dentro de Ver Inventario (`FireShot Capture 026`) como modal, no
como página propia. Esto **confirma** (no contradice) el veredicto "Mejorada" que ya
tenía Traslados en `00-inventario-diseno.md` línea 134: el refactor le dio una página
dedicada con más funcionalidad (tabs, filtros, lotes) de la que el legacy nunca tuvo
como vista independiente. No se abrió ningún hallazgo nuevo aquí.

## Resumen de severidades

| Severidad | Pantalla | Archivo/componente sugerido | Desviación |
|---|---|---|---|
| Media-Alta | Bipay/Anypay | `frontend/src/pages/bipay/PanelBipayPage.tsx:217-224` | Estado "warning" reemplaza toda la página en vez de degradar por sección (hallazgo #4) |
| Media | Reporte BCP | `backend/app/Http/Controllers/Api/ReporteBcpController.php:24` + `frontend/src/pages/bcp/ReporteBcpPage.tsx:210` | `Total Operaciones: undefined` cuando `kpis` viene incompleto (hallazgo #3) |
| Media | Comisiones Empresa | `frontend/src/pages/comisiones/ComisionesPage.tsx` (modales `TarifasModal`/`RangosOperativosModal`) | 2 páginas legacy siempre visibles colapsadas a 2 modales, se pierde color-coding por sección y banners explicativos (hallazgo #1) |
| Baja-Media | Reporte BCP | `frontend/src/pages/bcp/ReporteBcpPage.tsx` (tabla) | Tabla plana vs. agrupación jerárquica fecha+tienda+turno del legacy (hallazgo #2) |
| Baja (a confirmar manualmente) | Comisiones Empresa | `RangosOperativosModal` en `ComisionesPage.tsx` | Inputs de BIPAY/KRECE/PAYJOY se ven en blanco en captura headless pese a tener el valor correcto en el DOM — posible artefacto de Playwright, no reproducido como bug de datos |

Ninguna pantalla de este bloque queda "genérica" o "faltante" sin hallazgo asociado.
Bipay/Anypay se marcó **degradada** específicamente por el manejo del estado sin
tablas/datos (hallazgo #4), no por su diseño con datos reales — eso requeriría
validarse en VPS/staging donde `cuentas_bipay` sí existe, fuera del alcance de esta
pasada local.

## Capturas

`C:/Users/Usuario/AppData/Local/Temp/qa026c_shots/` (temporal, no versionado):
`01_comisiones.png`, `01b_comisiones_tarifas.png`, `01c_comisiones_estrategia.png`,
`02_financieras.png`, `03_reporte_bcp.png`, `04_panel_bipay.png`,
`05b_postpago_recheck.png`, `06_traslados.png`.

## Cierre de entorno

Al terminar esta pasada se detuvieron los procesos propios de backend (`:8001`) y
frontend (`:5174`) por PID (no `taskkill /IM`), se revirtió la línea `5174` agregada a
`backend/config/cors.php` (dejando intacta la `5175` de otro worker), se borraron
`backend/qa_seed_c.php` y `backend/database/database_qa_c.sqlite`, y se desinstaló por
completo el Playwright temporal de `C:/Users/Usuario/AppData/Local/Temp/qa026c_playwright`
(incluido su `package.json`). No se tocó `backend/.env`, `backend/database/database.sqlite`
ni ningún archivo de otros bloques.
