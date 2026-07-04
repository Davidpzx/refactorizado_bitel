# Plan Maestro — Reskin2: Fidelidad visual del refactor React a sis_bipay (legacy)

- **Fecha:** 2026-07-04
- **Rama:** `reskin2-arquitectura` (worktree `C:\xampp\htdocs\bitel-p0-5`)
- **Objetivo:** que el refactor **se vea igual** que el legacy `sis_bipay`. NO cambiamos funcionalidad ni rutas; solo estructura visual y estilos, de forma **aditiva y retrocompatible**.
- **Fuente de verdad:** las 20 capturas en
  `C:\xampp\htdocs\refactorizado_bitel\.superpowers\sdd\capturas\`
  (`legacy-<pagina>.png` = objetivo; `refactor-<pagina>.png` = estado actual).
- **Regla de oro para TODA tarea:** ejecutar `npm run build` en `frontend/` antes de dar por hecho. El build es `tsc -b` estricto: un import sin usar, un prop faltante o un tipo mal rompen el deploy Dokploy. Cambios **aditivos** — no borrar props ni renombrar exports que otras páginas usan.

---

## 0. Estado del design system (lo que YA existe — reutilízalo, no reinventes)

El refactor ya tiene una librería de primitivas sólida en `frontend/src/components/` y `frontend/src/components/ui/`. **Casi todos los patrones del legacy ya existen como componente**; el trabajo es (a) enriquecer el shell, (b) **aplicar consistentemente** las primitivas donde las páginas aún usan markup plano, y (c) afinar detalles (acentos de color, pills, banners).

| Primitiva | Archivo | Ya soporta |
|-----------|---------|------------|
| `StatCard` | `ui/StatCard.tsx` | acento `left`/`top` (4px), label uppercase, valor grande, `icon`, `valueColorClass`, `formatMoney` |
| `PageHeader` | `PageHeader.tsx` | `Icon` con caja de glow, `title` Orbitron, `subtitle`, `actions` (slot derecho) |
| `DataTable` | `DataTable.tsx` | headers uppercase, hairline dorado superior, hover dorado, borde-izq dorado en 1ª celda, paginación |
| `SectionPanel` | `ui/SectionPanel.tsx` | header de sección con `accent` (borde+fondo tintado), `icon`, `count` badge, `subtotal` |
| `SegmentedToggle` | `ui/SegmentedToggle.tsx` | píldoras de filtro (Todos/Disponible/…) |
| `PageTabs` | `ui/PageTabs.tsx` | tabs (Resumen/Por Tienda/…) |
| `GlassPanel` / `MoneyTotal` / `ActionIconButton` / `AddRowButton` / `badge` / `button` / `input` / `select` | `ui/*` | paneles, totales, botones de icono, badges, etc. |

**Diagnóstico de consistencia (clave):** algunas páginas ya usan el patrón correcto (Estadísticas, CRM, Asistencias ya tienen `StatCard align="top"` con acento de color), pero **Dashboard y Financieras usan tarjetas planas sin acento superior ni agrupación**. La meta del reskin es **unificar todas** al mismo patrón que ya se ve bien en Estadísticas.

---

## 1. SHELL COMPARTIDO — `AppLayout.tsx` (MÁXIMA PRIORIDAD, va primero)

**Archivo único:** `frontend/src/components/AppLayout.tsx`
**Capturas a mirar:** el sidebar es idéntico en TODAS las capturas `legacy-*.png`. Los mejores referentes: `legacy-dashboard.png`, `legacy-financieras.png`, `legacy-tiendas.png` (sidebar completo con pie). Comparar contra `refactor-dashboard.png`.

El sidebar del refactor ya acertó varias cosas (sección con label de borde dorado, ítem activo con borde-izq dorado + fondo, badges con contador). **Lo que falta o difiere:**

| # | Diferencia | Cómo se ve en legacy | Qué falta / difiere en refactor | Dónde tocar (AppLayout.tsx) |
|---|-----------|----------------------|--------------------------------|-----------------------------|
| S1 | **Logo con ícono + subtítulo** | Ícono dorado (figura de persona) en caja, título **"PRUEBA"** grande y subtítulo **"PRUEBA KYRO"** debajo | Solo texto "SIS-KYRO" en dorado; sin ícono, sin subtítulo | Bloque logo (líneas ~148-210): añadir un ícono lucide en caja dorada (`Users`/`UserCircle`) + segunda línea `text-[10px]` muted con el subtítulo. El nombre puede seguir siendo dinámico. |
| S2 | **Label de la 1ª sección ("GERENCIA")** | La sección "GERENCIA" se rotula ANTES de Dashboard (primer ítem) | El separador solo se dibuja cuando `idx !== 0` (línea 215), así que la **primera sección nunca muestra label**. "GERENCIA" no aparece. | En el `.map` del nav (línea 214-284) cambiar la condición de `showSeparator` para que también muestre el label cuando `idx === 0`. Aditivo. |
| S3 | **Tarjeta de usuario rica al pie** | Avatar circular con inicial, nombre, rol ("Admin"), **toggle de tema in-situ (luna / switch dorado / sol)** y **badge "CENTRAL"** (tienda_id) | Solo avatar + nombre + rol + "Cerrar sesión". El theme toggle y la campana están arriba; falta el badge CENTRAL y el toggle dentro de la tarjeta | Bloque "Usuario" (líneas 287-322). Añadir badge tienda/CENTRAL bajo el rol y un theme toggle segmentado (reusar `toggleTheme` de `useTheme`, ya importado). |
| S4 | **Botón "Notificaciones (20)"** | Botón ancho dorado/oscuro con ícono campana + contador rojo "20" | No existe como botón en el pie (la campana está arriba, minimal) | Añadir en el pie, sobre "Cerrar sesión". Usar `notifCount`/`ccAlertCount` (ya calculados, líneas 110-111) y abrir `ControlCenterPanel` (`setCcOpen`). |
| S5 | **Botón "Aprobar Traslados"** | Botón ancho con degradado azul/cian + ícono | No existe | Añadir en el pie. Enlazar a `/traslados` (ya en NAV_ITEMS). Mostrar solo si admin. |
| S6 | **"Cerrar Sesión" con color** | Botón rojo tenue (texto/borde rojo) | Texto gris neutro | Reestilizar `logoutCls` a rojo tenue (`text-red-400 hover:bg-red-500/10`). |

**Notas de implementación del shell:**
- Mantener el modo claro/oscuro: cada estilo nuevo necesita su variante `isDark`. Ya existe el patrón (ver `navActive`, `logoutCls`, etc.).
- El collapse chevron y la campana superior del refactor **se conservan** (son mejoras aditivas); no romperlas.
- No tocar `NAV_ITEMS` ni la lógica de roles/badges — solo el render del logo, el label de sección y el bloque del pie.
- **Verificar:** `npm run build` + abrir dashboard en dark y light; el pie no debe desbordar cuando `collapsed`.

---

## 2. PATRONES COMPARTIDOS (segunda prioridad — habilitan las páginas)

Estos son ajustes a **primitivas** o **nuevos micro-componentes** que luego las páginas consumen. Hacerlos antes que las páginas.

### P1 — `StatCard` con acento superior + ícono, como estándar
`StatCard` ya soporta `align="top"` y `icon`. **No requiere cambio de código**, solo **convención**: en las páginas se usa `align="top"` con `accent` por tipo e `icon`. Documentar la paleta de acentos por tipo (usarla en todas las páginas):

| Tipo de KPI | `accent` | Ícono lucide sugerido |
|-------------|----------|------------------------|
| General / total | `#3b82f6` (azul) | `Wallet` |
| Físico esperado / OK | `#10b981` (verde) | `CheckCircle` |
| Declarado / neutro | `#a855f7` (púrpura) | `FileText` |
| Diferencia / gris | `#71717a` (gris) | `TrendingUp` |
| Yape | `#a855f7` (púrpura) | `Zap` |
| Bipay | `#3b82f6` (azul) | `CreditCard` |
| Transferencia | `#14b8a6` (teal) | `Landmark` |
| Alerta / pendiente | `#ffc200` (dorado) | `AlertTriangle` |

> Opcional (mejora menor): añadir a `StatCard` un prop `subtitle?: ReactNode` para la línea gris bajo el valor (legacy la usa: "Desembolsos aprobados", "0 ventas — 2026-07"). Aditivo y opcional; no rompe llamadas existentes.

### P2 — Grupo "DINERO DIGITAL DEL PERÍODO" (nuevo componente)
**Nuevo:** `frontend/src/components/ui/MoneyGroup.tsx` (o inline en Dashboard).
Legacy (`legacy-dashboard.png`): un rótulo dorado pequeño con ícono **"DINERO DIGITAL DEL PERÍODO"**, seguido de 3 `StatCard` con **borde-izq de color + ícono en caja redondeada** (Yape púrpura, Bipay azul, Transferencia teal). Es un `<section>` con `<h3>` dorado + grid de 3 StatCards `align="left"` con icon. Reutiliza `StatCard`; el único elemento nuevo es el encabezado de grupo dorado.

### P3 — Banner "GANANCIA TOTAL DEL PERÍODO" (nuevo componente)
**Nuevo:** `frontend/src/components/ui/ProfitBanner.tsx`.
Legacy: banner ancho (full-width) con **borde-izq verde**, ícono, título "GANANCIA TOTAL DEL PERIODO", subtítulo descriptivo a la izquierda y **valor grande verde a la derecha**. Es un panel flex `justify-between`. Props: `title`, `subtitle`, `value`, `accent='#10b981'`.

### P4 — Barra de filtros estilo legacy (patrón, no siempre componente)
Legacy (dashboard/financieras/productividad/asistencias): fila horizontal con **labels UPPERCASE** encima de cada input (`DESDE` / `HASTA` / `TIENDA` / `ESTADO`…), inputs con **ícono** (calendario en fechas, lupa en búsqueda), y a la derecha botón **"FILTRAR" dorado con lupa** + acciones (recargar circular, "Exportar" verde con ícono xls).
Diferencia con refactor: el refactor usa labels en Title Case y a veces inputs apilados verticales (financieras). **Estándar a aplicar:** labels uppercase `text-[0.68rem] tracking-wide text-muted`, layout en grid horizontal, botón Filtrar dorado. Considerar extraer `frontend/src/components/ui/FilterBar.tsx` si se repite mucho, pero es opcional; puede quedar como convención de markup por página.

### P5 — Estilo de tabla legacy (ya en `DataTable`, afinar celdas)
`DataTable` ya trae headers uppercase + hairline dorado. Lo que las páginas deben aportar en las **celdas**:
- **Chips de tienda** (pills de color por tienda) — legacy `legacy-agentes.png`.
- **Badges de estado**: `ACTIVO`/`Activa` verde, `VENDIDO` rojo, `DISPONIBLE` verde, `CUADRADO` verde, `Sin GPS` gris/rojo, `GPS activo` verde. Reusar `ui/badge.tsx` con variantes de color.
- **Pills de rol**: `ADMIN` dorado, `TIENDA` cian (legacy usuarios) — hoy el refactor los pinta rosa; cambiarlos.
- **Códigos como link cian** (legacy tiendas: CÓDIGO en cian).
- **Estado vacío con ícono** (lupa/ícono + texto), como legacy, en vez del texto plano actual.

### P6 — Banners de alerta con color
Legacy (`legacy-inventario.png`, `legacy-asistencias.png`): banners de alerta **rojos/degradados** con ícono (Stock estancado, Monitor de Fraude). El refactor los pinta gris plano. **Estándar:** banner con `border` + fondo tintado del color semántico (rojo alerta, dorado warning). Micro-componente opcional `ui/AlertBanner.tsx` con prop `tone: 'danger'|'warning'|'info'`.

---

## 3. PÁGINAS — diferencias por captura

> Para cada página el implementador debe **abrir el par `legacy-<x>.png` + `refactor-<x>.png`** y replicar. Abajo el detalle observado.

### 3.1 Dashboard — `frontend/src/pages/DashboardPage.tsx`
Capturas: `legacy-dashboard.png` ↔ `refactor-dashboard.png`

| Diferencia | En legacy | Falta en refactor | Archivo |
|-----------|-----------|-------------------|---------|
| Acciones de cabecera | Botones: campana roja, **"Tiendas"** (outline dorado c/ícono), **"Usuarios"** (outline c/ícono), **"Registrar Cuadre"** (índigo c/ícono) | Solo "Exportar Excel" + "Anomalías" | `DashboardPage.tsx` header `actions` |
| KPI superiores | 4 cards con **borde superior de color** (azul/verde/púrpura/gris) e ícono | Cards con top-border pero mezcladas con las de dinero en 2 filas uniformes | usar P1 (StatCard `align="top"` + accent + icon) |
| Dinero digital | Rótulo dorado **"DINERO DIGITAL DEL PERÍODO"** + 3 cards (Yape/Bipay/Transferencia) con **borde-izq + ícono en caja** | Yape/Bipay/Transferencia son cards planas en la 2ª fila, sin rótulo ni agrupación | usar **P2** (MoneyGroup) |
| Ganancia | **Banner ancho verde** con subtítulo + valor grande a la derecha | Es una card pequeña más | usar **P3** (ProfitBanner) |
| Barra de filtros | Labels UPPERCASE, botón **FILTRAR dorado con lupa**, botón recargar circular, **Exportar verde** dentro de la barra | Labels Title Case, "Filtrar" + "Hoy"; Exportar está arriba | usar **P4** |
| Tabla vacía | Ícono de lupa + "No se encontraron reportes con estos filtros" | "Sin reportes…" sin ícono | P5 estado vacío |

### 3.2 Financieras — `frontend/src/pages/admin/PanelFinancierasPage.tsx`
Capturas: `legacy-financieras.png` ↔ `refactor-financieras.png`

| Diferencia | En legacy | Falta en refactor | Archivo |
|-----------|-----------|-------------------|---------|
| 3 KPI cards | **Borde superior de color**: PENDIENTE (dorado), CONFIRMADO (verde), TOTAL FACTURADO (índigo), cada una con **subtítulo** ("Desembolsos aprobados", "Inicial + Saldo financieras") | Cards planas sin borde de color; TOTAL en blanco; subtítulos distintos/ausentes | P1 (StatCard `align="top"` + accent) + P1-subtitle |
| Barra de filtros | Fila **horizontal** con labels UPPERCASE (MES/ESTADO/FINANCIERA/TIENDA) + Filtrar (outline teal c/embudo) | Inputs **apilados verticales** full-width + segmented Todos/Pendiente/Aprobada | usar **P4** (pasar a fila horizontal con labels) |
| Tabla | Header uppercase, **hairline índigo superior**, columnas MODELO/IMEI, INICIAL (TIENDA), SALDO FINANCIERA; **estado vacío con ícono** | menos columnas / sin hairline índigo / vacío sin ícono | `DataTable` + P5 |

### 3.3 Bipay / Anypay — `frontend/src/pages/bipay/PanelBipayPage.tsx`
Capturas: `legacy-bipay.png` ↔ `refactor-bipay.png`
**Nota de alcance:** el legacy es una página larga multi-sección (cuenta MADRE, Control Diario BiPay vs ERP, Auditoría de Cierre, Historial de Transacciones) mientras el refactor es **tabbed** (Saldos/Transacciones/Recarga/…). La reestructuración funcional total está **fuera del reskin puro**; aquí solo alineamos **estilo visual**. El KPI header del refactor (3 cards con borde-izq + ícono, valores monoespaciados) ya se ve bien.

| Diferencia (solo visual) | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Acciones de cabecera | **"Alertas Webhook"** (rojo), **"Nueva Cuenta"** (índigo), **"Recargar Cuenta"** (verde) | añadir/estilar botones de color equivalentes en `actions` | `PanelBipayPage.tsx` |
| Encabezados de sección | Cada bloque tiene barra de sección con **acento de color** (MADRE dorado, Declaraciones cian, Control neutro, Auditoría rojo, Historial púrpura) | usar `SectionPanel accent=…` para las secciones/tabs | `SectionPanel` |
| Badges de estado en tabla | **CUADRADO** verde por fila | badge verde | P5 |
| Estado vacío | — | "Sin cuentas registradas" con ícono | P5 |

### 3.4 Agentes / Personal — `frontend/src/pages/agentes/AgentesPage.tsx`
Capturas: `legacy-agentes.png` ↔ `refactor-agentes.png`
**Nota:** el legacy ("Administración de Personal") es **mucho más denso** (form inline de alta, columnas de jornada/refrigerio/PIN). El refactor ("Agentes") es una lista limpia. No portamos toda la densidad; alineamos **estilo de tabla y badges**.

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Chip de tienda | Pill **de color** por tienda (púrpura) junto al agente | pintar la columna TIENDA como chip de color, no texto plano | `AgentesPage.tsx` + P5 chip tienda |
| Estado bajo el nombre | Badge **ACTIVO** verde bajo el nombre | ya hay badge ACTIVO verde en columna Estado — mantener/alinear color | P5 badge |
| PIN | Pill **dorado** con el PIN | (si se muestra PIN) pill dorado | P5 |
| Botones de cabecera | "URL Registro de Datos"/"URL Asistencia" dorados; el refactor tiene "Ficha técnica"/"Nuevo agente" (dorado) — OK | mantener dorado | — |

### 3.5 Productividad / Estadísticas — `frontend/src/pages/estadisticas/EstadisticasPage.tsx`
Capturas: `legacy-estadisticas.png` (= "Productividad por Tienda") ↔ `refactor-estadisticas.png` (= "Estadísticas de Ventas")
**Nota:** son páginas distintas, pero el refactor **ya usa el patrón correcto**: KPI cards con **borde superior de color** (Total azul, Postpago azul, Prepago púrpura, Cuotas naranja, Contado amarillo, Accesorios verde), tabs dorados, y charts. Esta página es el **referente de estilo** que las demás deben imitar.

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Tabla de ranking | Headers de columna **coloreados por categoría** (Postpago azul, Chip púrpura, Cuotas naranja, Contado amarillo, Accesorios verde) y valores del mismo color; TOTAL en **pill** | si/cuando se muestre la tabla de ranking por tienda, colorear headers y el total | `EstadisticasPage.tsx` (tab Por Tienda) |
| Estado vacío | ícono + texto | P5 | — |

> Esta página es de **baja prioridad**: ya cumple el 80%. Úsala como muestra de referencia para P1.

### 3.6 Inventario — `frontend/src/pages/inventario/InventarioPage.tsx`
Capturas: `legacy-inventario.png` ↔ `refactor-inventario.png`
**Nota:** el legacy es extremadamente denso (KPIs de capital, chips Bitel con steppers, equipos en tránsito, precios editables). No portamos toda la funcionalidad; alineamos **estilo**.

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Banners de alerta | **Rojos/degradados** con ícono (Stock estancado, ventas sin costo) | los del refactor son gris plano → pasarlos a **P6** (danger/warning con color) | `InventarioPage.tsx` |
| KPI de capital | Cards superiores CAPITAL EQUIPOS / ACCESORIOS / CHIPS | (opcional) añadir fila de KPI con P1 | `InventarioPage.tsx` |
| Pills TIPO/ESTADO | TIPO (EQUIPO azul / ACCESORIO gris) y ESTADO (DISPONIBLE verde / VENDIDO rojo) | ya existen; alinear tonos a P5 | P5 |
| Tabs | Todos/Equipos/Accesorios/Chips (índigo activo) — el refactor ya los tiene | mantener; considerar acento dorado si se busca consistencia total | `PageTabs` |

### 3.7 Tiendas — `frontend/src/pages/admin/TiendasPage.tsx`
Capturas: `legacy-tiendas.png` ↔ `refactor-tiendas.png`

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Código como link cian | CÓDIGO en **cian** (link) | hoy texto blanco → pintar cian | `TiendasPage.tsx` + P5 |
| GPS badge | **"Activo"** verde con pin / **"Sin GPS"** gris | el refactor usa "GPS activo" verde / "Sin GPS" rojo — alinear tono (Sin GPS gris, no rojo agresivo) | P5 badge |
| Layout | Legacy: 2 columnas (form NUEVA SEDE con **acento cian superior** + tabla) | El refactor usa "Nueva tienda" (modal) + tabla full-width — **se conserva** (patrón moderno consistente). No portar el form inline. | — |

### 3.8 Usuarios — `frontend/src/pages/admin/UsuariosPage.tsx`
Capturas: `legacy-usuarios.png` ↔ `refactor-usuarios.png`

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Pills de rol | **ADMIN dorado**, **TIENDA cian** | el refactor los pinta **rosa/rose** → cambiar a dorado/cian | `UsuariosPage.tsx` + P5 |
| Pill BCP | **"BCP" cian** con ícono | ya hay columna BCP — pintar pill cian | P5 |
| Código Bitel | pill gris con el código | alinear a pill gris | P5 |
| Layout | Legacy 2 columnas (form "Registrar Nuevo Agente" índigo + tabla) | refactor: "Nuevo usuario" (modal) + tabla full-width — **se conserva** | — |

### 3.9 Asistencias — `frontend/src/pages/asistencias/AsistenciasPage.tsx`
Capturas: `legacy-asistencias.png` ↔ `refactor-asistencias.png`
**Nota:** el refactor ya tiene 4 KPI cards con acento superior (Presentes verde, Ausentes rojo, Tardanzas dorado, Pend. Revisión azul) — bien.

| Diferencia | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| Botones de acción | Fila colorida: **Descargar PDF** (rojo), **Exportar Excel** (verde), **Fotos** (dorado + badge 9), **Registrar Falta/Permiso** (cian), **Asistencia Manual** (índigo), **Control** (dorado outline) | el refactor tiene botones mayormente outline neutros → **dar color** a cada acción | `AsistenciasPage.tsx` header `actions` |
| Barra de filtros | acento cian superior + labels; Aplicar Filtros dorado | alinear (P4) | P4 |
| Monitor de Fraude | Sección con **banner rojo** + badge "50 alertas" + tabla con badges "DIFERENTE" rojos | (si existe la data) añadir como `SectionPanel accent='#ef4444'` + P6 | P6 + SectionPanel |

### 3.10 CRM — `frontend/src/pages/crm/CrmPage.tsx`
Capturas: `legacy-crm.png` ↔ `refactor-crm.png`
**Nota de alcance:** son **conceptos distintos** — legacy es "CRM y Marketing" (captación: KPIs + donut + barras + tendencia + registro completo), refactor es "CRM – Pipeline de Leads" (Kanban por estado). Rehacer el legacy completo está **fuera del reskin**. El refactor **ya usa** KPI cards con acento superior de color, lo cual es correcto.

| Diferencia (solo visual/aditiva) | En legacy | Aplicar en refactor | Archivo |
|-----------|-----------|---------------------|---------|
| KPI cards con ícono | 4 cards con **ícono en caja** + borde de color | el refactor tiene 6 cards con borde de color pero **sin ícono** → añadir `icon` a los StatCards | `CrmPage.tsx` (P1) |
| Charts | donut + barras horizontales + área dorada | si/cuando el tab Analytics muestre charts, usar la paleta dorada/cian | `CrmPage.tsx` (tab Analytics) |
| Columnas Kanban | (el refactor ya usa borde de color por columna: Nuevo azul, Contactado dorado, Interesado índigo, Convertido verde, Perdido rojo) — **se conserva** | mantener | — |

---

## 4. DESCOMPOSICIÓN EN TAREAS (paralelizable por área, en orden de dependencia)

> Cada tarea la ejecuta otra cuenta que **también lee las capturas**. Toda tarea termina con `npm run build` verde en `frontend/`. Cambios aditivos.

### Ola 0 — Shell (bloquea todo lo visual compartido; hacer primero, secuencial)
- **T0 — Rediseño del sidebar (AppLayout).**
  Archivo: `frontend/src/components/AppLayout.tsx`.
  Capturas: `legacy-dashboard.png`, `legacy-financieras.png`, `legacy-tiendas.png` (sidebar+pie) vs `refactor-dashboard.png`.
  Implementar S1–S6 (logo+ícono+subtítulo, label de 1ª sección, tarjeta de usuario rica con theme toggle + badge CENTRAL, botones Notificaciones/Aprobar Traslados/Cerrar Sesión con color). Mantener dark/light. No tocar `NAV_ITEMS`.

### Ola 1 — Patrones compartidos (bloquean las páginas; pueden ir en paralelo entre sí)
- **T1 — Micro-componentes de patrón.**
  Archivos nuevos: `ui/MoneyGroup.tsx` (P2), `ui/ProfitBanner.tsx` (P3), `ui/AlertBanner.tsx` (P6). Prop opcional `subtitle` en `ui/StatCard.tsx` (P1, aditivo).
  Capturas: `legacy-dashboard.png` (MoneyGroup, ProfitBanner), `legacy-inventario.png` (AlertBanner).
- **T2 — Paleta de badges/pills + estado vacío con ícono.**
  Archivo: `ui/badge.tsx` (añadir variantes de color: verde/rojo/dorado/cian/gris) y un helper de estado vacío (puede vivir en `DataTable.tsx` como prop `emptyIcon`/`emptyLabel`, aditivo). (P5)
  Capturas: `legacy-tiendas.png`, `legacy-usuarios.png`, `legacy-inventario.png`.

### Ola 2 — Páginas (paralelizables entre grupos una vez lista Ola 1)
Agrupadas para repartir a varias cuentas. Cada grupo cita sus capturas.

- **T3 — Grupo Gerencia/Dinero:** Dashboard (`DashboardPage.tsx`) + Financieras (`admin/PanelFinancierasPage.tsx`).
  Usa T0, T1 (MoneyGroup/ProfitBanner/subtitle), T2. Capturas: `legacy-dashboard.png`, `legacy-financieras.png`.
- **T4 — Grupo Catálogos/Tablas:** Tiendas (`admin/TiendasPage.tsx`) + Usuarios (`admin/UsuariosPage.tsx`) + Agentes (`agentes/AgentesPage.tsx`).
  Usa T2 (pills rol dorado/cian, código cian, chip tienda, GPS badge). Capturas: `legacy-tiendas.png`, `legacy-usuarios.png`, `legacy-agentes.png`.
- **T5 — Grupo Operaciones:** Asistencias (`asistencias/AsistenciasPage.tsx`) + Inventario (`inventario/InventarioPage.tsx`).
  Usa T1 (AlertBanner), T2. Botones de acción con color + banners de alerta rojos. Capturas: `legacy-asistencias.png`, `legacy-inventario.png`.
- **T6 — Grupo Paneles complejos (solo estilo, sin reestructurar):** Bipay (`bipay/PanelBipayPage.tsx`) + CRM (`crm/CrmPage.tsx`) + Estadísticas (`estadisticas/EstadisticasPage.tsx`).
  Usa T1/T2 + `SectionPanel`/`PageTabs`. Botones de acción con color, íconos en KPI, acentos de sección. Baja prioridad (ya cerca del objetivo). Capturas: `legacy-bipay.png`, `legacy-crm.png`, `legacy-estadisticas.png`.

**Dependencias:** T0 → (T1, T2) → (T3, T4, T5, T6). T1 y T2 en paralelo. T3–T6 en paralelo entre sí una vez cerradas T1/T2. T4 solo depende de T2 (puede empezar antes que T1).

**Checklist por tarea:** (1) abrir el par de capturas; (2) cambios aditivos; (3) dark + light; (4) `npm run build` verde; (5) no romper imports/props existentes.

---

## 5. Riesgos / notas
- **tsc estricto:** cualquier import sin usar o prop mal tipada rompe el deploy. Ejecutar build siempre.
- **No romper retrocompat:** `StatCard`, `PageHeader`, `DataTable`, `badge` los consumen muchas páginas; solo **añadir** props/variantes, nunca cambiar firmas existentes.
- **Alcance:** Bipay, CRM y Agentes/Personal legacy son funcionalmente más densos; este plan alinea **estilo**, no reconstruye su funcionalidad.
- **Modo claro:** el legacy captura modo oscuro; todo cambio debe tener su variante clara (el shell y las primitivas ya siguen ese patrón).
