# TICKET-041 — Paridad visual REAL contra el legacy en producción (auditoría dura + fixes)

**Origen:** feedback del usuario (2026-07-09) — "la verdad todo el diseño no he visto mejoras...
siento que no hay paridad con el legacy". El QA previo (`04-qa-visual.md`, 25 fiel / 9 mejorada)
se hizo contra datos sembrados en local; este ticket compara **ambos sistemas en producción, en
vivo**, con las mismas credenciales, sesión admin, mismo viewport (1440×900).

Nota: las skills `headroom`/`superpowers`/`frontend-design`/`browser` que menciona el ticket no
existen en este entorno (mismo hallazgo que dejaron anotado tickets anteriores) — se procedió con
Playwright temporal directo, igual que en el QA-026 original.

## Metodología

1. Playwright temporal instalado fuera del repo (`%TEMP%/t041_playwright`, borrado al cierre).
2. Login con `adminprueba@gmail.com` / `adminadmin` en ambos sistemas de producción:
   - Legacy: `https://mundoandroid.kyrocodelabs.cloud`
   - Refactor: `https://app.kyrocodelabs.cloud`
3. Captura de las 10 pantallas del alcance (Dashboard, Nuevo Reporte, Historial, Asistencias,
   Personal, Inventario, Precios, CRM, Planilla, Comisiones) en ambos — **solo lectura**, sin
   crear/editar/aprobar nada en ninguno de los dos sistemas de producción.
4. Comparación pixel-a-pixel de cada par, listando diferencias concretas (no veredictos blandos).
5. Fix de las diferencias que resultaron ser gaps reales (no decisiones de diseño ya tomadas).
6. Verificación del "después" contra **local** (SQLite + `QaDemoSeeder`, ver
   `plan/04-qa-visual-setup.md`) porque el ticket prohíbe escribir en producción.

## 1. Resultado por pantalla

| # | Pantalla | Veredicto tras comparar en vivo | Acción |
|---|---|---|---|
| 1 | Dashboard | Casi 1:1 salvo un gap real (ver abajo) | **Fix aplicado** |
| 2 | Nuevo Reporte (cuadre) | Estructura y colores ya calcados (confirma el trabajo de ticket-020); no se pudo comparar 100% porque el legacy mostraba un modal de "Borrador en la Nube" tapando la mitad de la pantalla, pero las secciones visibles (Ventas Postpago/Prepago/Equipos, Caja Inicial, Dinero No Físico, Salidas de Efectivo) coinciden en estructura y color | Sin cambios |
| 3 | Historial | Franja de KPIs, banner de Ganancia y columnas de tabla ya calcados (confirma ticket-029) | Sin cambios |
| 4 | Asistencias | Toolbar de acciones "aplanada" a un único estilo *glass* + bug real en las tarjetas KPI | **2 fixes aplicados** |
| 5 | Personal | Estructura equivalente; refactor usa modal para alta en vez de formulario inline (mejora de UX ya evaluada, no es un gap) | Sin cambios |
| 6 | Inventario | Falta la franja completa de 6 tarjetas KPI de capital invertido (gap ya anotado en `04-qa-visual.md` y nunca cerrado) | **Fix aplicado** |
| 7 | Precios | Refactor con datos reales se ve más rico que el legacy en este filtro puntual (el legacy mostraba "0 pendientes" por estado de sesión, no por diseño) | Sin cambios |
| 8 | CRM | Estructura distinta a propósito (IA en tabs: Tabla/Kanban/Temperatura/Analytics vs. dashboard único del legacy) — ya evaluado como mejora | Sin cambios |
| 9 | Planilla | Estructura y KPIs equivalentes, con más agentes reales visibles en refactor | Sin cambios |
| 10 | Comisiones | Refactor aterriza en "Planes" en vez de "Estrategia de Rangos" por defecto, pero ambas vistas existen y coinciden en contenido (confirma ticket-029) | Sin cambios |

**Conclusión de la auditoría:** la mayoría de las 10 pantallas SÍ tienen paridad real (confirma que
el trabajo de tickets 020/028/029/030 no se perdió). El desajuste entre "25 fiel / 9 mejorada" y la
percepción del usuario viene de un puñado de gaps puntuales pero **muy visibles** (la franja de
KPIs que falta en Inventario, la columna que falta en el Dashboard, la falta de color en los
botones de Asistencias) — exactamente el tipo de detalle que un usuario real nota al usar el
sistema todos los días aunque el "veredicto" QA general diga "fiel".

## 2. Hallazgos concretos y fixes aplicados

### 2.1 Dashboard — falta la columna "Ganancia" en la tabla de últimos reportes

El widget "Últimos Reportes del Período" del Dashboard (`DashboardPage.tsx`) no traía la columna
**Ganancia** que sí tiene el legacy (`panel_gerencia.php`) entre "Venta Total" y "Físico
Entregado". La columna existe en `/historial` desde ticket-029, pero nunca se replicó en el
widget del Dashboard porque es una query separada (`DashboardController::kpis`, `LIMIT 5`).

- **Backend:** `backend/app/Http/Controllers/Api/DashboardController.php` — se agregó el mismo
  `selectSub` de ganancia por fila que ya usa `HistorialController::index` (suma de
  `ve.ganancia_snap` + `vl.comision_unitaria * vl.cantidad`, excluyendo ventas `ANULADA`),
  admin-only.
- **Frontend:** `frontend/src/pages/DashboardPage.tsx` + `frontend/src/services/dashboard.api.ts`
  — columna nueva condicionada a `usuario.rol === 'admin'`, `colSpan` de los estados
  vacío/cargando ahora es dinámico según el número real de columnas.

Antes / Después (legacy de referencia incluido):
`refactor_principal/ticket-041/legacy_01_dashboard.png` (columna Ganancia visible en legacy) ·
`refactor_principal/ticket-041/refactor_01_dashboard.png` (antes, sin la columna, producción) ·
`refactor_principal/ticket-041/local_after_01_dashboard.png` (después, columna Ganancia presente).

### 2.2 Asistencias — toolbar sin color y KPIs atascados en "···" (bug real)

**a) Bug real de datos**, no solo visual: `AsistenciaController::index` calculaba los 4 KPIs
(Presentes/Ausentes/Tardanzas/Pend. Revisión) con `SUM(CASE WHEN ...)` **sin `COALESCE`**. Cuando
el rango de fechas no tiene ninguna marcación (p. ej. "Hoy" antes de que alguien fiche), `SUM()`
sobre 0 filas devuelve `NULL` en SQL, y el `StatCard` del frontend trata `null` igual que
`undefined` (estado "cargando"), mostrando `···` **para siempre**, nunca `0`. Confirmado en
producción (`refactor_04_asistencias.png`) y reproducido en local antes del fix. Se corrigió
envolviendo los 4 `SUM()` en `COALESCE(..., 0)` — mismo patrón que ya usa el endpoint hermano de
Neiry (`AsistenciaController.php:2002`).

**b) Toolbar aplanada:** el legacy usa botones sólidos y muy saturados por acción (PDF rojo, Excel
verde, Fotos naranja, Falta/Permiso cian, Manual morado outline). El refactor usaba la variante
`glass*` (fondo transparente, borde tenue) en las 4 acciones, lo que aplana el conjunto — a simple
vista, ninguna acción "salta" como en el legacy. Se cambiaron 2 de los 4 botones a variantes
sólidas ya existentes en el sistema de diseño: `Exportar Excel` → `variant="success"` (verde
sólido, igual que el legacy) y `Plantilla Neiry` → `variant="gold"` (dorado sólido, coherente con
la identidad Bitel). Los otros dos (`Asistencia Manual`, `Registrar excepción`) se dejaron en
estilo *glass* a propósito — el legacy también tiene un botón outline entre los cinco (Asistencia
Manual), así que 2 sólidos + 2 outline reproduce la mezcla real en vez de forzar 4 colores planos.

- **Backend:** `backend/app/Http/Controllers/Api/AsistenciaController.php` (4×`COALESCE`).
- **Frontend:** `frontend/src/pages/asistencias/AsistenciasPage.tsx` (2 variantes de botón).

Antes / Después: `refactor_principal/ticket-041/legacy_04_asistencias.png` ·
`refactor_principal/ticket-041/refactor_04_asistencias.png` (antes: toolbar plana + KPIs en
`···` con datos reales de producción, o sea el bug se puede confirmar ahí mismo) ·
`refactor_principal/ticket-041/local_after_04_asistencias.png` (después: KPIs con números reales
`5/0/0/1`, Exportar Excel en verde sólido, Plantilla Neiry en dorado sólido).

### 2.3 Ver Inventario — falta la franja de 6 KPI de capital invertido

Gap ya identificado en el QA visual original (`04-qa-visual.md`, fila 29, "pendiente — baja,
polish") y nunca cerrado. El legacy (`tienda/ver_inventario.php`) abre con 6 tarjetas: 3 de
"Capital Invertido" (Equipos/Accesorios/Chips, en S/, con borde dorado) y 3 de "Total" (unidades
por tipo). El refactor no tenía ningún endpoint que calculara esto — se creó desde cero replicando
exactamente las fórmulas del legacy:

- `capital_equipos` / `capital_accesorios` = `SUM(precio_costo * cantidad)` sobre
  `inventario_tiendas` con `estado = 'DISPONIBLE'`, filtrado por tienda si aplica.
- `capital_chips` = `SUM(stock_actual)` de `inventario_chips` (costo fijo S/1.00/unidad, igual que
  el legacy).
- `total_uds_equipos` / `total_uds_accesorios` = mismas condiciones, en unidades.

- **Backend:** `backend/app/Http/Controllers/Api/InventarioController.php` — nuevo método
  `capitalInvertido()`, admin-only (devuelve ceros para no-admin, igual que el legacy oculta el
  bloque por rol). Ruta nueva en `backend/routes/api.php`:
  `GET /v1/inventario/capital-invertido`.
- **Frontend:** `frontend/src/pages/inventario/InventarioPage.tsx` — nuevo
  `CapitalInvertidoWidget`, reutiliza el componente `StatCard` que ya usa el Dashboard (consistencia
  visual entre pantallas), con los mismos iconos Phosphor que el legacy usa vía `ph-*`
  (`DeviceMobileCamera`, `Headphones`, `SimCard`) y el borde dorado de la identidad Bitel.

Antes / Después: `refactor_principal/ticket-041/legacy_06_inventario.png` ·
`refactor_principal/ticket-041/refactor_06_inventario.png` (antes: página arranca directo en las
alertas, sin la franja de capital) ·
`refactor_principal/ticket-041/local_after_06_inventario.png` (después: franja completa con datos
reales del seeder — S/28,150 en equipos, S/581 en accesorios, 38/40/0 unidades).

## 3. Pendientes (no abordados en esta pasada, por alcance/riesgo)

- **Reporte BCP** — tabla plana vs. agrupación jerárquica del legacy (ya en `04-qa-visual.md`,
  media-baja, no se re-auditó porque no está en las 10 pantallas de este ticket).
- **Botón "Registrar Cuadre" del Dashboard es índigo/morado en el refactor y azul en el legacy** —
  detectado en la comparación en vivo, pero es un caso aislado de un único botón (no un patrón
  sistemático: `default` = índigo es un token de diseño ya establecido y usado en toda la app,
  incluido el login). Cambiarlo requeriría decidir si se re-tematiza ese único CTA o el token
  global; se deja como nota para una decisión de producto explícita, no como bug.
- El resto de hallazgos "baja" de `04-qa-visual.md` (tab inicial de Productividad, columna
  Razón Social Bipay en Usuarios, búsqueda por N° Ticket, ícono duplicado Comprobantes/Facturación,
  columna CARGO en Planilla) siguen igual — no forman parte de las 10 pantallas de este ticket y no
  se detectó nada nuevo sobre ellos en la comparación en vivo.

## 4. Verificación

- `php artisan test` (backend completo): **632 passed (2133 assertions)**.
- `npx tsc -b` (frontend): limpio, cero errores.
- `npm run build` (frontend): build de producción exitoso.
- Cero cambios de lógica de negocio — los 3 fixes son: 1 columna nueva de solo-lectura (dashboard),
  1 `COALESCE` que corrige un bug de agregación SQL + 2 variantes de botón (asistencias), y 1
  endpoint nuevo de solo-lectura + widget (inventario).
- Producción: **no se escribió nada** en ninguno de los dos sistemas — todas las capturas fueron
  navegación de solo lectura. Los "después" se verificaron en local (SQLite + `QaDemoSeeder`)
  porque escribir en producción está fuera del alcance permitido por este ticket.
- Playwright temporal desinstalado por completo al cierre; servidores locales (`php artisan
  serve`, `npm run dev`) detenidos por PID exacto, sin tocar otros procesos `node`/`php`.

## 5. Evidencia

Capturas referenciadas en este informe (9 imágenes, 3 pares antes/después + su referencia legacy)
en `C:\xampp\htdocs\refactor_principal\ticket-041\` — fuera de `plan/`, junto al resto de evidencia
histórica del proyecto (`refactor_principal\legacy\*.png`).
