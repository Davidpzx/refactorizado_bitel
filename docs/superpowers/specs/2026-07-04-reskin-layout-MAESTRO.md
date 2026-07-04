# Plan Maestro — Re-skin de Layout del Refactor hacia `sis_bipay`

**Fecha:** 2026-07-04
**Rama:** `reskin-arquitectura` (worktree `C:\xampp\htdocs\bitel-p0-5`)
**Objetivo:** que el refactor React (`frontend/`) tenga la **misma organización visual / estructura de layout** que el legacy `sis_bipay` (E:\laragon\www\sis_bipay). El **color ya está alineado** (índigo/oro). Lo que falta es **estructura**: agrupación de navegación, encabezados de página, tarjetas KPI, barras de filtro, tablas.
**Alcance de este doc:** SOLO plan. No se implementa código aquí. Lo ejecutan cuentas headless por tarea.

---

## 0. Hallazgo estructural principal (leer primero)

**El legacy NO es un navbar superior con pestañas. Es un sidebar vertical izquierdo** — igual que el refactor. Ambos comparten el mismo esqueleto:

| Aspecto | Legacy (`includes/header.php` + `estilos.css`) | Refactor (`AppLayout.tsx`) |
|---|---|---|
| Shell | `<nav class="sidebar">` fijo, 260px, flotante (`top:1rem left:1rem`), `border-radius:16px`, glass `blur(20px)` | `<aside>` flex, `w-[260px]`, `kyro-glass`, colapsable a `w-16` |
| Logo arriba | SVG "B" + nombre comercial + razón social | "SIS-KYRO" Orbitron + badge tienda |
| Secciones | `<span class="menu-section-title">` (uppercase, borde-izq oro) | separador calculado por `section` (uppercase, borde-izq oro) |
| Ítem activo | `.sidebar-link.active` → borde-izq 3px oro + gradiente | `navActive` → `border-l-[3px] border-kyro-gold` |
| Pie | avatar + rol + toggle tema + logout + campanitas | avatar + rol + logout; campana/tema arriba |

**Conclusión:** el CSS y el esqueleto ya coinciden ~90%. **La divergencia real es la AGRUPACIÓN y el ORDEN de las secciones del menú**, más pequeñas diferencias de estilo en 4 patrones de página (PageHeader, KPI card, barra de filtros, encabezado de panel/tabla). El re-skin es sobre todo **reorganizar el menú** y **unificar 4 patrones compartidos**, luego pasar página por página.

**Segundo hallazgo:** el refactor **reorganizó deliberadamente** el sidebar (ver comentario "auditoría de sidebar" en `AppLayout.tsx:26`). Sus secciones son 7 (`Operaciones / Pagos digitales / Personal / Inventario / Clientes y Marketing / Recursos y Finanzas / Administración`). El legacy admin usa 5 (`GERENCIA / ADMINISTRACIÓN / INVENTARIO / OPERACIONES / CONFIGURACIÓN`). Para el re-skin hay que **volver a las 5 secciones del legacy en su orden**.

**Tercer hallazgo:** el refactor tiene **más páginas top-level** que el legacy (Traslados, Kardex, Gestión Chips, Comprobantes, Control Mensual, Liquidación, Revisar Fotos, Diagnóstico, Clientes, Postulantes como página). En el legacy varias de esas son sub-páginas/modales sin entrada propia en el sidebar. **Decisión de diseño:** conservar esos ítems extra (no se borran rutas), pero **colocarlos dentro de la sección legacy más afín**, después de los ítems canónicos del legacy, para que el orden principal coincida con `sis_bipay`.

---

## 1. Mapa de navegación objetivo (copiado del legacy)

Fuente legacy: `E:\laragon\www\sis_bipay\includes\header.php` líneas 285-467.

### 1.1 Rol `admin` — orden y secciones exactas del legacy

Orden legacy (header.php): **GERENCIA → ADMINISTRACIÓN → INVENTARIO → OPERACIONES → CONFIGURACIÓN**.

| Sección legacy | Ítem legacy (label + `ph` icono) | Ruta legacy | Ruta refactor equivalente | Ítem refactor (label / `lucide`) |
|---|---|---|---|---|
| **Gerencia** | Dashboard `ph-squares-four` | `panel_gerencia.php` | `/` | Dashboard `LayoutDashboard` |
| Gerencia | Productividad `ph-trend-up` | `estadisticas_ventas.php` | `/estadisticas` | Estadísticas `BarChart2` |
| Gerencia | CRM y Marketing `ph-megaphone` | `crm_dashboard.php` | `/crm` | CRM `Megaphone` |
| Gerencia | Precios `ph-currency-circle-dollar` | `revisar_stock.php` | `/revisar-stock` | Revisar Stock `Package` |
| **Administración** | Tiendas `ph-storefront` | `tiendas.php` | `/tiendas` | Tiendas `Store` |
| Administración | Usuarios `ph-users` | `usuarios.php` | `/usuarios` | Usuarios `Users` |
| Administración | Personal `ph-identification-card` | `gestionar_agentes.php` | `/agentes` | Agentes `Users` |
| Administración | Asistencias `ph-calendar-check` | `panel_asistencias.php` | `/asistencias` | Asistencias `Clock` |
| Administración | Planilla `ph-money` | `planilla_agentes.php` | `/planilla` | Planilla `DollarSign` |
| Administración | Tickets `ph-ticket` | `tickets_emitidos.php` | `/tickets` | Tickets `Ticket` |
| Administración | Comisiones `ph-gear-fine` | `configurar_comisiones.php` | `/comisiones` | Comisiones `TrendingUp` |
| Administración | Comisiones Empresa `ph-buildings` | `comisiones_empresa.php` | *(sin ruta propia — ver Riesgos §5)* | — |
| Administración | Financieras `ph-handshake` | `panel_financieras.php` | `/financieras` | Financieras `Landmark` |
| Administración | Reporte BCP `ph-bank` | `reporte_bcp.php` | `/reporte-bcp` | Reporte BCP `FileText` |
| Administración | Bipay / Anypay `ph-wallet` | `panel_bipay.php` | `/panel-bipay` | Panel Bipay `CreditCard` |
| Administración | Churn / Postpago `ph-chart-line-down` | `panel_postpago.php` | `/postpago` | Monitor Postpago `Signal` |
| Administración | Mapa de Calor `ph-map-pin` | `mapa_calor.php` | `/mapa-calor` | Mapa de Calor `Activity` |
| **Inventario** | Ingreso Stock `ph-package` | `registrar_stock.php` | *(en refactor es acción dentro de `/inventario`)* | — |
| Inventario | Ver Inventario `ph-stack` | `ver_inventario.php` | `/inventario` | Inventario `Package` |
| Inventario | Bitácora Stock `ph-clipboard-text` | `ver_bitacora_stock.php` | `/bitacora-stock` | Bitácora Stock `BookOpen` |
| **Operaciones** | Reporte Diario `ph-file-plus` | `nuevo_reporte.php` | `/reportes/nuevo` | Nuevo Cuadre `ClipboardList` |
| Operaciones | QR Asistencia `ph-qr-code` | `qr_asistencia.php` | `/asistencias/qr` | (QR) `Clock` |
| **Configuración** | Perfil de Empresa `ph-buildings` | `configuracion_empresa.php` | `/configuracion` | Configuración `Settings` |
| Configuración | Integrador Bipay `ph-plugs-connected` | `configuracion_integrador.php` | `/integrador` | Integrador Bipay `Plug` |

**Ítems SOLO-refactor (no existen como entrada en el sidebar legacy).** Conservarlos, ubicados al final de la sección afín:
- `Administración`: `/admin/postulaciones` (Postulantes), `/diagnostico` (Diagnóstico), `/comprobantes` (Comprobantes).
- `Inventario`: `/traslados` (Traslados), `/inventario/kardex` (Kardex), `/chips-gestion` (Gestión Chips).
- `Administración`/`Personal` legacy: `/asistencias/control` (Control Mensual), `/asistencias/liquidacion` (Liquidación), `/revisar-fotos` (Revisar Fotos) → van junto a **Asistencias**.
- `Gerencia`: `/historial` (Historial) → el legacy lo abre desde el Dashboard; en refactor mantenerlo bajo Gerencia tras Dashboard.
- `/clientes` (Clientes) → el legacy no tiene sidebar-item; ubicar en **Gerencia** tras CRM (afinidad marketing) o dejar como sub-nav. Documentado como decisión menor.

> Nota: NO se crean rutas nuevas ni se borran rutas. Solo se cambia `section`, `label` (cuando difiera del legacy: "Estadísticas"→"Productividad", "Revisar Stock"→"Precios", "Agentes"→"Personal", "Panel Bipay"→"Bipay / Anypay", "Monitor Postpago"→"Churn / Postpago", "Nuevo Cuadre"→"Reporte Diario") y el **orden** del array `NAV_ITEMS`.

### 1.2 Rol `tienda` — orden legacy

Orden legacy (header.php:404-466): **MI PANEL → INVENTARIO → OPERACIONES → CONFIGURACIÓN**.

| Sección | Ítem legacy | Ruta refactor |
|---|---|---|
| **Mi Panel** | Historial `ph-clock-counter-clockwise` | `/historial` |
| Mi Panel | Productividad `ph-trend-up` | `/estadisticas` |
| Mi Panel | Mi Reporte Personal `ph-user-focus` | `/mi-historial` |
| Mi Panel | Reporte BCP (si `tiene_bcp`) `ph-bank` | `/reporte-bcp` |
| **Inventario** | Ingreso Stock / Ver Inventario / Bitácora Stock | `/inventario`, `/bitacora-stock` |
| **Operaciones** | Reporte Diario `ph-file-plus` | `/reportes/nuevo` |
| Operaciones | Tickets Emitidos `ph-ticket` | `/tickets` |
| Operaciones | QR Asistencia `ph-qr-code` | `/asistencias/qr` |
| **Configuración** | Integrador Bipay | `/integrador` |

> El filtro `roles` de cada `NavItem` ya controla la visibilidad; el separador de sección del refactor (`AppLayout.tsx:210`) se calcula contra el ítem visible anterior, así que la agrupación no se rompe por rol. **Solo hay que renombrar la sección "Operaciones" del refactor a las secciones legacy y reordenar.** Para el rol `tienda` la primera sección debe llamarse **"Mi Panel"** (el refactor hoy la llama "Operaciones"). Como el mismo array sirve a ambos roles, usar `section` que ya coincida con el rol dominante y aceptar que el label de la 1ª sección diga "Gerencia" para admin; para lograr "Mi Panel" en tienda se puede derivar el label de sección por rol (helper simple) — ver Tarea A.

---

## 2. Patrones de componentes a alinear

Para cada patrón: **antes (refactor actual)** → **objetivo (legacy)**. Citas de archivo incluidas.

### 2.1 PageHeader — encabezado de página
- **Antes (refactor):** `frontend/src/components/PageHeader.tsx`. Barra vertical de acento a la izquierda + título Orbitron 1.35rem + subtítulo + **línea divisoria degradada** debajo. Acciones a la derecha.
- **Objetivo (legacy):** `d-flex justify-content-between align-items-center mb-4` con `<h2 class="fw-bold">` precedido de **ícono relleno de color** (`ph-fill ...`) + título, y grupo de botones de acción a la derecha. **Sin barra vertical, sin línea divisoria.** Ejemplos: `panel_gerencia.php:251-287`, `panel_financieras.php:115-124`, `gestionar_agentes.php:311-331`.
- **Decisión:** modificar `PageHeader.tsx` para: (a) aceptar prop `Icon` (lucide) que se renderiza a la izquierda del título con color de acento; (b) sustituir la barra vertical por el ícono; (c) **quitar** la línea divisoria degradada (o volverla opcional `divider={false}` por defecto); (d) mantener título en peso bold pero tamaño ~`text-2xl`/`1.5rem`. Como `PageHeader` es compartido por casi todas las páginas, este cambio propaga el look legacy en bloque. **No cambiar la firma de props existentes (`title/description/actions/children`)** — solo AGREGAR `Icon` y `divider` opcionales para no romper llamadas.

### 2.2 KPI / StatCard — tarjeta de métrica
- **Antes (refactor):** el `KpiCard` vive **local** dentro de `DashboardPage.tsx:51-71` (y variantes en otras páginas, p.ej. `PanelBipayPage.tsx`, `PlanillaPage.tsx`, `AsistenciasPage.tsx`, `BitacoraStockPage.tsx`). No hay componente compartido → cada página lo reimplementa con estilos ligeramente distintos.
- **Objetivo (legacy):** tarjeta `glass-panel p-3/p-4 h-100` con **acento de 4px** (`border-left:4px solid <color>` o `border-top:4px`), etiqueta pequeña uppercase muted (`~0.70rem`, `#a1a1aa`) y valor grande bold (`h4`/`1.8rem`). Variante con ícono grande a la izquierda (`d-flex gap-3`). Ejemplos: `panel_gerencia.php:362-424` (grilla de 4 KPIs con `border-left` de color por métrica; sección "Dinero Digital" con ícono + label + valor), `panel_financieras.php:127-149` (3 tarjetas `border-top:4px`).
- **Decisión:** crear **componente compartido** `frontend/src/components/ui/StatCard.tsx` que encapsule el `KpiCard` local del Dashboard (que ya reproduce bien el patrón: `border-l-4`, label uppercase tracking, valor `text-xl` bold, glow sutil). Props: `title`, `value`, `accent` (color del borde), `icon?`, `align?` (`left`/`top`), `valueColorClass?`, `formatMoney?`. Luego migrar Dashboard y demás páginas a usarlo. **Este es el mayor gap de reuso.**

### 2.3 Barra de filtros
- **Antes (refactor):** `frontend/src/components/ListToolbar.tsx`. Panel con cromo propio: ícono índigo `SlidersHorizontal` + título "Filtros" + hairline degradado + blur. Bonito pero más "cargado" que el legacy.
- **Objetivo (legacy):** simplemente un `glass-panel p-3 mb-4` con un `<form method="GET" class="row g-2/g-3 align-items-end">`: labels pequeñas uppercase muted + inputs `form-control-sm`/`form-select-sm` + botón "FILTRAR" (`btn-warning`/`btn-glass-cyan`) + botón limpiar. **Sin cabecera "Filtros" ni ícono.** Ejemplos: `panel_gerencia.php:315-360`, `panel_financieras.php:152-193`.
- **Decisión:** mantener `ListToolbar` (ya lo usan muchas páginas) pero **atenuar el cromo** para acercarlo al legacy: volver **opcional** la cabecera (`title`/ícono) con `showHeader={false}` por defecto, o reducir su prominencia. Objetivo: que por defecto se vea como un panel de formulario sobrio. No romper el contrato actual (mantener `title`/`description` opcionales). Riesgo alto de propagación → cambio conservador.

### 2.4 Panel / Tabla con encabezado de sección
- **Antes (refactor):** `frontend/src/components/DataTable.tsx` — tarjeta redondeada, hairline oro superior, `thead` sticky uppercase muted, hover con acento. Ya **coincide muy bien** con el legacy.
- **Objetivo (legacy):** `glass-panel p-0 overflow-hidden` (a veces con `border-top:4px solid <color>`), opcional **tira de cabecera** (`px-4 py-3` con bg tenue de color, título + badge contador + acción) y luego `table-responsive > table table-hover align-middle` con `thead` de headers uppercase muted pequeños. Ejemplos: `panel_financieras.php:196-212`, `gestionar_agentes.php:396-417`, `panel_gerencia.php:448-467`.
- **Decisión:** `DataTable` se deja casi igual. AGREGAR patrón opcional de **"panel con cabecera de sección"**: usar el `SectionPanel.tsx` existente (`components/ui/SectionPanel.tsx`) como contenedor con `border-top` de acento + tira de título; envolver tablas/listas que en el legacy tienen esa cabecera. No reescribir `DataTable`.

### 2.5 Envoltura de página (ancho/densidad)
- **Legacy:** muchas páginas de gestión usan `container-fluid mb-5 style="max-width:1200px"` (contenido centrado y acotado); el dashboard usa ancho completo. Densidad alta (`g-3`, `p-3`, fuentes 0.7–0.95rem).
- **Refactor:** `<main class="app-canvas p-4 sm:p-6">` con `<Outlet>` a ancho completo (`AppLayout.tsx:403`).
- **Decisión:** para páginas de gestión que en legacy están acotadas, envolver el contenido en un contenedor `max-w-[1200px] mx-auto` (utilidad Tailwind) — decisión por página en Tareas C/D/E. El dashboard y paneles anchos quedan a ancho completo.

---

## 3. DESCOMPOSICIÓN EN TAREAS (independientes y paralelizables)

Orden de ejecución: **A y B primero (fundacionales, se pueden correr en paralelo entre sí). C, D, E dependen de B (patrones) y se corren en paralelo tras B.** A no bloquea a C/D/E a nivel de código (toca solo `AppLayout.tsx`), pero es el cambio más visible → arrancar ya.

> **Verificación obligatoria en TODAS las tareas:** `cd frontend && npm run build`. El build de prod usa `tsc -b` (más estricto que `tsc --noEmit`): un import sin usar, un prop sobrante o un tipo mal, **rompen el deploy**. Correr `npm run lint` también. No hacer commit si el build falla.

---

### TAREA A — Shell + Navegación
**Toca:** SOLO `frontend/src/components/AppLayout.tsx` (array `NAV_ITEMS` y, si hace falta, el render del label de sección).
**Debe lograr:**
1. Reordenar `NAV_ITEMS` para reproducir el orden del legacy (§1.1 admin / §1.2 tienda).
2. Renombrar `section` a las secciones legacy: **Gerencia, Administración, Inventario, Operaciones, Configuración** (admin) y **Mi Panel** como primera sección para `tienda`.
3. Renombrar labels que difieren del legacy (§1.1: Productividad, Precios, Personal, Bipay / Anypay, Churn / Postpago, Reporte Diario).
4. Mapear íconos lucide a la intención del `ph` legacy (usar los ya importados; añadir imports solo si faltan y ELIMINAR los que queden sin usar — o rompe `tsc -b`).
5. Colocar los ítems solo-refactor al final de su sección afín (§1.1).
6. (Opcional) Para que la 1ª sección diga "Mi Panel" en `tienda` y "Gerencia" en `admin`, derivar el label de sección por rol con un helper local; NO duplicar el array.
**NO toca:** rutas (`App.tsx`), páginas, componentes de patrón, CSS. No agrega/borra rutas ni cambia permisos `roles`.
**Verificar:** login como admin y como tienda; el separador de sección agrupa bien (lógica `AppLayout.tsx:210` ya lo hace por `section` vs ítem visible previo); `npm run build` limpio.

---

### TAREA B — Patrones compartidos (FUNDACIONAL, bloquea C/D/E)
**Toca:**
- `frontend/src/components/PageHeader.tsx` (§2.1): añadir props `Icon?` y `divider?` (default sin línea); render ícono+título estilo legacy. **Aditivo, no romper firma.**
- `frontend/src/components/ui/StatCard.tsx` (NUEVO, §2.2): extraer el `KpiCard` de `DashboardPage.tsx:51-71` a componente compartido con props (`title`, `value`, `accent`, `icon?`, `align?`, `valueColorClass?`, `formatMoney?`).
- `frontend/src/components/ListToolbar.tsx` (§2.3): hacer la cabecera "Filtros" opcional/atenuada (`showHeader?` default false), sin romper llamadas existentes.
**Debe lograr:** un set de primitivas que reproduzcan el look legacy, retrocompatibles (todas las páginas que ya las usan deben seguir compilando sin cambios).
**NO toca:** `AppLayout.tsx`, ni migra páginas todavía (eso es C/D/E). NO cambia `DataTable.tsx` salvo, si acaso, exportar helpers. NO borra el `KpiCard` local del Dashboard aún (Tarea C lo migra), para no romper el build intermedio.
**Verificar:** `npm run build` limpio con las 3 primitivas nuevas/ampliadas sin usar todavía (o usadas mínimamente). Cuidado: si `StatCard` queda sin importar en ningún lado, `tsc -b` no falla por un componente exportado sin usar, pero SÍ falla por imports/vars sin usar DENTRO del archivo.

---

### TAREA C — Páginas de Gerencia
**Depende de:** B.
**Toca:** `frontend/src/pages/DashboardPage.tsx`, `pages/estadisticas/*`, `pages/bipay/PanelBipayPage.tsx` (+ `CuadreBitelPage.tsx`), `pages/crm/*`, `pages/postpago/*`, `pages/analytics/MapaCalorPage.tsx`, `pages/bcp/*`, `pages/historial/*`, `pages/clientes/*`.
**Debe lograr por página:** (1) `PageHeader` con `Icon` estilo legacy; (2) grillas de KPI migradas a `StatCard` compartido (empezar por Dashboard: reemplazar `KpiCard`/`KpiCardDiferencia` locales — o dejarlos como wrappers finos sobre `StatCard`); (3) barra de filtros con `ListToolbar` sobrio; (4) tablas/paneles con encabezado de sección vía `SectionPanel` cuando el legacy lo tenga (`panel_financieras.php:196`, `panel_gerencia.php`); (5) contenedor de ancho acorde (dashboard/paneles anchos = full; los acotados en legacy = `max-w-[1200px]`).
**Referencia legacy:** `panel_gerencia.php:248-467`, `panel_financieras.php:112-212`, `estadisticas_ventas.php`, `panel_bipay.php`, `crm_dashboard.php`, `panel_postpago.php`, `mapa_calor.php`.
**NO toca:** `AppLayout.tsx`, primitivas (usarlas, no modificarlas), páginas de otras tareas, lógica de datos/servicios (`services/*`), rutas.

---

### TAREA D — Páginas de Administración
**Depende de:** B.
**Toca:** `pages/agentes/*` (AgentesPage, VerAgentePage, AgenteForm), `pages/planilla/*`, `pages/comisiones/*`, `pages/asistencias/*` (Asistencias, Control, Liquidación, RevisarFotos), `pages/tickets/*`, `pages/admin/*` (Postulaciones, Usuarios, Tiendas, Configuración, Integrador, Diagnóstico, Comprobantes).
**Debe lograr:** mismos 5 puntos de la Tarea C aplicados a estas páginas. Prestar atención a `gestionar_agentes.php` (header con botones "URL ..." a la derecha; paneles con `border-top:4px` + cabecera de sección con badge contador — replicar con `SectionPanel`).
**Referencia legacy:** `gestionar_agentes.php:306-425`, `planilla_agentes.php`, `configurar_comisiones.php`, `comisiones_empresa.php`, `panel_asistencias.php`, `tickets_emitidos.php`, `usuarios.php`, `tiendas.php`, `configuracion_empresa.php`, `configuracion_integrador.php`.
**NO toca:** shell, primitivas (solo usarlas), páginas de C/E, servicios, rutas.

---

### TAREA E — Páginas de Tienda / Inventario / Operaciones
**Depende de:** B.
**Toca:** `pages/inventario/*` (Inventario, BitacoraStock, Kardex, MatrizInventario), `pages/traslados/*`, `pages/*Chips*`, `pages/reportes/*` (NuevoReporte, ReporteDetalle, EditarReporte), `pages/historial/MiHistorial*`.
**Debe lograr:** mismos 5 puntos aplicados. Inventario/Bitácora usan tablas densas → alinear con `DataTable`/`SectionPanel`; Nuevo Reporte es un formulario largo → alinear encabezado y bloques `glass-panel`.
**Referencia legacy:** `tienda/ver_inventario.php`, `tienda/registrar_stock.php`, `gerencia/ver_bitacora_stock.php`, `reportes/nuevo_reporte.php`, `reportes/mi_historial.php`.
**NO toca:** shell, primitivas (solo usarlas), páginas de C/D, servicios, rutas.

---

### (Opcional) TAREA F — Pulido de densidad y modo claro
**Depende de:** C/D/E.
**Toca:** `frontend/src/index.css` (tokens ya centralizados) + retoques finos.
**Debe lograr:** igualar densidad/espaciado (paddings, tamaños de fuente 0.7–0.95rem, `gap` de grillas) y verificar que el modo claro (equivalente a `data-bs-theme="light"` del legacy, sidebar corporativo) se mantiene legible tras los cambios. Barrido visual admin+tienda, oscuro+claro.
**NO toca:** lógica; solo estilos/tokens.

---

## 4. Orden recomendado y paralelización

```
   ┌── TAREA A (shell/nav) ──┐
   │                          │  (A y B en paralelo)
   └── TAREA B (patrones) ───┘
                 │
        ┌────────┼────────┐   (C, D, E en paralelo, tras B)
        C        D        E
        └────────┼────────┘
              TAREA F (opcional, pulido)
```

- **A** es independiente a nivel de archivos (solo `AppLayout.tsx`) → puede ir primero o en paralelo con B.
- **B** es el prerequisito real de C/D/E (definen las primitivas que ellas consumen).
- **C/D/E** no comparten archivos entre sí → seguras en paralelo. Cada una consume las primitivas de B sin modificarlas.
- Si dos tareas headless corrieran a la vez sobre B y C, C debe esperar a que B esté commiteada (C importa `StatCard`, ampliaciones de `PageHeader`/`ListToolbar`).

---

## 5. Riesgos y verificación

### Riesgos
1. **`PageHeader.tsx`, `ListToolbar.tsx`, `DataTable.tsx` son compartidos por ~todas las páginas.** Un cambio no-retrocompatible en su firma rompe decenas de imports a la vez. → **Mitigación:** en Tarea B los cambios son **aditivos** (props nuevas opcionales, defaults que preservan el render actual). Nunca renombrar/quitar props existentes.
2. **`tsc -b` (build prod) es más estricto que `tsc --noEmit`.** Imports sin usar, variables sin usar, props sobrantes → **rompen el deploy** (precedente: commit `740b879` "corregir 2 errores de build que bloqueaban el deploy"). → **Mitigación:** `npm run build` obligatorio antes de cada commit; al reordenar `NAV_ITEMS` en Tarea A, eliminar de la lista de imports lucide cualquier ícono que quede sin usar y añadir los que falten.
3. **Íconos:** el legacy usa Phosphor (`ph-*`); el refactor usa lucide. No hay equivalencia 1:1. → mapear por **intención** (Dashboard, tendencia, banco, wallet, etc.), no por nombre. Documentado en §1.1.
4. **Migrar KPI local → `StatCard`** puede alterar sutilmente el layout de tarjetas con lógica especial (p.ej. `KpiCardDiferencia` con ícono/flechas y colores por signo en `DashboardPage.tsx:73`). → mantener esas variantes como wrappers finos sobre `StatCard`, no forzar todo al caso base.
5. **`comisiones_empresa.php` (Comisiones Empresa)** no tiene ruta refactor equivalente clara. → NO inventar ruta en el re-skin; si el contenido está fusionado en `/comisiones`, omitir el ítem del sidebar y anotarlo. Es un gap funcional, no de layout — fuera de alcance de este plan.
6. **Rol `tienda` label de 1ª sección ("Mi Panel")** con un único array `NAV_ITEMS`: si no se resuelve por helper, la sección se verá "Gerencia" para tienda. → resolver en Tarea A con label de sección derivado por rol; si es costoso, aceptar divergencia menor y anotarla.
7. **Ancho acotado (`max-w-[1200px]`)** aplicado global rompería el dashboard ancho. → decidir por página (C/D/E), no en el shell.

### Verificación (checklist por tarea)
- [ ] `cd frontend && npm run build` termina sin errores (`tsc -b` + vite).
- [ ] `npm run lint` sin errores nuevos.
- [ ] Login **admin**: sidebar con orden/secciones legacy (§1.1); todas las páginas cargan.
- [ ] Login **tienda**: sidebar con secciones §1.2; sin ítems admin.
- [ ] Modo oscuro y claro: sin texto invisible ni contraste roto.
- [ ] Ninguna ruta rota (navegar cada ítem del sidebar).
- [ ] No se agregaron/quitaron rutas ni permisos `roles` (salvo lo especificado).

---

## 6. Resumen de archivos clave (referencia rápida)

**Legacy (`E:\laragon\www\sis_bipay`):**
- `includes/header.php:270-762` — sidebar completo (secciones, ítems, íconos, pie, campanitas).
- `includes/estilos.css:42-89` — `.sidebar`, `.sidebar-link`, `.main-content`; `:130-147` títulos de sección; `:869-936` acentos y badges.
- `gerencia/panel_gerencia.php:248-467` — patrón dashboard (header, filtros, grilla KPI, tabla).
- `gerencia/panel_financieras.php:112-212` — header + KPI `border-top` + filtros + tabla con `border-top` de acento.
- `gerencia/gestionar_agentes.php:306-425` — header con acciones + panel con cabecera de sección + tabla.

**Refactor (`C:\xampp\htdocs\bitel-p0-5\frontend/src`):**
- `components/AppLayout.tsx:29-76` — `NAV_ITEMS` (Tarea A).
- `components/PageHeader.tsx` — Tarea B (§2.1).
- `components/ListToolbar.tsx` — Tarea B (§2.3).
- `components/DataTable.tsx` — referencia patrón tabla (§2.4).
- `components/ui/SectionPanel.tsx` — contenedor con cabecera de sección (§2.4).
- `pages/DashboardPage.tsx:51-71` — `KpiCard` local a extraer (Tarea B → `ui/StatCard.tsx`).
- `App.tsx:69-129` — mapa de rutas (referencia, NO tocar).
