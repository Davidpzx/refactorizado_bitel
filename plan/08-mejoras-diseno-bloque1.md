# 08 — Mejoras de diseño POR ENCIMA del legacy — BLOQUE 1: núcleo operativo

**Fase:** planes de mejora (solo escritura de planes, cero código). **Autor:** titan (Fable, razonamiento bajo).
**Nota de entorno:** la skill `headroom` NO existe en este entorno (mismo hallazgo que `00-inventario-diseno.md` y tickets 017/041) — se planificó sin ella, cuidando tokens.

**Premisa:** la paridad con el legacy YA está lograda (QA consolidado `04-qa-visual.md`: 25 fiel / 9 mejorada / 5 parcial / 0 degradada; auditoría dura `06-paridad-produccion.md` post-feedback del usuario). Este plan NO persigue paridad: define cómo **superar** al legacy conservando la identidad **Ultra Dark Premium** — dorado `#ffc200` como acento maestro, glass (`backdrop-filter: blur(20px)`), acentos de color por sección (púrpura CRM, cian info, verde éxito), nunca genérico tipo "admin template".

**Alcance Bloque 1 (núcleo operativo, 10 pantallas):** Dashboard · Nuevo Reporte/cuadre · Historial · Mi Historial · Asistencias-Gestión · Asistencias-Control mensual · Asistencias-Liquidación · Revisar Fotos · Planilla · Terminal Asistencia.
El resto de pantallas → `plan/08-mejoras-diseno-bloque2.md` (dev3).

**Regla de ejecución:** los tickets son de **una pasada** (autocontenidos, verificables con `tsc -b` + `npm run build` + suite backend cuando toquen API). Ejecutores: **Sonnet 5** (mecánico/UI) u **Opus 4.8** (pantallas con dinero o interacción compleja). **Nunca Fable.** El ticket transversal **DIS-B1-00 va PRIMERO** — todos los demás consumen sus tokens.

---

## 0. Transversal — Design System v2 (ticket DIS-B1-00, prerequisito)

### 0.1 Qué elevar
El sistema actual (`frontend/src/index.css` + `components/ui/*`) es sólido en color y radio pero le faltan: **tokens de movimiento**, **skeletons** (hoy el estado de carga es `···` con `animate-pulse` en `StatCard.tsx:48`), **estado vacío estandarizado**, **números tabulares** para dinero, y **`prefers-reduced-motion`** (accesibilidad). El modo claro existe (ticket-019) pero las sombras/elevación en claro son más pobres que en oscuro.

### 0.2 Propuesta concreta (valores exactos)

**a) Tokens de movimiento** — añadir al bloque `@theme` de `index.css`:
```css
--motion-fast:   150ms;   /* hovers, toggles */
--motion-base:   220ms;   /* transiciones de panel, fades */
--motion-slow:   360ms;   /* entradas de página, modales */
--ease-premium:  cubic-bezier(0.22, 1, 0.36, 1);  /* ease-out-quint suavizado */
--ease-spring:   cubic-bezier(0.34, 1.56, 0.64, 1); /* micro-rebote para confirmaciones */
```
Y el guard de accesibilidad global:
```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; }
}
```

**b) Skeleton shimmer** — nueva animación + clase utilitaria (reemplaza el `···` como estado de carga en tablas y cards):
```css
@keyframes kyro-shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
.kyro-skeleton {
  border-radius: 6px;
  background: linear-gradient(90deg, rgba(148,163,184,0.12) 25%, rgba(148,163,184,0.24) 50%, rgba(148,163,184,0.12) 75%);
  background-size: 200% 100%;
  animation: kyro-shimmer 1.6s ease-in-out infinite;
}
html.dark .kyro-skeleton {
  background: linear-gradient(90deg, rgba(255,255,255,0.05) 25%, rgba(255,255,255,0.10) 50%, rgba(255,255,255,0.05) 75%);
  background-size: 200% 100%;
}
```
Componente nuevo `frontend/src/components/ui/Skeleton.tsx` (props: `w`, `h`, `rounded`) + `SkeletonRow` para tablas (n celdas). `StatCard` gana prop `loading` que pinta `<Skeleton h={28} w="60%">` en lugar de `···` (mantener `···` como fallback para no romper llamadas existentes).

**c) Estado vacío estandarizado** — componente `frontend/src/components/ui/EmptyState.tsx`:
- Ícono Phosphor `duotone` 48px en `--color-kyro-gold` al 40% de opacidad, título `text-sm font-semibold`, descripción `text-[0.8rem] text-kyro-muted`, CTA opcional (`Button variant="gold" size="sm"`).
- Contenedor: `py-12 px-6 text-center`, borde `1px dashed rgba(255,194,0,0.25)` (dorado punteado = identidad, no el gris genérico), `border-radius: var(--radius-kyro-lg)` (12px).
- Nunca texto plano "No hay datos" suelto en una celda.

**d) Números de dinero** — clase utilitaria:
```css
.kyro-money { font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
```
Aplicar en TODAS las columnas monetarias y KPIs (hoy los montos "bailan" al cambiar de dígitos). `MoneyTotal.tsx`, `StatCard.tsx` y las columnas S/ de tablas la adoptan.

**e) Tipografía — jerarquía de 2 fuentes con criterio:**
- **Orbitron** (ya cargada, `--font-orbitron`): SOLO para (1) valor numérico del hero-KPI del Dashboard, (2) reloj del Terminal de Asistencia, (3) diferencia del cuadre en la barra sticky. En ningún otro lado — es la firma "premium tech" y se degrada si se abusa.
- **Inter**: todo lo demás. Escala fija: título de página `text-xl/28px font-bold tracking-tight`, título de sección `text-sm font-semibold uppercase tracking-[0.08em]` (ya es el patrón de `StatCard`), cuerpo `14px` (ya en `body`), micro `text-[0.68rem]` para heads de tabla (ya en `.kyro-table-head`).

**f) Elevación en modo claro** — hoy `.kyro-card` claro tiene una sola sombra; añadir hover coherente en ambos temas:
```css
.kyro-card { transition: box-shadow var(--motion-base) var(--ease-premium), transform var(--motion-base) var(--ease-premium); }
.kyro-card:hover { box-shadow: 0 24px 55px -30px rgba(15, 23, 42, 0.45); }
html.dark .kyro-card:hover { box-shadow: 0 8px 30px -4px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 194, 0, 0.06); }
```
(el `0 0 0 1px` dorado al 6% es el "glow" sutil de identidad en dark; imperceptible pero presente).

**g) Scroll-shadows para tablas anchas** — clase `.kyro-scroll-x`: contenedor `overflow-x-auto` con pseudo-elementos degradados de 24px (`linear-gradient(90deg, rgba(9,9,11,0.9), transparent)` en dark / `rgba(248,250,252,0.9)` en claro) visibles solo cuando hay overflow (JS: hook `useScrollShadows` de ~30 líneas). Consumida por Historial, Planilla, Control mensual.

**h) Dark/light** — auditoría de contraste puntual: `--color-kyro-subtle` claro (`#94a3b8` sobre `#f8fafc`) es 2.9:1 — **solo decorativo**, prohibirlo para texto informativo (usar `--color-kyro-muted` `#64748b`, 4.6:1). En dark, subir `--color-kyro-subtle` de `#52525b` a `#71717a` cuando se use como placeholder de input (hoy roza 3:1 sobre `#09090b`).

**i) Feedback estándar** — regla de sistema: toda acción asíncrona disparada por botón muestra spinner inline en el botón (`Button` gana prop `loading` → ícono `CircleNotch` Phosphor con `animate-spin`, deshabilita, conserva ancho con `min-width` capturado). Elimina el patrón "clic y a esperar sin señal" en exports/aprobaciones.

### 0.3 Esfuerzo y ejecutor
**M (½ día). Sonnet 5.** Sin backend. Verificación: `tsc -b` + `vite build` + revisión visual de 3 pantallas en ambos temas.

---

## 1. Dashboard — `frontend/src/pages/DashboardPage.tsx`

### 1.1 Qué elevar
- **Jerarquía:** hoy los KPIs son una parrilla plana homogénea; nada "manda". La Venta Total del período debe ser el héroe.
- **Feedback/estados:** carga con `···`; sin indicación de frescura de datos.
- **Microinteracciones:** cero — los números aparecen de golpe.
- **Densidad:** la tabla "Últimos Reportes" es correcta (ya con Ganancia admin, ticket-041) pero sin affordance de fila.

### 1.2 Propuesta concreta
1. **Hero-KPI:** la card "Venta Total" pasa a `col-span-2` con valor en **Orbitron `text-3xl/30px`**, borde superior `CardTopAccent` dorado, y un **delta vs período anterior** debajo: `▲ +12.4% vs ayer` en `#22c55e` / `▼` en `#ef4444`, `text-[0.72rem] font-semibold` (dato ya calculable con la misma query del KPI con rango desplazado; endpoint `DashboardController::kpis` gana campo `venta_total_prev`).
2. **Count-up:** los valores numéricos de los `StatCard` animan de 0 al valor real en **600ms** con `--ease-premium` (hook `useCountUp(value, 600)` con `requestAnimationFrame`; respeta `prefers-reduced-motion` devolviendo el valor final directo). Solo en el mount inicial, no en refetch.
3. **Sparkline en hero:** mini área Recharts (ya es dependencia) de los últimos 7 días de venta, 100% ancho × **44px** alto, línea `#ffc200` 1.5px, fill `rgba(255,194,0,0.10)`, sin ejes ni tooltip (decorativa, `aria-hidden`). Datos: endpoint nuevo liviano `GET /v1/dashboard/serie-7d` (7 filas, cacheable 5 min).
4. **Frescura:** bajo el título de página, línea `text-[0.7rem] text-kyro-muted`: `Actualizado hace 23 s · se refresca cada 60 s` con refetch automático de react-query `refetchInterval: 60_000` (verificar que no exista ya; si existe, solo exponer el texto).
5. **Tabla últimos reportes:** hover de fila `bg-white/[0.03]` dark / `bg-slate-50` claro con `transition var(--motion-fast)`; fila clicable → navega al detalle del reporte (`cursor-pointer` + `aria-label`); columna Ganancia con `.kyro-money` y color `#10b981`/`#ef4444` según signo.
6. **Carga:** primera carga con `SkeletonRow × 5` en la tabla y `Skeleton` en cada KPI (patrón 0.2b).
7. **Vacío:** si no hay reportes en el período → `EmptyState` ícono `ChartLineUp`, CTA `Registrar Cuadre` (`variant="gold"` — de paso resuelve la nota pendiente de 06: el CTA de cuadre en Dashboard pasa de índigo a **dorado**, decisión coherente con identidad; anotar en el ticket que si el usuario prefiere azul legacy se cambia solo esta variante).

### 1.3 Esfuerzo y ejecutor
**M (½–1 día). Sonnet 5** (el cambio backend es un `selectSub` + endpoint de 7 filas, bajo riesgo; test de feature para `serie-7d` y `venta_total_prev`).

---

## 2. Nuevo Reporte / Cuadre — `frontend/src/pages/reportes/NuevoReportePage.tsx` + `EditarReportePage.tsx` (+ `cuadre/*.tsx`)

Es la pantalla de referencia del port (fiel campo a campo, ticket-020). Mejorarla **sin tocar una sola fórmula** — todo lo de abajo es capa de presentación sobre estado ya existente.

### 2.1 Qué elevar
- **Feedback continuo:** el usuario llena decenas de campos y la "verdad" (¿cuadra o no?) vive al final de la página. El legacy tampoco lo resuelve — aquí está la mejora por encima.
- **Resiliencia:** el legacy tiene "Borrador en la Nube" (visto en la auditoría 06); el refactor debe igualar o superar con autosave local.
- **Jerarquía/navegación:** formulario largo multi-sección sin mapa.
- **Accesibilidad:** montos sin `aria-live`, cambios de total invisibles para lector de pantalla.

### 2.2 Propuesta concreta
1. **Barra sticky de cuadre (la mejora estrella):** barra inferior fija `position: sticky; bottom: 0`, alto **56px**, `.kyro-glass` con `border-top: 1px solid rgba(255,194,0,0.18)`, siempre visible mientras se edita. Contenido: `Esperado S/ X · Declarado S/ Y · Diferencia S/ Z` — la diferencia en **Orbitron `text-lg`** y con semáforo exacto: `|dif| ≤ 0.10` → `#22c55e` + ícono `CheckCircle`; `0.10 < |dif| ≤ 5.00` → `#f59e0b`; `> 5.00` → `#ef4444`. El contenedor de la diferencia lleva `aria-live="polite"`. Cuando la diferencia cruza a verde, un único pulso dorado: `box-shadow: 0 0 0 0 rgba(255,194,0,0.45)` → expandido a `12px` transparente en **700ms** `--ease-spring`, una sola vez (no loop — nada de confetti genérico).
2. **Riel de secciones:** columna derecha fija (solo `≥1280px`) con anclas a cada sección (Ventas Postpago/Prepago/Equipos, Caja Inicial, Dinero No Físico, Salidas de Efectivo…); ítem activo por `IntersectionObserver`, marcado con barra izquierda 2px `#ffc200` + texto `text-kyro-text`; inactivos `text-kyro-muted`. En `<1280px` no se renderiza (la barra sticky ya da el feedback esencial).
3. **Autosave de borrador:** cada **15 s** (debounced) serializar el estado del formulario a `localStorage` con clave `cuadre-draft:{fecha}:{tienda_id}`; al montar, si existe borrador más reciente que el server-state, banner `AlertBanner` ámbar "Tienes un borrador local de las 14:32 — Restaurar / Descartar". Indicador permanente en la barra sticky: `Borrador guardado 14:32` en `text-[0.7rem] text-kyro-muted`. Se limpia al enviar con éxito. (Supera al legacy: no depende de red.)
4. **Inputs de dinero:** prefijo visual `S/` dentro del input (`text-kyro-subtle`, `pointer-events-none`), valor con `.kyro-money`, selección total al enfocar (`onFocus={e => e.target.select()}`). En filas repetibles, `Enter` en el último campo añade fila nueva y enfoca su primer campo (`AddRowButton` ya existe — solo cablear el atajo).
5. **Validación inline:** campo obligatorio vacío al intentar enviar → borde `#ef4444` + `animate-kyro-shake` (ya existe, `index.css:138`) + scroll al primer error con `scrollIntoView({behavior:'smooth', block:'center'})`.
6. **Modales del cuadre** (`TicketIngresoModal`, `PostVentaModal`): entrada con `opacity 0→1` + `translateY(8px)→0` en `--motion-slow` `--ease-premium` (hoy aparecen secos).

### 2.3 Esfuerzo y ejecutor
**L (1–1.5 días). Opus 4.8** — pantalla de dinero real; el ticket debe repetir en negrita: *prohibido tocar cálculos, mapeos de payload o validaciones de negocio; solo presentación, autosave y aria*. Verificación: suite backend intacta + `tsc` + prueba manual del flujo de cuadre completo en local (seeder QA).

---

## 3. Historial (admin) — `frontend/src/pages/historial/HistorialPage.tsx`

### 3.1 Qué elevar
- **Densidad controlable:** gerencia lo usa para barrer muchas filas; hoy hay una sola densidad.
- **Filtros:** funcionales pero sin visibilidad de "qué está aplicado".
- **Estados:** badges ya existen (ticket-029); faltan skeleton, vacío con identidad y feedback de export.
- **Responsive:** tabla ancha sin tratamiento móvil.

### 3.2 Propuesta concreta
1. **Toggle de densidad:** `SegmentedToggle` (componente ya existente) "Cómoda / Compacta" a la derecha de la toolbar; cómoda = `py-2.5` por celda (actual), compacta = `py-1 text-[0.78rem]`. Persistir en `localStorage` clave `historial:densidad`.
2. **Chips de filtros activos:** debajo de la toolbar, cada filtro aplicado se pinta como chip `h-6 px-2 rounded-full text-[0.72rem]` con `background: var(--color-kyro-gold-dim)`, texto `#b45309` claro / `#ffc200` dark, y `×` para quitarlo; chip extra "Limpiar todo" en `ghost`. 
3. **Encabezado sticky:** `thead` con `position: sticky; top: 0; z-index: 10` y el fondo ya definido en `.kyro-table-head` (verificar opacidad — subir a `rgba(24,24,27,0.95)` en dark para que no transparente filas al hacer scroll).
4. **Zebra + hover:** filas pares `bg-white/[0.015]` dark / `bg-slate-50/60` claro; hover `bg-white/[0.04]` dark con `transition var(--motion-fast)`.
5. **Columna Ganancia:** `.kyro-money`, alineada derecha, verde `#10b981` positiva / rojo `#ef4444` negativa, `—` en `text-kyro-subtle` si null.
6. **Export con feedback:** botones de export usan `Button loading` (patrón 0.2i); al completar, toast/banner de éxito 3 s.
7. **Skeleton y vacío:** `SkeletonRow × 8` en carga; `EmptyState` ícono `Receipt` con texto "Sin cuadres para este filtro" + CTA "Limpiar filtros".
8. **Responsive `<768px`:** la tabla se convierte en lista de cards (una por reporte: fecha+tienda arriba, Venta/Ganancia como pares label-valor, badge de estado a la derecha). Implementar como render alternativo con el mismo dataset, no CSS-only.
9. **Scroll-shadows** (`.kyro-scroll-x`, patrón 0.2g) para el rango 768–1100px donde la tabla aún desborda.

### 3.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5.** Solo frontend (la Ganancia ya viene del backend desde 029/041).

---

## 4. Mi Historial (agente) — `frontend/src/pages/reportes/MiHistorialPage.tsx`

### 4.1 Qué elevar
Es la pantalla "personal" del agente: hoy es una tabla correcta pero fría. La mejora sobre el legacy es darle **narrativa personal** (mi mes, mi progreso) sin inventar datos nuevos — todo sale de los reportes que ya lista.

### 4.2 Propuesta concreta
1. **Franja hero personal (3 `StatCard`):** "Ventas del mes" (suma de sus cuadres del mes en curso), "Cuadres registrados" (conteo), "Último cuadre" (fecha relativa: "hace 2 días"). Acentos: dorado / índigo `#6366f1` / cian `#06b6d4`. Si el endpoint actual no agrega, calcular client-side sobre la página ya cargada del mes (aceptable; anotar en el ticket que si hay paginación se agrega un agregado liviano al endpoint).
2. **Mini gráfico de barras del mes:** Recharts `BarChart` 100%×**72px**, una barra por día con venta declarada, fill `rgba(255,194,0,0.65)`, barra del día seleccionado en `#ffc200` sólido, radius `[3,3,0,0]`, tooltip mínimo (fecha + S/). Clic en barra filtra la tabla a ese día.
3. **Fecha como dato primario:** primera columna con día de semana arriba (`text-[0.68rem] uppercase text-kyro-muted`) y `dd/mm` en `font-semibold` debajo — escaneo vertical mucho más rápido que el `dd/mm/yyyy` plano.
4. **Estados vacíos por contexto:** mes sin cuadres → `EmptyState` ícono `CalendarBlank` "Aún no registras cuadres en {mes}" + CTA "Registrar cuadre" → `/reportes/nuevo`. Filtro sin resultados → variante sin CTA.
5. **Skeleton, zebra, hover y `.kyro-money`:** mismos patrones que Historial (§3.2.3–5) — reutilizar literalmente las mismas clases.

### 4.3 Esfuerzo y ejecutor
**S–M (½ día). Sonnet 5.** Solo frontend salvo el posible agregado del §1 (decidirlo dentro del ticket con criterio: si `per_page` cubre el mes completo, client-side y listo).

---

## 5. Asistencias — Gestión — `frontend/src/pages/asistencias/AsistenciasPage.tsx` (+ `MonitorFraudePanel.tsx`, `AsistenciasTabs.tsx`)

### 5.1 Qué elevar
- KPIs ya correctos (COALESCE, ticket-041) y toolbar ya balanceada (2 sólidos + 2 glass). Falta: **identidad visual de los estados**, escaneo rápido de la tabla, y jerarquía del Monitor de Fraude.
- **Filtros de rango:** cambiar fechas a mano para "hoy/semana/mes" es fricción diaria.

### 5.2 Propuesta concreta
1. **Presets de rango:** `SegmentedToggle` "Hoy · Semana · Mes · Personalizado" a la izquierda de los date-pickers; los presets setean las fechas y disparan el fetch; "Personalizado" habilita los pickers. Default "Hoy".
2. **KPIs con acento superior:** los 4 `StatCard` (Presentes/Ausentes/Tardanzas/Pend. Revisión) pasan a `topAccentColor` (hairline con glow, patrón ticket-021): verde `#22c55e` / rojo `#ef4444` / ámbar `#f59e0b` / cian `#06b6d4`. "Pend. Revisión" > 0 añade `badge-pulse` (animación existente `kyro-pulse`) en un dot de 6px junto al valor.
3. **Estado de asistencia como badge con dot:** en la tabla, cada estado se pinta `inline-flex items-center gap-1.5 h-5 px-2 rounded-full text-[0.7rem] font-semibold` con dot `w-1.5 h-1.5 rounded-full`: PRESENTE verde, TARDANZA ámbar, FALTA rojo, PERMISO cian, CIERRE_AUTO `text-kyro-muted` con borde `dashed` (señal de "lo cerró la máquina, no el humano" — información que el legacy no comunica).
4. **Identidad del agente en fila:** avatar de iniciales 28px (`rounded-full`, fondo determinístico por hash del nombre sobre 6 colores: `#6366f1 #06b6d4 #22c55e #f59e0b #a78bfa #38bdf8`, texto blanco `text-[0.68rem] font-bold`) + nombre. Componente `ui/InitialsAvatar.tsx` reutilizable (lo consumirán Planilla y Revisar Fotos).
5. **Foto de marcación:** thumbnail 32×32 `rounded-kyro-sm` en la fila; hover ≥ 400ms → popover 160×160 con `--motion-fast`; clic → lightbox (reutilizar el de Revisar Fotos, §8.2.2).
6. **Monitor de Fraude:** colapsable (`<details>` estilizado o estado propio) con header rojo `border-l-4 #ef4444` y contador de incidencias en badge `destructive`; colapsado por defecto si 0 incidencias, expandido si > 0. Evita que un panel de excepción domine la pantalla los días normales.
7. **Skeleton + vacío:** patrón estándar; vacío de "Hoy" antes de la primera marcación → `EmptyState` ícono `FingerprintSimple` "Nadie ha marcado todavía — el terminal está en /terminal".

### 5.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5.**

---

## 6. Asistencias — Control mensual — `frontend/src/pages/asistencias/ControlAsistenciasPage.tsx`

### 6.1 Qué elevar
Grid mes×agente denso por naturaleza. La mejora sobre el legacy: convertirlo en un **heatmap legible de un vistazo** con leyenda y navegación fluida, en vez de una tabla de textos.

### 6.2 Propuesta concreta
1. **Celdas heatmap:** cada día-agente es una celda **28×28px** `rounded-[4px]` coloreada por estado con los MISMOS colores del §5.2.3 (consistencia total): PRESENTE `rgba(34,197,94,0.22)` con dot centrado `#22c55e`; TARDANZA `rgba(245,158,11,0.22)`; FALTA `rgba(239,68,68,0.25)`; PERMISO `rgba(6,182,212,0.20)`; sin dato = fondo transparente con borde `1px dashed rgba(148,163,184,0.25)`. Números/horas solo en tooltip, no en celda.
2. **Tooltip por celda:** hover → tooltip `.kyro-glass` `rounded-kyro` con fecha, estado, hora ingreso/salida, minutos de tardanza; delay 150ms.
3. **Columna de agente sticky:** `position: sticky; left: 0` con fondo sólido (`#18181b` dark / `#ffffff` claro) + `InitialsAvatar` (§5.2.4); scroll horizontal con `.kyro-scroll-x`.
4. **Leyenda fija** sobre el grid: fila de chips estado+color (los mismos badges del §5.2.3 en versión mini). Sin leyenda un heatmap es criptográfico.
5. **Fila de resumen por agente** al final de cada fila: `18P · 2T · 1F` en `text-[0.7rem] text-kyro-muted` con los mismos colores por letra.
6. **Navegación de mes:** botones `‹ ›` + label "Junio 2026" en `font-semibold`; transición del grid con fade `--motion-base` (nada de re-render seco).
7. **Fin de semana/feriado:** cabecera de columna sáb/dom en `text-kyro-subtle` y fondo de columna `rgba(148,163,184,0.04)` — separa visualmente lo no-laborable.

### 6.3 Esfuerzo y ejecutor
**M–L (1 día). Sonnet 5** (si el endpoint actual no trae el detalle para tooltips en una sola llamada, anotarlo y resolver con un include opcional — no crear N+1 de peticiones por celda).

---

## 7. Asistencias — Liquidación — `frontend/src/pages/asistencias/HistorialLiquidacionPage.tsx`

### 7.1 Qué elevar
Pantalla de dinero (descuentos/pagos por asistencia). Necesita: **totales visibles antes de actuar**, confirmaciones sólidas y trazabilidad visual del proceso.

### 7.2 Propuesta concreta
1. **Franja resumen (3 `StatCard`):** "Total a liquidar" (dorado, `formatMoney`), "Agentes incluidos" (índigo), "Descuentos aplicados" (rojo `#ef4444`, `formatMoney`). Con `.kyro-money` y count-up del §1.2.2.
2. **Fila expandible por agente:** chevron a la izquierda; expandir revela el desglose (días, tardanzas→descuento, permisos) en un sub-panel `bg-white/[0.02]` con `border-left: 2px solid #ffc200`, animado `max-height` `--motion-base`. Evita el "número final mágico" — el legacy no explica de dónde sale cada monto.
3. **Confirmación de liquidar:** usar `ConfirmDialog` existente (ticket-016) con resumen embebido: "Vas a liquidar S/ X para N agentes del período Y" y el monto en `font-bold text-kyro-gold`. Botón confirmar `variant="gold"` con `loading` (0.2i).
4. **Estado de liquidación como badge-paso:** PENDIENTE (ámbar) → LIQUIDADO (verde) con fecha y usuario en tooltip — auditoría visible.
5. **Skeleton + vacío estándar** (`EmptyState` ícono `Money`).

### 7.3 Esfuerzo y ejecutor
**M (½–1 día). Opus 4.8** — hay montos y una acción irreversible en el flujo; misma cláusula que el cuadre: presentación solamente, cero cambios de cálculo.

---

## 8. Revisar Fotos — `frontend/src/pages/admin/RevisarFotosPage.tsx`

### 8.1 Qué elevar
Tarea repetitiva de aprobación visual: el diseño debe optimizar **fotos grandes + decisión en 1 tecla**. Aquí se puede superar al legacy de forma contundente.

### 8.2 Propuesta concreta
1. **Grid de cards de foto:** `grid gap-3` con `repeat(auto-fill, minmax(180px, 1fr))`; cada card = foto `aspect-[3/4] object-cover rounded-kyro-lg` + pie con `InitialsAvatar` mini, nombre, hora y badge de estado (§5.2.3). Imágenes con `loading="lazy"` + fondo `.kyro-skeleton` mientras cargan.
2. **Lightbox de revisión con teclado:** clic abre overlay `rgba(9,9,11,0.92)` con la foto a `max-height: 80vh`; navegación `←/→`, `A` = aprobar, `R` = rechazar, `Esc` = cerrar; los atajos visibles en una hint-bar inferior (`text-[0.7rem] text-kyro-muted`, estilo `kbd` con borde `1px solid rgba(255,255,255,0.15)` y `border-radius: 4px`). Al decidir, la foto sale con fade+slide 200ms y entra la siguiente automáticamente — flujo de revisión continua sin volver al grid.
3. **Feedback de decisión:** flash de borde 2px verde/rojo en el frame del lightbox (300ms, una vez) + contador "12 pendientes → 11" en el header, `aria-live="polite"`.
4. **Filtro por estado:** `PageTabs` (existente) Pendientes / Aprobadas / Rechazadas con conteo en badge; default Pendientes.
5. **Vacío-celebración:** cero pendientes → `EmptyState` ícono `CheckCircle` verde "Todo revisado ✔ — no hay fotos pendientes" (sin CTA). Es el único vacío "positivo" del bloque y merece distinguirse: borde dashed verde `rgba(34,197,94,0.3)` en vez de dorado.

### 8.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5.**

---

## 9. Planilla — `frontend/src/pages/planilla/PlanillaPage.tsx`

### 9.1 Qué elevar
Tabla financiera pura: **alineación, totales y desglose**. Además arrastra un pendiente de QA (columna CARGO sin confirmar, `04-qa-visual.md` fila 16).

### 9.2 Propuesta concreta
1. **Resolver el pendiente CARGO:** verificar si la columna existe fuera del viewport; si falta, añadirla tras NOMBRES (`text-[0.72rem] uppercase text-kyro-muted`). Cierra el último "a confirmar" del QA en este bloque.
2. **Columnas monetarias:** TODAS alineadas a la derecha con `.kyro-money`; sueldo base neutro, bonos `#22c55e`, descuentos/adelantos `#ef4444` con signo `−` explícito, neto en `font-bold`. La lectura contable correcta es la mejora — el legacy alinea a la izquierda y todo en blanco.
3. **Fila de totales sticky:** `tfoot` con `position: sticky; bottom: 0`, fondo `#18181b`/`#ffffff`, `border-top: 2px solid rgba(255,194,0,0.4)`, totales por columna en `font-bold`. Al hacer scroll, gerencia nunca pierde el total.
4. **Fila expandible** con el desglose del cálculo por agente (mismo patrón/estilo que Liquidación §7.2.2 — literalmente el mismo sub-panel, componente compartido `ui/ExpandableRow.tsx` si sale natural).
5. **`InitialsAvatar`** en la columna de agente (§5.2.4), franja de 3 KPIs arriba (Total planilla / Agentes / Total descuentos — colores dorado/índigo/rojo) y export con `Button loading`.
6. **Skeleton + vacío + scroll-shadows** estándar.

### 9.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5** (si CARGO exige campo del backend, es un `select` más en el endpoint de planilla — dentro del mismo ticket, con test).

---

## 10. Terminal Asistencia — `frontend/src/pages/asistencias/TerminalAsistenciaPage.tsx`

### 10.1 Qué elevar
Es un **kiosko**: pantalla táctil, a distancia, usuarios apurados. Ya está en dorado kyro (ticket-028). La mejora: régimen visual de kiosko real — legibilidad a 2 metros, feedback inequívoco, targets táctiles grandes. Nada de esto existe en el legacy.

### 10.2 Propuesta concreta
1. **Reloj hero:** hora actual en **Orbitron `text-6xl/60px`** `#ffc200`, segundos en `text-2xl text-kyro-muted`, fecha completa debajo (`text-lg text-kyro-body`, "miércoles 10 de julio"). Actualización por segundo con `setInterval` limpiado en unmount.
2. **Targets táctiles:** todo botón accionable del terminal a mínimo **56×56px** (`h-14`), texto `text-base font-semibold`; separación entre acciones ≥ 12px. Input de identificación (DNI/código) `h-14 text-xl text-center tracking-[0.15em]` con autofocus persistente (re-enfocar en `blur` — en kiosko no hay mouse).
3. **Feedback de marcación fullscreen:** al registrar, overlay a pantalla completa **1.4 s**:
   - Éxito: fondo `rgba(34,197,94,0.12)`, círculo central 96px con `CheckCircle` Phosphor `fill` verde, nombre del agente `text-2xl font-bold`, hora marcada en Orbitron `text-xl`, entrada del círculo con `--ease-spring` (scale 0.6→1).
   - Error/no reconocido: fondo `rgba(239,68,68,0.12)`, `XCircle` rojo + mensaje claro ("DNI no encontrado — intenta de nuevo") + `animate-kyro-shake` en el input al volver.
   - Tardanza: variante ámbar con `Clock` y "Ingreso registrado con tardanza (14 min)".
   El overlay lleva `role="status"` y `aria-live="assertive"`.
4. **Marco de cámara:** el preview de cámara (si está activo en esta vista) con borde `2px solid rgba(255,194,0,0.5)` + esquinas de mira (4 pseudo-elementos en L, 20×20px, `border-color: #ffc200`) — costo casi nulo.
5. **Modo kiosko visual:** ocultar sidebar/chrome de la app en `/terminal` (verificar si ya es standalone; si no, envolver en layout mínimo), fondo `#09090b` con los radial-gradients del body actuales, y **estado offline**: si el POST falla por red, banner rojo persistente "Sin conexión — la marcación NO se registró" (jamás fallar en silencio en un kiosko).
6. **Contraste AA garantizado:** todos los textos informativos del terminal ≥ 4.5:1 sobre `#09090b` (los tokens actuales lo cumplen salvo `--color-kyro-subtle` — prohibido aquí, regla 0.2h).

### 10.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5.**

---

## 11. Tickets de una pasada (resumen ejecutable)

| Ticket | Alcance | Archivos principales | Esfuerzo | Ejecutor | Dependencias |
|---|---|---|---|---|---|
| **DIS-B1-00** | Design System v2: motion tokens, Skeleton, EmptyState, `.kyro-money`, hover de card, scroll-shadows, `Button loading`, reduced-motion, fix contraste subtle | `index.css`, `ui/Skeleton.tsx` (nuevo), `ui/EmptyState.tsx` (nuevo), `ui/button.tsx`, `ui/StatCard.tsx` | M | **Sonnet 5** | — (va PRIMERO) |
| **DIS-B1-01** | Dashboard: hero-KPI + delta, count-up, sparkline 7d, frescura/auto-refresh, fila clicable, skeleton/vacío | `DashboardPage.tsx`, `dashboard.api.ts`, `DashboardController.php` (+2 campos/endpoint liviano, con tests) | M | **Sonnet 5** | 00 |
| **DIS-B1-02** | Cuadre: barra sticky con semáforo de diferencia, riel de secciones, autosave localStorage, inputs S/ + Enter, validación shake, entrada de modales | `NuevoReportePage.tsx`, `EditarReportePage.tsx`, `cuadre/*.tsx` | L | **Opus 4.8** | 00. **Cero cambios de lógica de negocio** |
| **DIS-B1-03** | Historial: densidad toggle, chips de filtros, sticky head, zebra/hover, export loading, responsive cards, skeleton/vacío | `historial/HistorialPage.tsx` | M | **Sonnet 5** | 00 |
| **DIS-B1-04** | Mi Historial: hero personal 3 KPIs, mini barras del mes, fecha bicapa, vacíos por contexto | `reportes/MiHistorialPage.tsx` | S–M | **Sonnet 5** | 00 (ideal tras 03 para reutilizar patrones de tabla) |
| **DIS-B1-05** | Asistencias-Gestión: presets de rango, KPIs topAccent+pulse, badges de estado con dot, `InitialsAvatar` (nuevo, compartido), thumbnails de foto, Monitor Fraude colapsable | `AsistenciasPage.tsx`, `MonitorFraudePanel.tsx`, `ui/InitialsAvatar.tsx` (nuevo) | M | **Sonnet 5** | 00 |
| **DIS-B1-06** | Control mensual: heatmap 28px + tooltips + leyenda + sticky agente + resumen por fila + nav de mes + weekend dim | `ControlAsistenciasPage.tsx` | M–L | **Sonnet 5** | 00, 05 (colores de estado e `InitialsAvatar`) |
| **DIS-B1-07** | Liquidación: franja KPIs, filas expandibles con desglose, ConfirmDialog con resumen, badge-paso, estándares | `HistorialLiquidacionPage.tsx` | M | **Opus 4.8** | 00, 05. Solo presentación | 
| **DIS-B1-08** | Revisar Fotos: grid de cards, lightbox con teclado A/R/←/→, flujo continuo, tabs con conteo, vacío-celebración | `admin/RevisarFotosPage.tsx` | M | **Sonnet 5** | 00, 05 (`InitialsAvatar`) |
| **DIS-B1-09** | Planilla: columna CARGO (cerrar pendiente QA), monetarias derecha+color, totales sticky, filas expandibles, KPIs, avatar | `planilla/PlanillaPage.tsx` (+posible select en endpoint planilla con test) | M | **Sonnet 5** | 00, 05, 07 (patrón fila expandible) |
| **DIS-B1-10** | Terminal kiosko: reloj Orbitron, targets 56px, overlay de marcación fullscreen 3 variantes, marco cámara, offline banner, AA | `TerminalAsistenciaPage.tsx` | M | **Sonnet 5** | 00 |

**Olas sugeridas** (dominios disjuntos, sin conflicto de archivos):
- **Ola A:** DIS-B1-00 solo (todo depende de él).
- **Ola B:** 01 + 03 + 10 en paralelo (Dashboard / Historial / Terminal — archivos disjuntos).
- **Ola C:** 02 (Opus) + 05 + 04 en paralelo.
- **Ola D:** 06 + 07 + 08 en paralelo; luego 09 (usa patrones de 05/07).

**Criterios de aceptación comunes a TODOS los tickets:** `tsc -b` y `vite build` limpios; suite backend verde si tocó API; ambos temas (dark y claro) verificados; `prefers-reduced-motion` respetado; ningún color fuera de los tokens de `index.css` (si falta un token, se agrega al `@theme`, no se hardcodea); identidad Ultra Dark Premium — ante la duda, dorado `#ffc200` y glass, nunca gris genérico. Prohibido en los prompts de workers: `taskkill /IM node.exe` (matar solo PIDs propios).

## 12. Pendientes fuera de este bloque (no cerrados aquí)

- Ninguna pantalla del alcance quedó sin plan — **bloque completo** (regla 0.3 satisfecha).
- Quedan para Bloque 2 / decisiones: fusión Dashboard/Historial/MiHistorial (diferida en ticket-043 por tocar dinero real — si se hace, DIS-B1-01/03/04 siguen siendo válidos porque son capas de presentación); botón "Registrar Cuadre" índigo vs azul legacy (propuesta §1.2.7: dorado — pedir confirmación del usuario en el ticket 01); Reporte BCP jerárquico e Ingreso Stock como página (ya listados en `04-qa-visual.md` §3, pertenecen al bloque 2/backlog funcional, no a este plan de diseño).
