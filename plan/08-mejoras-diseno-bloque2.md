# 08 — Mejoras de diseño POR ENCIMA del legacy — BLOQUE 2: módulos de gestión

**Fase:** planes de mejora (solo escritura de planes, cero código). **Autor:** dev3 (Fable, razonamiento bajo).
**Nota de entorno:** la skill `headroom` NO existe en este entorno (mismo hallazgo que `00-inventario-diseno.md` y el Bloque 1) — se planificó sin ella.

**Premisa:** paridad ya lograda (`04-qa-visual.md`: 25 fiel / 9 mejorada / 5 parcial / 0 degradada) y navegación ya consolidada 1:1 con el legacy (`07-mapa-navegacion.md`, ticket-043: tabs internas de Inventario, CRM+Clientes, Personal+Postulantes). Este plan define cómo **superar** al legacy en los módulos de gestión, conservando la identidad **Ultra Dark Premium**: dorado `#ffc200` como acento maestro, glass (`backdrop-filter: blur(20px)`), acentos por sección (púrpura `#c084fc` CRM, cian info, verde éxito), nunca genérico tipo "admin template".

**Dependencia dura:** este bloque **consume el Design System v2 del Bloque 1** (`plan/08-mejoras-diseno-bloque1.md` §0, ticket **DIS-B1-00**): tokens de movimiento (`--motion-fast/base/slow`, `--ease-premium`, `--ease-spring`), `ui/Skeleton.tsx` + `SkeletonRow`, `ui/EmptyState.tsx`, `.kyro-money`, hover de `.kyro-card`, `.kyro-scroll-x`, `Button loading`, `prefers-reduced-motion`, y (de DIS-B1-05) `ui/InitialsAvatar.tsx` y los badges de estado con dot. **Ningún ticket de este bloque arranca antes de que DIS-B1-00 esté integrado**; los que usan `InitialsAvatar` dependen además de DIS-B1-05. Aquí NO se re-especifican esos componentes — se referencian.

**Alcance Bloque 2 (11 dominios):** Inventario (Ver Inventario + tabs Traslados/Kardex/Chips + Matriz + Bitácora + Ingreso Stock) · CRM (+Clientes) · Precios · Comisiones (+Empresa) · Financieras (+Bipay/BCP/Postpago) · Facturación Electrónica · Comprobantes · Personal / Ver Agente · Postulación pública · Tiendas / Usuarios · Tickets.

**Regla de ejecución:** tickets de **una pasada** (autocontenidos, verificables con `tsc -b` + `vite build` + suite backend si tocan API). Ejecutores: **Sonnet 5** (mecánico/UI) u **Opus 4.8** (dinero real o interacción compleja). **Nunca Fable.**

---

## 1. Inventario — `frontend/src/pages/inventario/InventarioPage.tsx` (+ `InventarioTabs.tsx`, `TrasladosPage.tsx`, `KardexInventarioPage.tsx`, `ChipsGestionPage.tsx`, `MatrizInventarioPage.tsx`, `BitacoraStockPage.tsx`, `InventarioForm.tsx`)

### 1.1 Qué elevar
- **Ver Inventario** ya tiene la franja Capital Invertido (ticket-041, `StatCard` todo dorado) y tabs consolidadas (043). Falta: diferenciar los KPIs (hoy 6 cards con el mismo acento `#ffc200` — parrilla plana), estados de stock escaneables, y el flujo **Ingreso Stock** que sigue siendo un modal sin identidad (pendiente medio del QA §3.1).
- **Traslados**: es un flujo con estados (pendiente→aprobado/rechazado) pintado como tabla plana; la aprobación merece trazabilidad visual.
- **Kardex**: es una línea de tiempo de movimientos contada como tabla — el formato natural es timeline.
- **Matriz**: grid denso sin sticky ni leyenda (mismo problema que Control mensual del B1).
- **Bitácora**: log de auditoría sin diferenciación visual por tipo de acción.

### 1.2 Propuesta concreta

**Ver Inventario:**
1. **KPIs con acento semántico:** la franja Capital Invertido diferencia acentos vía `topAccentColor` (hairline con glow, patrón ticket-021): Equipos dorado `#ffc200`, Accesorios cian `#06b6d4`, Chips índigo `#6366f1`, y los totales (unidades/valor) en verde `#22c55e`. Todos los montos con `.kyro-money` y count-up (`useCountUp` de DIS-B1-01).
2. **Estado del ítem como badge con dot** (mismo spec §5.2.3 del B1): DISPONIBLE verde `#22c55e`, VENDIDO `text-kyro-muted` + dot gris, TRASLADO ámbar `#f59e0b`, DEFECTUOSO rojo `#ef4444`. `inline-flex items-center gap-1.5 h-5 px-2 rounded-full text-[0.7rem] font-semibold`, dot `w-1.5 h-1.5 rounded-full`.
3. **IMEI/serial monoespaciado:** columna IMEI con `font-mono text-[0.78rem] tracking-[0.03em]` + botón copiar al hover (`ActionIconButton` con ícono `Copy` Phosphor, toast "IMEI copiado" 2 s). Los IMEI se comparan a ojo contra el equipo físico — el mono elimina errores de lectura.
4. **Alerta de ítems estancados con identidad:** el panel de capital inmovilizado existente pasa a `SectionPanel` con `border-left: 3px solid #f59e0b` + ícono `HourglassMedium` duotone; filas con "días sin movimiento" como badge ámbar cuando > 30 días (`≥ 60` rojo).
5. **Toolbar:** el `SegmentedToggle` Equipos/Accesorios/Chips gana conteo por segmento (`Equipos · 124`); búsqueda con ícono `MagnifyingGlass` embebido y atajo visible `kbd /` (enfoca el input con la tecla `/`).
6. **Skeleton + vacío estándar:** `SkeletonRow × 8`; `EmptyState` ícono `Package` "Sin ítems para este filtro" + CTA "Limpiar filtros"; zebra + hover + sticky head (mismos specs §3.2.3–4 del B1).

**Ingreso Stock (dentro de `InventarioForm.tsx` — cierra el pendiente medio del QA §3.1 como decisión de diseño):**
7. **Selector de tipo como 3 tarjetas** (paridad conceptual con el selector del legacy `registrar_stock.php`, pero elevado): al abrir el modal, primer paso = 3 cards 120px de alto (`Equipo` ícono `DeviceMobileCamera` / `Accesorio` ícono `Headphones` / `Chip` ícono `SimCard`), borde `1px solid rgba(255,255,255,0.08)`, hover y selección con borde `#ffc200` + fondo `rgba(255,194,0,0.06)`; segundo paso = el formulario actual filtrado al tipo. El modal crece a `max-w-2xl` y gana título por paso ("1 de 2 — ¿Qué ingresa?" / "2 de 2 — Detalle").
8. **Input IMEI modo scanner:** `font-mono text-lg h-12 text-center tracking-[0.1em]`, autofocus, `Enter` agrega el IMEI a una lista de chips removibles (ingreso multi-unidad de corrido, como pistola lectora); contador "3 unidades listas". Banner informativo cian si no se fijó precio: "Precio pendiente — se fija en Precios" (cablea con el flujo del ticket-030).

**Traslados (`TrasladosPage.tsx`):**
9. **Estado como badge-paso con trazabilidad:** PENDIENTE ámbar (dot con `kyro-pulse`) → APROBADO verde / RECHAZADO rojo; tooltip con fecha+usuario que decidió. Dirección del traslado como par visual `PUNSC01 → PUNDA23` con ícono `ArrowRight` en `text-kyro-muted` (hoy son dos columnas desconectadas).
10. **Aprobación con `ConfirmDialog`** (ticket-016) con resumen embebido: "Aprobar traslado de N ítems de X a Y" + `Button variant="gold" loading`; al aprobar, la fila hace fade a su nuevo estado (`--motion-base`), sin re-render seco.
11. **Franja de 3 KPIs:** Pendientes (ámbar, con `kyro-pulse` si > 0) / Aprobados del mes (verde) / Rechazados del mes (rojo).

**Kardex (`KardexInventarioPage.tsx`):**
12. **Vista timeline opcional:** `SegmentedToggle` "Tabla / Línea de tiempo". Timeline: riel vertical `2px rgba(255,255,255,0.08)` a la izquierda, cada movimiento un nodo 10px coloreado por tipo (INGRESO verde, VENTA dorado, TRASLADO cian, AJUSTE ámbar, BAJA rojo), card `.kyro-card` compacta a la derecha con fecha relativa + detalle. Agrupado por día con separador `text-[0.7rem] uppercase text-kyro-muted sticky top-0`. La tabla actual queda como default (cero riesgo).
13. **Filtro por tipo de movimiento como chips** toggleables (mismo spec de chips §3.2.2 del B1) en vez de select.

**Matriz (`MatrizInventarioPage.tsx`):**
14. **Sticky doble + leyenda:** primera columna (modelo/producto) `position: sticky; left: 0` fondo sólido `#18181b`/`#ffffff`, `thead` sticky top; `.kyro-scroll-x`. Celdas con valor 0 en `text-kyro-subtle` (decorativo), > 0 `font-semibold`; celda del total por fila con fondo `rgba(255,194,0,0.06)`. Leyenda mini sobre el grid.
15. **Heat sutil por cantidad:** fondo de celda escalonado `rgba(34,197,94,0.06/0.12/0.20)` para 1–4 / 5–9 / ≥10 unidades — lectura de dónde hay stock de un vistazo.

**Bitácora (`BitacoraStockPage.tsx`):**
16. **Ícono + color por tipo de acción:** INGRESO `PlusCircle` verde, VENTA `Tag` dorado, TRASLADO `ArrowsLeftRight` cian, EDICIÓN `PencilSimple` ámbar, ELIMINACIÓN `TrashSimple` rojo — 16px duotone al inicio de la fila. Fecha relativa ("hace 2 h") con la absoluta en tooltip. Skeleton + vacío estándar.

### 1.3 Esfuerzo y ejecutor
Se parte en 3 tickets (ver §12): Ver Inventario+Ingreso **M–L, Sonnet 5**; Traslados+Kardex **M, Sonnet 5**; Matriz+Bitácora **S–M, Sonnet 5**. Todo frontend (los KPIs y endpoints ya existen).

---

## 2. CRM y Marketing (+ Clientes) — `frontend/src/pages/crm/CrmPage.tsx` + `frontend/src/pages/clientes/ClientesPage.tsx` (+ `ClienteForm.tsx`)

### 2.1 Qué elevar
El CRM ya tiene Kanban por temperatura (orden legacy `crm_dashboard.php:909-956`), funnel Recharts y export Excel (ticket-013). Es la pantalla con acento propio (púrpura `#c084fc`) — la mejora es llevar ese acento a un **sistema de temperatura visual coherente** y dar vida al Kanban (hoy las columnas son estáticas). Clientes (tab del CRM desde 043) es una tabla correcta pero sin conexión visual con la temperatura del cliente.

### 2.2 Propuesta concreta
1. **Sistema de temperatura con color fijo** (único en toda la app, documentado en el ticket): Caliente `#ef4444`, Upselling `#a78bfa`, Neutro `#94a3b8`, Frío `#38bdf8`. Cabecera de cada columna Kanban: dot 8px + label + conteo en badge; borde superior de columna `2px solid` su color al 60%. `TemperaturaCard`/`TemperaturaColumna` y las filas de Clientes consumen la MISMA constante (`crm/temperatura.ts` nuevo, solo colores/labels).
2. **Tarjeta de lead elevada:** `.kyro-card` compacta con hover `translateY(-2px)` + sombra (token DIS-B1-00 §0.2f), nombre `font-semibold`, teléfono `font-mono text-[0.75rem]`, fecha de última interacción relativa; badge de días sin contacto cuando > 7 días (`h-5 rounded-full text-[0.68rem]` ámbar; > 15 días rojo) — el dato de seguimiento que el legacy no comunica.
3. **Microinteracción de cambio de columna:** al mover/reclasificar un lead, la tarjeta sale con fade+slide 200ms y entra en la columna destino con `--ease-spring` (scale 0.95→1). Si el movimiento es por select (no drag), igual se anima.
4. **Funnel con identidad:** las barras del pipeline usan degradado púrpura→dorado (`#c084fc` → `#ffc200` según etapa de avance), labels con `.kyro-money` cuando son montos; tooltip `.kyro-glass`.
5. **Panel de dashboard CRM:** los KPIs superiores con `topAccentColor` púrpura `#c084fc` (identidad de sección, no dorado aquí); count-up.
6. **Clientes (tab):** columna Temperatura con el badge del §2.2.1; búsqueda con atajo `/`; fila clicable → panel lateral (drawer `w-[380px]` `.kyro-glass`, entra con `translateX(16px)→0` `--motion-slow`) con historial de interacciones en timeline mini (patrón §1.2.12) — evita saltar de pantalla para ver el contexto del cliente. Datos: endpoint de historial ya existente del CRM (verificar `useCrmTemperatura`/historial en `crm.api.ts`; si falta el detalle por cliente, anotar y usar el modal actual como fallback en el mismo ticket).
7. **Export con `Button loading`** (0.2i del B1) + skeleton/vacío estándar (`EmptyState` ícono `UsersThree` púrpura al 40%: excepción documentada — en CRM el vacío usa púrpura, no dorado, coherencia de sección).

### 2.3 Esfuerzo y ejecutor
**M–L (1 día). Sonnet 5.** Solo frontend salvo la verificación del endpoint de historial (§6) — decidir dentro del ticket, sin crear endpoints nuevos si el existente alcanza.

---

## 3. Precios — `frontend/src/pages/admin/RevisarStockPage.tsx`

### 3.1 Qué elevar
QA la marca **Fiel/Mejorada**. Es la pantalla donde gerencia fija precios (incluye "fijar precio agente" del T2.3 y los pendientes de precio del ticket-030) — la mejora es hacer del **precio editable** un elemento de primera clase y visibilizar los ítems sin precio.

### 3.2 Propuesta concreta
1. **Cola "Sin precio" primero:** franja superior con banner ámbar si existen ítems con precio pendiente (flujo ticket-030): "N ítems ingresados sin precio — fijarlos ahora" con ancla que filtra la tabla. Badge `kyro-pulse` en el conteo.
2. **Edición de precio inline premium:** el input de precio con prefijo `S/` interno (`text-kyro-subtle pointer-events-none`), `.kyro-money`, `text-right`, selección total al enfocar; al guardar, flash del borde verde 300ms una vez + toast. `Enter` guarda y salta al siguiente input de la columna (edición en ráfaga, estilo hoja de cálculo — muy por encima del legacy).
3. **Margen visible:** si costo y precio están presentes, mostrar bajo el input `+S/ 45.00 · 18%` en `text-[0.7rem]` verde `#10b981` (rojo si el margen es negativo — venta a pérdida visible ANTES de guardar).
4. **Precio agente vs precio público:** las dos columnas con cabecera diferenciada (`PRECIO PÚBLICO` neutro, `PRECIO AGENTE` con dot dorado) y el delta entre ambas en tooltip.
5. **Skeleton, zebra, sticky head, vacío estándar** (specs B1 §3.2).

### 3.3 Esfuerzo y ejecutor
**M (½–1 día). Opus 4.8** — edita precios reales (dinero): cláusula "solo presentación e interacción de inputs; cero cambios en payloads/validaciones de guardado".

---

## 4. Comisiones (+ Comisiones Empresa) — `frontend/src/pages/comisiones/ComisionesPage.tsx`

### 4.1 Qué elevar
Ya consolidada con `GananciasOperativasSection`/`EstrategiaComisionesSection` siempre visibles con color-coding (ticket-029) y ranking por categoría (T2.4). Faltan: jerarquía entre las 3 zonas de la página (tabla de planes + 2 secciones), edición de rangos con feedback, y el pendiente "a confirmar" del QA (inputs BIPAY/KRECE/PAYJOY en blanco en captura headless).

### 4.2 Propuesta concreta
1. **Cerrar el pendiente QA:** verificación manual (2 min) de los inputs de `EstrategiaComisionesSection` en navegador real; si es artefacto de Playwright, anotarlo cerrado en `04-qa-visual.md` §2; si es bug real, corregir el binding en el mismo ticket.
2. **Color-coding de estrategia como sistema:** BIPAY, KRECE y PAYJOY ganan cada uno un color fijo (BIPAY cian `#06b6d4`, KRECE verde `#22c55e`, PAYJOY violeta `#a78bfa`) aplicado como `border-left: 3px solid` de su card + dot en el título + fondo del input activo al 6%. Documentar la tripleta en el ticket (la reutiliza Financieras §5).
3. **Rangos como tabla escalonada visual:** cada fila de rango (`desde–hasta → S/ comisión`) con barra horizontal proporcional de fondo (`rgba(255,194,0,0.08)`, ancho relativo al monto máximo) — el "escalón" se ve, no solo se lee. Montos con `.kyro-money` derecha.
4. **Edición con dirty-state:** al modificar un rango, la fila marca dot ámbar "sin guardar" y el botón Guardar de la sección pasa a `variant="gold"` con contador "Guardar (3 cambios)"; `Button loading` al enviar; toast éxito. Evita el clásico "¿guardé o no?" en configuración financiera.
5. **Tabla de planes:** zebra, hover, sticky head, `.kyro-money`; badge de categoría del plan con dot (colores por categoría estables por hash, mismos 6 del `InitialsAvatar`).
6. **Skeleton + vacío estándar.**

### 4.3 Esfuerzo y ejecutor
**M (1 día). Opus 4.8** — configura dinero (comisiones): cláusula estándar de cero cambios de lógica/payload salvo el posible fix del §1 si resulta bug real (con test).

---

## 5. Financieras (+ Bipay/Anypay, Reporte BCP, Churn/Postpago) — `frontend/src/pages/admin/PanelFinancierasPage.tsx`, `frontend/src/pages/bipay/PanelBipayPage.tsx`, `frontend/src/pages/bcp/ReporteBcpPage.tsx`, `frontend/src/pages/postpago/PostpagoPage.tsx`

### 5.1 Qué elevar
Cuatro paneles financieros hermanos sin lenguaje común. Bipay ya no colapsa en vacío (fix `18da642`) y BCP ya no muestra `undefined` (fix `a5bee3f`), pero BCP arrastra el pendiente **medio-bajo real** del QA: tabla plana vs agrupación jerárquica fecha+tienda+turno del legacy.

### 5.2 Propuesta concreta
1. **BCP jerárquico (cierra el pendiente QA §3.2):** agrupar filas por fecha+tienda con fila-cabecera colapsable (`chevron` + fecha `font-semibold` + tienda + subtotal `.kyro-money` a la derecha); turnos como filas hijas indentadas `pl-8` con `border-left: 2px solid rgba(255,194,0,0.25)`; expandido por defecto el día más reciente. Animación `max-height` `--motion-base`. Estado de colapso NO persistido (es lectura de reporte, no preferencia).
2. **Tripleta financiera coherente:** los KPIs de Bipay/Anypay usan los colores de estrategia del §4.2.2 (BIPAY cian, etc.); Financieras y Postpago usan `topAccentColor` según semántica (montos a favor verde, deudas/churn rojo, neutros índigo). Todos los montos `.kyro-money` + count-up.
3. **Postpago/Churn:** el indicador de churn como badge semáforo (`< 2%` verde / `2–5%` ámbar / `> 5%` rojo — umbrales a confirmar contra el legacy en el ticket; si el legacy no los define, usar estos y documentarlos); tendencia con flecha ▲▼ vs mes anterior si el dato ya viene en el payload (si no viene, NO crear endpoint — anotar como mejora futura).
4. **Cuadre Bitel (`CuadreBitelPage.tsx`, hermana de Bipay):** mismos patrones de tabla estándar (zebra/hover/sticky/`.kyro-money`) — incluida para que el módulo quede uniforme.
5. **Skeleton + vacío estándar en las 4** (`EmptyState` íconos: `Bank` Financieras, `CreditCard` Bipay, `ChartBar` BCP, `PhoneDisconnect` Postpago).

### 5.3 Esfuerzo y ejecutor
**M–L (1 día). Sonnet 5** (el agrupado BCP es transformación client-side del dataset ya recibido; si el payload no trae los campos de agrupación, anotarlo y hacer solo el resto).

---

## 6. Facturación Electrónica — `frontend/src/pages/admin/ConfiguracionFacturacionPage.tsx` (+ `ConfiguracionPage.tsx` Perfil de Empresa)

### 6.1 Qué elevar
QA: **Mejorada**. Es configuración sensible (certificado PFX, credenciales SUNAT, series). La mejora es de **confianza**: estado del sistema visible, secretos bien tratados, acciones con confirmación proporcional al riesgo.

### 6.2 Propuesta concreta
1. **Header de estado de conexión:** card superior `.kyro-glass` con semáforo grande: dot 10px + "Facturación operativa" verde / "Certificado por vencer" ámbar / "Sin configurar · Error de credenciales" rojo; a la derecha, vencimiento del certificado como fecha + badge de días restantes (`> 30` verde, `8–30` ámbar con `kyro-pulse`, `≤ 7` rojo). Dato: la config ya cargada expone el certificado (ticket-006); si la fecha de expiración no está en el payload, exponerla desde el PEM ya parseado (cambio backend menor, con test).
2. **Secretos con affordance correcta:** campos de contraseña/clave SOL con toggle ojo (`Eye`/`EyeSlash` Phosphor), NUNCA texto plano por defecto; al editar un secreto guardado, placeholder `••••••••` y banner cian "Deja en blanco para conservar el actual".
3. **Zona de acciones peligrosas:** "Reemplazar certificado" y "Cambiar modo (beta/producción)" en una sección al pie con `border: 1px solid rgba(239,68,68,0.3)`, título rojo, y `ConfirmDialog` que exige escribir el RUC para confirmar el cambio de modo (patrón type-to-confirm — la única acción del bloque que lo amerita).
4. **Upload de certificado como dropzone:** área `border: 2px dashed rgba(255,194,0,0.3)` con ícono `Certificate` duotone, estados hover/drag-over (borde sólido dorado), nombre+peso del archivo elegido, y `Button loading` al subir. El botón "Sincronizar logo con facturación" (ya existente, `e205bd8`) gana el mismo `loading` + toast.
5. **Formulario en secciones `SectionPanel`** con hairline de color: Emisor (dorado), Credenciales SUNAT (rojo suave — sensible), Series y correlativos (cian), Certificado (ámbar). Perfil de Empresa (`ConfiguracionPage.tsx`) adopta el mismo patrón de secciones para que Configuración sea un módulo uniforme.
6. **Skeleton de formulario** (bloques `Skeleton h-10` por campo) en carga inicial.

### 6.3 Esfuerzo y ejecutor
**M (1 día). Opus 4.8** — toca configuración fiscal y potencialmente un campo nuevo de backend (§1): cláusula de cero cambios en la lógica de firma/emisión; solo presentación + el campo de expiración con test.

---

## 7. Comprobantes — `frontend/src/pages/comprobantes/ComprobantesPage.tsx`

### 7.1 Qué elevar
Migrada a `comprobantes_cola` real (ticket-010) con acciones (reintentar, WhatsApp, links CPE). Es una **cola de procesamiento** — el diseño debe contar el ciclo de vida del comprobante, no solo listarlo.

### 7.2 Propuesta concreta
1. **Estado de cola como badge-paso con dot** (colores fijos): PENDIENTE ámbar `#f59e0b` (dot `kyro-pulse`), PROCESANDO cian `#06b6d4` (dot con `animate-spin` sutil — ícono `CircleNotch` 10px), ACEPTADO verde `#22c55e`, RECHAZADO rojo `#ef4444`, ERROR rojo con borde `dashed` (reintentable). Tooltip con el mensaje SUNAT crudo.
2. **Franja de 4 KPIs de cola:** Pendientes (ámbar, `kyro-pulse` si > 0) / Aceptados hoy (verde) / Rechazados (rojo) / En error (rojo dashed) — con los conteos del index existente; si el endpoint no agrega, calcular client-side sobre la página y anotarlo.
3. **Acciones por fila con jerarquía:** ver PDF/XML y WhatsApp como `ActionIconButton` fantasma; "Reintentar" como botón texto solo visible en filas ERROR/RECHAZADO, con `ConfirmDialog` ligero + `Button loading`; tras reintentar, la fila pasa a PROCESANDO con transición de badge (no refetch seco).
4. **Número de comprobante como identidad:** `B001-00001234` en `font-mono font-semibold`; serie y correlativo visualmente separados (serie en `text-kyro-muted`).
5. **Auto-refresh consciente:** si hay filas PENDIENTE/PROCESANDO, `refetchInterval: 15_000` con línea de frescura "Actualizado hace Xs" (patrón B1 §1.2.4); si no hay filas activas, sin polling.
6. **Filtros por estado como chips** toggleables con conteo + skeleton/vacío estándar (`EmptyState` ícono `Files`).

### 7.3 Esfuerzo y ejecutor
**M (1 día). Sonnet 5** (documentos fiscales pero acciones ya existentes y testeadas; cero lógica nueva — solo presentación y polling condicional).

---

## 8. Personal / Ver Agente — `frontend/src/pages/agentes/AgentesPage.tsx` (+ `PersonalTabs.tsx`, `VerAgentePage.tsx`, `AgenteForm.tsx`)

### 8.1 Qué elevar
- **Personal:** tabla con apellidos ya corregida; le falta identidad humana (es la pantalla de PERSONAS) y estado laboral escaneable.
- **Ver Agente:** ya tiene Ficha RRHH violeta, Contactos naranja y KPIs de boletas (021/029). Dos deudas reales: (1) el editor de contactos de emergencia es **texto plano** `nombre | parentesco | telefono` por línea (deuda UX anotada en QA §2); (2) la postulación pública captura campos RRHH que aquí no se muestran (dato clave de QA D1).

### 8.2 Propuesta concreta

**Personal:**
1. **`InitialsAvatar`** (DIS-B1-05) 28px en cada fila + nombre completo `font-semibold` y DNI en `text-[0.72rem] text-kyro-muted font-mono` debajo — la fila se vuelve una persona, no un registro.
2. **Estado laboral como badge con dot:** ACTIVO verde, INACTIVO gris `text-kyro-muted`, y (si existe el estado) SUSPENDIDO ámbar. Filtro por estado como chips con conteo.
3. **Fila clicable completa** → `/agentes/:id` (hoy solo el botón); hover estándar; skeleton + vacío (`EmptyState` ícono `Users` + CTA "Registrar agente").
4. **Tab Postulantes** (`PersonalTabs`): badge de pendientes con `kyro-pulse` si > 0 (ya movido al tab en 043 — solo añadir el pulso).

**Ver Agente:**
5. **Header de perfil hero:** franja superior con `InitialsAvatar` 56px (o foto de perfil si existe — la postulación ya la captura), nombre `text-xl font-bold`, cargo+tienda en `text-kyro-muted`, badge de estado, y antigüedad calculada ("2 años, 3 meses") — humaniza la ficha por encima del legacy.
6. **Contactos de emergencia como campos reales (cierra la deuda UX):** reemplazar el textarea `nombre | parentesco | telefono` por filas repetibles de 3 inputs + `AddRowButton` (componente existente) + botón quitar por fila. **Serialización al MISMO formato string actual** al guardar (cero cambio de backend/payload — parse/format en el cliente, con test de ida y vuelta si hay test runner de frontend; si no, casos borde documentados en el ticket: pipes en el nombre, filas vacías).
7. **Mostrar los campos RRHH ya capturados en la postulación** que hoy no se pintan (cotejar contra `PostulacionPublicaPage.tsx` campo a campo dentro del ticket): solo lectura en la Ficha RRHH violeta si el backend ya los devuelve; si el endpoint del agente no los expone, anotar el gap con la lista exacta de campos (NO crear el endpoint en este ticket).
8. **Secciones con hairline consistente:** mantener violeta RRHH / naranja contactos (identidad ya establecida) y alinear el resto de paneles (`BoletasPanel`, liquidación) al patrón `SectionPanel` + acentos; montos con `.kyro-money`.

### 8.3 Esfuerzo y ejecutor
**M–L (1 día). Sonnet 5** para Personal; el bloque Ver Agente (contactos + campos RRHH) es delicado de datos → mismo ticket pero con cláusula: la serialización de contactos NO cambia el formato persistido.

---

## 9. Postulación pública — `frontend/src/pages/PostulacionPublicaPage.tsx` (+ `frontend/src/pages/admin/PostulacionesPage.tsx`)

### 9.1 Qué elevar
QA: **Mejorada** (ambas). Es la ÚNICA pantalla pública de cara a candidatos — es marketing de la empresa. Hoy es un formulario largo de una pasada, sin progreso ni jerarquía. La mejora: experiencia de postulación por pasos con la identidad de la marca.

### 9.2 Propuesta concreta
1. **Wizard de pasos:** dividir el formulario en 3–4 pasos lógicos según sus secciones actuales (identificar en el ticket; esperable: Datos personales → Contacto y emergencia → Documentos y fotos → Revisión). Stepper superior: círculos 28px numerados, activo relleno `#ffc200` texto `#09090b`, completado con `Check` verde, conectores `2px` que se pintan dorado al avanzar; labels `text-[0.72rem]`. Navegación Atrás/Siguiente con validación por paso (los mismos schemas zod actuales, particionados — **sin cambiar ninguna regla**).
2. **Paso final de revisión:** resumen de todo lo ingresado en pares label-valor agrupados por paso, con link "Editar" por grupo que regresa al paso; el envío real solo desde aquí (`Button variant="gold" loading` grande, `h-12`).
3. **Progreso persistente:** estado del wizard en `sessionStorage` (clave `postulacion-draft`) — un candidato que recarga no pierde 10 minutos de tipeo (supera al legacy con contundencia). Se limpia al enviar.
4. **Upload de fotos (perfil/DNI) como dropzone con preview:** mismo spec §6.2.4 (dashed dorado + preview 96px `rounded-kyro-lg` + botón rehacer); validación de peso/formato con mensaje inline, no alert.
5. **Pantalla de éxito con identidad:** al enviar, vista completa (no toast): `CheckCircle` verde 64px con entrada `--ease-spring`, "¡Postulación recibida!", número/folio si el backend lo devuelve, y texto de siguientes pasos. Fondo con los radial-gradients del body.
6. **Header público:** logo de la empresa + título "Trabaja con nosotros" `text-2xl font-bold` — la página debe verse de la marca, no un formulario suelto.
7. **Postulantes (admin, `PostulacionesPage.tsx`):** cards de postulante con foto/`InitialsAvatar` + badge PENDIENTE/APROBADO/RECHAZADO; `ConfirmDialog` en aprobar/rechazar con `Button loading`; vacío-celebración estilo B1 §8.2.5 cuando no hay pendientes.

### 9.3 Esfuerzo y ejecutor
**L (1–1.5 días). Opus 4.8** — el wizard reordena un formulario público con validaciones zod y uploads: riesgo de romper el flujo de captura si se hace mecánicamente. Cláusula: el payload final de envío debe ser byte-idéntico al actual (mismos campos, mismos nombres); prueba manual del flujo completo con fotos en local antes de dar por bueno.

---

## 10. Tiendas / Usuarios — `frontend/src/pages/admin/TiendasPage.tsx` + `frontend/src/pages/admin/UsuariosPage.tsx`

### 10.1 Qué elevar
QA: Tiendas **Fiel**, Usuarios **Fiel/Mejorada** (pendiente bajo: campo "Razón Social Bipay/Anypay" sin confirmar dónde vive). Son CRUDs administrativos — la mejora es estándar de calidad, no reinvención.

### 10.2 Propuesta concreta
1. **Tiendas como cards de identidad:** cada tienda una `.kyro-card` con código `font-mono font-bold text-kyro-gold` (ej. `PUNSC01`), nombre, dirección `text-kyro-muted`, y mini-fila de stats si el payload ya las trae (agentes asignados / usuarios); grid `repeat(auto-fill, minmax(260px, 1fr))`. Toggle "Cards / Tabla" con `SegmentedToggle` (tabla actual como opción, cards como default). Si el payload no trae stats, cards sin stats — no crear endpoint.
2. **Usuarios:** `InitialsAvatar` + badge de rol con color fijo (admin dorado `#ffc200`, tienda cian `#06b6d4`); estado activo/inactivo como `ToggleSwitch` existente con `ConfirmDialog` al desactivar ("El usuario perderá acceso inmediatamente"); último acceso como fecha relativa si el dato existe.
3. **Cerrar el pendiente bajo del QA:** verificar en el legacy (`usuarios.php` / `tiendas.php`) dónde vive "Razón Social Bipay/Anypay"; si pertenece a Usuarios, añadir el campo al modal (backend ya tiene columnas Bipay por T2.2); si vive en Tiendas o no existe, documentar el cierre en `04-qa-visual.md`.
4. **Formularios en modal con secciones** (patrón §6.2.5 mini), validación inline con `animate-kyro-shake`, `Button loading` al guardar.
5. **Skeleton + vacío estándar en ambas.**

### 10.3 Esfuerzo y ejecutor
**S–M (½ día). Sonnet 5.**

---

## 11. Tickets — `frontend/src/pages/tickets/TicketsPage.tsx` (+ `TicketImpresionPage.tsx`)

### 11.1 Qué elevar
QA: **Fiel/Mejorada** con 2 pendientes bajos reales: sin búsqueda exacta por N° Ticket y sin columna "Cajero" separada de "Vendedor". Es la pantalla de consulta rápida en mostrador — optimizar para **encontrar un ticket en segundos**.

### 11.2 Propuesta concreta
1. **Búsqueda por N° Ticket (cierra pendiente QA):** input dedicado `font-mono` con placeholder `N° ticket…`, match exacto contra el campo del payload; si requiere parámetro nuevo en el endpoint, añadirlo con test (filtro `where` simple).
2. **Columna Cajero separada de Vendedor (cierra pendiente QA):** si el dato ya viene en el payload, columna nueva; si no, exponerlo en el index (select más, con test) — decidir dentro del ticket con evidencia.
3. **N° de ticket como identidad:** `font-mono font-semibold` + botón copiar al hover (spec §1.2.3); monto con `.kyro-money` derecha.
4. **Botón imprimir con feedback:** `ActionIconButton` `Printer` que abre `TicketImpresionPage` — con estado `loading` mientras genera; tooltip "Reimprimir ticket".
5. **Filtro de fecha con presets** "Hoy · Semana · Mes · Personalizado" (mismo spec B1 §5.2.1 — default "Hoy": en mostrador el 95% de búsquedas son del día).
6. **Zebra, hover, sticky head, skeleton, vacío estándar** (`EmptyState` ícono `Ticket` "Sin tickets para este filtro").

### 11.3 Esfuerzo y ejecutor
**S–M (½ día). Sonnet 5** (los 2 cierres de QA pueden tocar backend trivial: 1 filtro + 1 select, ambos con test).

---

## 12. Tickets de una pasada (resumen ejecutable)

Numeración continúa la del Bloque 1. **Prerequisito global: DIS-B1-00 integrado** (y DIS-B1-05 para los marcados con †, por `InitialsAvatar`/badges de estado).

| Ticket | Alcance | Archivos principales | Esfuerzo | Ejecutor | Dependencias |
|---|---|---|---|---|---|
| **DIS-B2-11** | Ver Inventario: KPIs semánticos, badges estado†, IMEI mono+copy, estancados, toolbar; Ingreso Stock: selector 3 tarjetas + scanner IMEI multi-unidad | `InventarioPage.tsx`, `InventarioForm.tsx` | M–L | **Sonnet 5** | B1-00, B1-05 |
| **DIS-B2-12** | Traslados: badge-paso + trazabilidad + ConfirmDialog + KPIs; Kardex: vista timeline + chips de filtro | `TrasladosPage.tsx`, `KardexInventarioPage.tsx` | M | **Sonnet 5** | B1-00 |
| **DIS-B2-13** | Matriz: sticky doble + heat sutil + leyenda; Bitácora: ícono/color por acción + fechas relativas | `MatrizInventarioPage.tsx`, `BitacoraStockPage.tsx` | S–M | **Sonnet 5** | B1-00 |
| **DIS-B2-14** | CRM: sistema de temperatura (`crm/temperatura.ts`), tarjetas de lead + días sin contacto, animación de columna, funnel púrpura→dorado; Clientes: badge temperatura + drawer de historial | `CrmPage.tsx`, `ClientesPage.tsx`, `crm/temperatura.ts` (nuevo) | M–L | **Sonnet 5** | B1-00 |
| **DIS-B2-15** | Precios: cola sin-precio, edición inline en ráfaga con `Enter`, margen visible, columnas público/agente | `RevisarStockPage.tsx` | M | **Opus 4.8** | B1-00. **Solo presentación — cero cambios en guardado** |
| **DIS-B2-16** | Comisiones: verificar inputs QA, tripleta BIPAY/KRECE/PAYJOY, rangos con barra proporcional, dirty-state con contador | `ComisionesPage.tsx` | M | **Opus 4.8** | B1-00. Cero lógica salvo fix §4.2.1 si es bug real |
| **DIS-B2-17** | Financieras/Bipay/BCP/Postpago: BCP jerárquico colapsable (cierra QA §3.2), tripleta financiera, semáforo churn, tabla estándar ×4 + CuadreBitel | `PanelFinancierasPage.tsx`, `PanelBipayPage.tsx`, `ReporteBcpPage.tsx`, `PostpagoPage.tsx`, `CuadreBitelPage.tsx` | M–L | **Sonnet 5** | B1-00, B2-16 (tripleta de colores) |
| **DIS-B2-18** | Facturación: header estado+vencimiento cert (posible campo backend con test), secretos con ojo, zona peligrosa type-to-confirm, dropzone cert; Perfil Empresa en secciones | `ConfiguracionFacturacionPage.tsx`, `ConfiguracionPage.tsx` (+posible campo en config endpoint) | M | **Opus 4.8** | B1-00. Cero cambios en firma/emisión |
| **DIS-B2-19** | Comprobantes: badges ciclo de vida, 4 KPIs de cola, reintento con transición, número mono, auto-refresh condicional, chips de filtro | `ComprobantesPage.tsx` | M | **Sonnet 5** | B1-00 |
| **DIS-B2-20** | Personal: avatar†+DNI, badges estado, fila clicable, pulso postulantes; Ver Agente: header hero, contactos emergencia como filas repetibles (mismo formato persistido), campos RRHH de postulación en solo-lectura | `AgentesPage.tsx`, `PersonalTabs.tsx`, `VerAgentePage.tsx` | M–L | **Sonnet 5** | B1-00, B1-05. Serialización de contactos SIN cambio de formato |
| **DIS-B2-21** | Postulación pública: wizard con stepper, revisión final, draft en sessionStorage, dropzones con preview, pantalla de éxito, header de marca; Postulantes admin: cards + confirmaciones | `PostulacionPublicaPage.tsx`, `admin/PostulacionesPage.tsx` | L | **Opus 4.8** | B1-00. **Payload de envío byte-idéntico**; prueba manual e2e con fotos |
| **DIS-B2-22** | Tiendas cards+toggle; Usuarios avatar†+rol+toggle con confirm; cierre pendiente Razón Social Bipay | `TiendasPage.tsx`, `UsuariosPage.tsx` | S–M | **Sonnet 5** | B1-00, B1-05 |
| **DIS-B2-23** | Tickets: búsqueda N° exacta + columna Cajero (cierres QA, backend trivial con test), presets fecha, mono+copy, imprimir loading | `TicketsPage.tsx` (+filtro/select en endpoint con test) | S–M | **Sonnet 5** | B1-00 |

**Olas sugeridas** (dominios disjuntos, sin conflicto de archivos; asumen DIS-B1-00 y DIS-B1-05 ya integrados):
- **Ola A:** B2-11 + B2-14 + B2-19 (Inventario / CRM / Comprobantes).
- **Ola B:** B2-12 + B2-16 (Opus) + B2-20 (Traslados-Kardex / Comisiones / Personal).
- **Ola C:** B2-13 + B2-17 + B2-18 (Opus) (Matriz-Bitácora / Financieras — requiere B2-16 / Facturación).
- **Ola D:** B2-15 (Opus) + B2-21 (Opus) + B2-22 + B2-23 (Precios / Postulación / Tiendas-Usuarios / Tickets).

**Criterios de aceptación comunes a TODOS los tickets** (idénticos al Bloque 1): `tsc -b` y `vite build` limpios; suite backend verde si tocó API; ambos temas (dark y claro) verificados; `prefers-reduced-motion` respetado; ningún color fuera de los tokens de `index.css` (si falta un token se agrega al `@theme`, no se hardcodea); identidad Ultra Dark Premium — ante la duda, dorado `#ffc200` y glass, nunca gris genérico; los acentos de sección (púrpura CRM, tripleta financiera) se documentan como constantes, no valores sueltos. Prohibido en los prompts de workers: `taskkill /IM node.exe` (matar solo PIDs propios).

## 13. Pendientes fuera de este bloque (no cerrados aquí)

- Ninguna pantalla del alcance quedó sin plan — **bloque completo** (regla 0.3 satisfecha). Se incluyeron además las hermanas del alcance (CuadreBitel, Perfil de Empresa, Postulantes admin) para que sus módulos queden uniformes.
- **Fuera de alcance (funcional, no diseño):** exponer `tiene_bcp` en `/me` para ocultar "Reporte BCP" a tienda sin flag (gap de 07-mapa §GAP); página dedicada "Ingreso Stock" tipo legacy — este plan lo resuelve como modal elevado (§1.2.7–8), la decisión de vista propia sigue siendo de producto; IntegradorPage ZIP con token-en-query (pendiente menor del ticket-042, es seguridad → corresponde a `09-plan-ciberseguridad.md`); tab inicial de Productividad "Resumen" (pendiente bajo QA — `EstadisticasPage.tsx` no está en el alcance nombrado de ningún bloque; 1 línea, candidata a colarse en cualquier ticket vecino o en un polish).
- **Decisiones que requieren al usuario:** ninguna bloqueante; los umbrales de churn (§5.2.3) y el default cards en Tiendas (§10.2.1) van propuestos con valores y son reversibles en 1 línea.
