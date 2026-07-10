# TICKET-026 — QA visual en vivo, pantalla por pantalla (informe consolidado)

Metodología: backend (SQLite + `QaDemoSeeder`) y frontend levantados en local (ver
`plan/04-qa-visual-setup.md`), navegados con Playwright temporal en 1440×900, sesión
`admin@qa.test`. Cada pantalla se comparó contra su captura FireShot legacy cuando se pudo
identificar cuál de las 33 le correspondía, y contra las notas de `00-inventario-diseno.md`
§3 (o el código legacy vivo en `E:\laragon\www\sistema-rolando-salas`) en los demás casos.

Este documento consolida las 4 pasadas del QA (Bloque A, B, C, D1+D2) más la re-QA de la
pantalla de cuadre, y **verifica contra `git log` y el código actual en `main`** (commits
`a5bee3f`, `18da642`, `ce9a2f0`, `f18dfb6`, `93cbb1c`) qué hallazgos ya fueron corregidos
desde que se escribieron los informes parciales. Los 4 archivos parciales
(`04-qa-visual-B/C/D1/D2.md`) se eliminaron una vez volcado su contenido aquí.

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

**Excluida de todos los bloques:** pantalla de cuadre como tal ya no está excluida — se le
hizo re-QA post-ticket-020 (ver fila 35) y pasó a ser referencia de calidad del port.

---

## 1. Tabla maestra (35 pantallas + cuadre)

| # | Pantalla | Ruta refactor | Bloque | Veredicto final | Nota corta |
|---|---|---|---|---|---|
| 1 | Login | `/login` | A | **Mejorada** | Sin cambios desde el QA. |
| 2 | Dashboard | `/` | A | **Fiel** | Casi 1:1 con `legacy_01.png`. |
| 3 | Productividad | `/estadisticas` | A | **Parcial** | Tab inicial "Por Tienda" en vez de "Resumen" — pendiente (polish, baja). |
| 4 | CRM | `/crm` | A | **Fiel** (corregido) | Resaltado activo del sidebar ya usa púrpura `#c084fc` — **corregido en ce9a2f0 (ticket-028)**. |
| 5 | Precios | `/revisar-stock` | A | **Fiel / Mejorada** | Sin cambios. |
| 6 | Historial admin | `/historial` | A | **Fiel** (corregido) | Columna "Ganancia" por fila + badge de color en estado — **corregido en f18dfb6 (ticket-029)**. |
| 7 | Mi Reporte Personal | `/mi-historial` | A | **Fiel** (corregido) | `Invalid Date` en columna FECHA — **corregido en a5bee3f (ola de fixes 1)**. |
| 8 | Ver Agente | `/agentes/:id` | A | **Fiel / Parcial** | Ver hallazgo revisado más abajo (§3) — el gap de Ficha RRHH/Contactos era una lectura errónea del QA original; KPIs de boletas sí se agregaron en ticket-029. |
| 9 | Tiendas | `/tiendas` | B | **Fiel** | Sin cambios. |
| 10 | Usuarios | `/usuarios` | B | **Fiel / Mejorada** | Falta campo "Razón Social Bipay/Anypay" — pendiente (baja, no confirmado si vive en otra pantalla). |
| 11 | Personal | `/agentes` | B | **Fiel** (corregido) | Columna NOMBRES solo mostraba nombre de pila — **corregido en a5bee3f (ola de fixes 1)**. |
| 12 | Asistencias — Gestión | `/asistencias` | B | **Fiel** (corregido) | Faltaba "Monitor de Fraude de Dispositivos" — **corregido en 93cbb1c (ticket-030)**, ver `MonitorFraudePanel.tsx`. |
| 13 | Asistencias — Control mensual | `/asistencias/control` | B | **Fiel** | Sin cambios. |
| 14 | Asistencias — Liquidación | `/asistencias/liquidacion` | B | **Fiel** | Sin cambios. |
| 15 | Revisar fotos | `/revisar-fotos` | B | **Fiel** | Sin cambios. |
| 16 | Planilla | `/planilla` | B | **Fiel** | Columna "CARGO" no confirmada en viewport — pendiente de confirmar (baja, posible falso negativo). |
| 17 | Tickets | `/tickets` | B | **Fiel / Mejorada** | Sin búsqueda exacta por N° Ticket ni columna "Cajero" separada — pendiente (baja). |
| 18 | Comisiones | `/comisiones` | C | **Fiel** | Sin cambios. |
| 19 | Comisiones Empresa | Fusionada en `/comisiones` | C | **Fiel** (corregido) | 2 modales → **ahora 2 secciones siempre visibles con color-coding y banners**, corregido en f18dfb6 (ticket-029, `GananciasOperativasSection`/`EstrategiaComisionesSection`). Confirmado en código: ya no son modales. |
| 20 | Financieras | `/financieras` | C | **Fiel** | Sin cambios. |
| 21 | Reporte BCP | `/reporte-bcp` | C | **Parcial** | `undefined` en KPI — **corregido en a5bee3f**. Sigue pendiente: tabla plana vs. agrupación jerárquica fecha+tienda+turno (baja-media, no abordado). |
| 22 | Bipay/Anypay | `/panel-bipay` | C | **Fiel** (corregido) | El `warning` ya no reemplaza toda la página — **corregido en 18da642 (ola de fixes 2)**. |
| 23 | Postpago/Churn | `/postpago` | C | **Parcial** | Buena fidelidad, sin cambios pendientes de código. |
| 24 | Traslados | `/traslados` | C | **Mejorada** | Sin cambios. |
| 25 | Mapa de Calor | `/mapa-calor` | D1 | **Fiel** (corregido) | Faltaba `/v1/` en las 3 llamadas — **corregido en a5bee3f**. |
| 26 | Onboarding RRHH (postulación pública) | `/postular` | D1 | **Mejorada** | Sin cambios. |
| 27 | Postulantes (admin) | `/admin/postulaciones` | D1 | **Mejorada** | Sin cambios. |
| 28 | Ingreso Stock | `/inventario` (modal) | D1 | **Parcial** (corregido a medias) | Ya **no exige precio al crear el ítem** — **corregido en 93cbb1c (ticket-030)**, precios ahora opcionales en `InventarioForm.tsx`. Sigue pendiente: no existe como pantalla/menú propio (sigue siendo modal dentro de Ver Inventario, sin entrada en `AppLayout.tsx`). |
| 29 | Ver Inventario | `/inventario` | D1 | **Parcial** | Falta franja de 6 KPI cards (capital invertido) — pendiente (baja, polish). |
| 30 | Matriz Inventario | `/inventario/matriz` | D1 | **Fiel** (corregido) | 500 por `REGEXP`/`CAST` MySQL-only contra SQLite — **corregido en 18da642 (ola de fixes 2)**, con test nuevo `MatrizInventarioTest.php`. |
| 31 | Bitácora Stock | `/bitacora-stock` | D1 | **Fiel** | Sin cambios. |
| 32 | QR Asistencia | `/asistencias/qr` | D1 | **Fiel** (corregido) | QR en blanco para admin sin `tienda_id` — **corregido en 18da642 (ola de fixes 2)**, ahora con selector de tienda. |
| 33 | Terminal Asistencia | `/terminal` | D2 | **Fiel** (corregido) | Retematizada enteramente en rojo — **corregido en ce9a2f0 (ticket-028)**, ahora dorado kyro. |
| 34 | Perfil de Empresa | `/configuracion` | D2 | **Fiel** | Sin cambios. |
| 35 | Facturación Electrónica | `/configuracion/facturacion` | D2 | **Mejorada** | Sin cambios. |
| 36 | Comprobantes | `/comprobantes` | D2 | **Fiel** | Icono duplicado con Facturación — pendiente (baja, polish). |
| 37 | Integrador Bipay | `/integrador` | D2 | **Fiel** | Sin cambios. |
| 38 | Clientes (extra) | `/clientes` | D2 | **Mejorada** | Sin cambios. |
| 39 | Gestión de Chips (extra) | `/chips-gestion` | D2 | **Mejorada** | Sin cambios. |
| 40 | Kardex de Inventario (extra) | `/inventario/kardex` | D2 | **Mejorada** | Sin cambios. |
| 41 | Diagnóstico del Sistema (extra) | `/diagnostico` | D2 | **Mejorada** | Sin cambios. |
| 42 | Cuadre (Reporte Diario / Editar Reporte) | `/reportes/nuevo`, `/reportes/:id/editar` | D2 (re-QA) | **Fiel** | Post ticket-020, confirmado en vivo campo por campo — referencia de calidad del port. |

**Conteo de veredictos finales (tras aplicar correcciones verificadas en código):**
Fiel: 25 · Mejorada: 9 · Parcial: 5 · Degradada: 0 · Genérica: 0 · Faltante: 0

Ninguna pantalla queda en **degradada**, **genérica** o **faltante** — las 3 que estaban
marcadas "degradada" en las pasadas originales (Mapa de Calor, Bipay/Anypay, QR Asistencia,
Terminal Asistencia) fueron corregidas por las olas de fixes y los tickets polish 028-030.

---

## 2. Hallazgos por severidad — estado tras verificación en código

### Ya corregidos (verificado contra `main`, no requieren más acción)

| Hallazgo original | Pantalla | Commit que lo corrigió | Verificación |
|---|---|---|---|
| URLs sin prefijo `/v1/` → 404 permanente en las 3 pestañas | Mapa de Calor | `a5bee3f` | `MapaCalorPage.tsx:78,243,403` ya usan `/v1/heatmap/...` |
| `Invalid Date` en columna FECHA | Mi Reporte Personal | `a5bee3f` | `MiHistorialPage.tsx` usa `.slice(0,10)` antes de `new Date()` |
| KPI "Total Operaciones: undefined" | Reporte BCP | `a5bee3f` | `ReporteBcpController.php` incluye `total_operaciones` en el payload de warning |
| Columna NOMBRES sin apellidos | Personal | `a5bee3f` | `types/agente.ts` declara `apellidos`; `AgentesPage.tsx` los concatena |
| QR en blanco para admin sin `tienda_id` | QR Asistencia | `18da642` | `QrDisplayPage.tsx` agrega selector de tienda (+36 líneas) |
| `warning` reemplazaba toda la página | Bipay/Anypay | `18da642` | `PanelBipayPage.tsx` ya no hace `return` temprano, banner superior |
| 500 por `REGEXP`/`CAST` MySQL-only contra SQLite | Matriz Inventario | `18da642` | `MatrizInventarioController.php` reescrito + `MatrizInventarioTest.php` nuevo |
| Terminal Asistencia enteramente en rojo | Terminal Asistencia | `ce9a2f0` (ticket-028) | `TerminalAsistenciaPage.tsx` retematizado a dorado kyro |
| Resaltado activo de CRM sin púrpura `#c084fc` | CRM | `ce9a2f0` (ticket-028) | `AppLayout.tsx` + `CrmPage.tsx` actualizados |
| Iconos duplicados varios (mencionados en polish) | Varias | `ce9a2f0` (ticket-028) | `PageTabs.tsx`, `ComprobantesPage.tsx` actualizados |
| Falta columna "Ganancia" por fila + badge de estado | Historial admin | `f18dfb6` (ticket-029) | `HistorialController.php` + `HistorialPage.tsx` actualizados, test `HistorialGananciaPorFilaTest.php` |
| 2 modales → se pierde color-coding y banners explicativos | Comisiones Empresa | `f18dfb6` (ticket-029) | `ComisionesPage.tsx`: `GananciasOperativasSection`/`EstrategiaComisionesSection` ahora **siempre visibles** (no modal), con color-coding bipay/krece/payjoy y banners |
| Faltaba panel de liquidación/boletas (KPIs Sueldo Base/Bonos/Descuentos/Adelantos) | Ver Agente | `f18dfb6` (ticket-029) | `VerAgentePage.tsx` `BoletasPanel` ahora trae 4 KPIs del mes en curso |
| Falta "Monitor de Fraude de Dispositivos" | Asistencias — Gestión | `93cbb1c` (ticket-030) | Nuevo `MonitorFraudePanel.tsx` + endpoint en `AsistenciaController.php`, test `FraudeDispositivosTest.php` |
| Ingreso Stock pedía precio obligatorio al crear ítem | Ingreso Stock | `93cbb1c` (ticket-030) | `InventarioForm.tsx`: precios ahora `z.number().optional()`, test `InventarioTiendaRegistraStockTest.php` |

### Hallazgo corregido en el propio proceso de consolidación (no estaba en ningún QA parcial)

**Ver Agente — "Ficha de Registro de Datos (HR)" y "Contactos de Emergencia" NO faltaban.**
El Bloque A QA (informe original) reportó estas dos secciones como ausentes ("faltan
estructuralmente"). Verificado en `VerAgentePage.tsx` (líneas 297-401): el componente
`PerfilRrhhEditor` con sección "Ficha RRHH" (borde violeta) y "Contactos emergencia" (borde
naranja, ícono `ContactPhone`) **ya existían en el commit `669f325`**, que es anterior a la
propia sesión de QA (`4366446`) — confirmado con `git merge-base --is-ancestor`. El gap real
que sí existía (panel de liquidación/boletas con KPIs) fue corregido en `f18dfb6`. Ambas
secciones RRHH usan un editor de texto plano (`nombre | parentesco | telefono` por línea) en
vez de campos individuales como el legacy — eso sí podría considerarse una deuda de UX menor,
pero no es el gap "sección faltante" que reportó el QA original.

### Pendiente real (verificado que sigue sin corregir en el código actual)

| Severidad | Pantalla | Archivo/componente | Desviación |
|---|---|---|---|
| Media-Baja | Reporte BCP | `frontend/src/pages/bcp/ReporteBcpPage.tsx` | Tabla plana vs. agrupación jerárquica fecha+tienda+turno (con fila resumen colapsable) que tiene el legacy. |
| Media (decisión de producto) | Ingreso Stock | `frontend/src/components/AppLayout.tsx` + `frontend/src/pages/inventario/InventarioForm.tsx` | Sigue sin existir como pantalla/entrada de menú propia — el ingreso vive solo como modal dentro de Ver Inventario. El precio ya es opcional (corregido), pero el flujo de 2 pasos del legacy (tienda ingresa → gerencia fija precio como acto separado) todavía no tiene una vista dedicada tipo "Gestión de Ingresos a Tienda". |
| Baja | Productividad | `frontend/src/pages/estadisticas/EstadisticasPage.tsx` | Tab inicial por defecto "Por Tienda" en vez de "Resumen" (la vista con charts Recharts debería ser la de entrada). |
| Baja | Ver Inventario | `frontend/src/pages/inventario/InventarioPage.tsx` | Falta franja de 6 KPI cards (capital invertido Equipos/Accesorios/Chips + totales) que el legacy muestra al abrir. |
| Baja | Usuarios | `UsuariosPage.tsx` (modal "Nuevo usuario") | Falta campo "Razón Social Bipay/Anypay" (no confirmado si vive en otra pantalla, ej. Tiendas). |
| Baja | Tickets | `frontend/src/pages/tickets/TicketsPage.tsx` | Sin búsqueda exacta por "N° Ticket" ni columna "Cajero" separada de "Vendedor". |
| Baja | Comprobantes / Facturación Electrónica | `frontend/src/components/AppLayout.tsx:56,71` | Icono `Receipt` duplicado entre ambas entradas de menú adyacentes. |
| Baja (a confirmar) | Planilla | `frontend/src/pages/planilla/PlanillaPage.tsx` | Columna "CARGO" no confirmada en viewport visible en la pasada original — puede ser solo scroll horizontal, no necesariamente un gap real. Ningún commit posterior tocó este archivo; sigue sin verificar. |
| Baja (a confirmar manualmente) | Comisiones Empresa | `RangosOperativosModal`/`EstrategiaComisionesSection` en `ComisionesPage.tsx` | Inputs de BIPAY/KRECE/PAYJOY se vieron en blanco en captura headless de Playwright pese a tener el valor correcto en el DOM — posible artefacto del entorno de captura, no reproducido como bug de datos real. |

---

## 3. Lista priorizada de lo que queda pendiente

1. **Ingreso Stock sin pantalla/menú propio** (media, decisión de producto) — el precio ya
   quedó opcional, pero falta decidir si se justifica una vista dedicada tipo legacy
   ("Gestión de Ingresos a Tienda") con selector de 3 tarjetas y UX de scanner de IMEI, o si
   el modal actual dentro de Ver Inventario es aceptable como diseño final.
2. **Reporte BCP: tabla plana vs. agrupación jerárquica** (media-baja) — pérdida real de la
   forma en que gerencia lee los datos (comparar turnos del mismo día/tienda de un vistazo).
3. Resto de items "baja": tab inicial de Productividad, franja de KPIs en Ver Inventario,
   campo Razón Social Bipay en Usuarios, búsqueda por N° Ticket en Tickets, icono duplicado
   Comprobantes/Facturación, columna CARGO en Planilla (a confirmar), inputs en blanco de
   Comisiones Empresa en captura headless (a confirmar manualmente, no bug de datos).

Todos los ítems "baja" son candidatos naturales a agruparse en un ticket de polish único, tal
como ya se hizo con los tickets 028-030 para los hallazgos previos de mayor severidad.
