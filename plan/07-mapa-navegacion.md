# 07 — Mapa de navegación: legacy (`header.php`) ↔ refactor (`AppLayout.NAV_ITEMS`)

> Generado para TICKET-043. Fuente de verdad legacy: `E:\laragon\www\sistema-rolando-salas\includes\header.php`.
> Fuente refactor: `frontend/src/components/AppLayout.tsx` (`NAV_ITEMS`) + `frontend/src/App.tsx` (rutas).
>
> **Nota de bloqueo (resuelta a mitad de pasada):** al iniciar este análisis, `DashboardPage.tsx`, `AsistenciasPage.tsx`
> e `InventarioPage.tsx` estaban modificados sin commitear (ticket-041, ver `git status`), así que la consolidación de
> Inventario quedó inicialmente marcada como bloqueada. Ticket-041 comiteó sus cambios (`493b52b`) durante esta misma
> sesión, liberando `InventarioPage.tsx` — la consolidación de Inventario (Traslados/Kardex/Gestión Chips) se completó
> en esta pasada una vez libre. `DashboardPage.tsx` y `AsistenciasPage.tsx` también quedaron libres, pero su
> consolidación (Dashboard/Historial/Mi Historial) se difiere deliberadamente: son páginas de cuadre de caja/reportes
> con acciones de dinero real (aprobar/denegar edición, eliminar reporte y revertir comisiones), y decidir la fusión
> correcta exige leer a fondo 3 archivos de 500-650 líneas cada uno y entender el alcance por rol — más riesgo y
> alcance del que cabe con margen de verificación en esta pasada. Ver sección 2.

## 1. Tabla de mapeo (por sección legacy)

Leyenda de acción: `OK` = ya 1:1, sin cambios · `FIX` = ajuste de metadata de nav (label/sección/rol), sin tocar páginas ·
`TAB` = consolidar como pestaña interna (patrón `PageTabs`/`AsistenciasTabs`, ticket-024) · `BLOQUEADO` = requiere editar
un archivo en edición por otro worker · `GAP` = falta en el refactor, no se implementa en esta pasada.

### Gerencia (admin) / Mi Panel (tienda)

| Legacy (`header.php`) | Ruta legacy | Refactor (`NAV_ITEMS`) | Acción |
|---|---|---|---|
| Dashboard (admin) | `/gerencia/panel_gerencia.php` | `/` "Dashboard" | Ver fila Historial abajo — **DIFERIDO** |
| Historial (tienda, mismo archivo PHP que Dashboard admin) | `/gerencia/panel_gerencia.php` | `/` "Dashboard" (label no cambia por rol) + `/historial` "Historial" (entrada separada) | **DIFERIDO** — el refactor separó en 2 rutas (`/` y `/historial`) lo que en el legacy es la MISMA página (`panel_gerencia.php`) relabeled por rol. `DashboardPage.tsx` (KPIs de cuadre del día, paridad confirmada por comentario interno "paridad legacy panel_gerencia.php") y `HistorialPage.tsx` (browser completo con filtros de fecha/tienda/agente/estado, aprobar/denegar edición, eliminar reporte) YA no están bloqueados (ticket-041 comiteó), pero fusionarlos con seguridad implica releer ambos a fondo y decidir si Dashboard debe re-etiquetarse "Historial" para tienda o si de verdad son 2 vistas distintas — riesgo alto por tocar reportes/comisiones reales. Se deja pendiente para una próxima pasada dedicada. |
| Productividad | `/gerencia/estadisticas_ventas.php` | `/estadisticas` "Productividad" | `OK` — ya 1:1 |
| CRM y Marketing | `/gerencia/crm_dashboard.php` | `/crm` "CRM y Marketing" | Ver fila Clientes abajo — `TAB` (aplicado) |
| Precios | `/gerencia/revisar_stock.php` | `/revisar-stock` "Precios" | `OK` — ya 1:1 |
| Mi Reporte Personal (tienda) | `/reportes/mi_historial.php` | `/mi-historial` "Mi Historial" | `OK` funcionalmente; label difiere del legacy ("Mi Historial" vs "Mi Reporte Personal") — cosmético, fuera de alcance de esta pasada (no arquitectura) |
| Reporte BCP (tienda, condicional a `usuario_tiene_bcp`) | `/gerencia/reporte_bcp.php` | `/reporte-bcp` "Reporte BCP" (roles `admin`+`tienda` sin condición) | `GAP` — el refactor muestra el ítem a **todo** usuario tienda; el legacy solo lo muestra si `usuarios.tiene_bcp = 1`. El campo `tiene_bcp` existe en el modelo/backend (usado en `UsuariosPage.tsx` para editar el flag) pero el endpoint `/me` (`types/auth.ts::Usuario`) no lo expone al usuario logueado. Requiere cambio de backend (exponer `tiene_bcp` en `/me`) — no es un problema de arquitectura de navegación, se documenta como pendiente separado. |

### Administración (solo admin)

| Legacy | Ruta legacy | Refactor | Acción |
|---|---|---|---|
| Tiendas | `/gerencia/tiendas.php` | `/tiendas` | `OK` |
| Usuarios | `/gerencia/usuarios.php` | `/usuarios` | `OK` |
| Personal (badge postulantes pendientes) | `/gerencia/gestionar_agentes.php` | `/agentes` "Personal" | Ver fila Postulantes abajo — `TAB` (aplicado) |
| Asistencias | `/gerencia/panel_asistencias.php` | `/asistencias` | `OK` — ya consolidado por ticket-024 (`AsistenciasTabs`: Gestión/Control mensual/Liquidación/Revisar fotos son tabs, no nav items) |
| Planilla | `/gerencia/planilla_agentes.php` | `/planilla` | `OK` |
| Tickets | `/gerencia/tickets_emitidos.php` | `/tickets` "Tickets" (sección Administración, roles admin+tienda) | `FIX` (aplicado) — ver nota Operaciones abajo, el legacy usa label+sección distintos por rol para la MISMA ruta |
| Comisiones | `/gerencia/configurar_comisiones.php` | `/comisiones` | `OK` |
| Comisiones Empresa | `/gerencia/comisiones_empresa.php` | *(sin nav propio)* | `OK` — ya consolidado dentro de `ComisionesPage.tsx` (comentario interno confirma "paridad legacy comisiones_empresa.php / configurar_comisiones.php"), ninguna acción necesaria |
| Financieras | `/gerencia/panel_financieras.php` | `/financieras` | `OK` |
| Reporte BCP | `/gerencia/reporte_bcp.php` | `/reporte-bcp` | `OK` |
| Bipay / Anypay | `/gerencia/panel_bipay.php` | `/panel-bipay` | `OK` |
| Churn / Postpago | `/gerencia/panel_postpago.php` | `/postpago` "Churn / Postpago" | `OK` |
| Mapa de Calor | `/gerencia/mapa_calor.php` | `/mapa-calor` | `OK` |
| Registro de Datos (link externo, `target=_blank`, admin) | `/public_onboarding.php` | *(sin entrada en NAV_ITEMS)* — ruta pública `/postular` (`PostulacionPublicaPage`) ya existe en `App.tsx` pero no está enlazada desde el sidebar | `FIX` (aplicado) — agregar entrada "Registro de Datos" → `/postular`, acento morado como legacy |
| *(sin equivalente legacy)* | — | `/admin/postulaciones` "Postulantes" | `TAB` (aplicado) — el legacy gestiona postulantes con modales DENTRO de `gestionar_agentes.php` (confirmado: la misma página consulta `postulantes_temp` y abre `modalVerPostulante`/`modalAprobarPostulante`/`modalRechazarPostulante`), no es una página propia. Se consolida como tab de "Personal". |
| *(sin equivalente legacy)* | — | `/diagnostico` "Diagnóstico" | `FIX` (aplicado) — no existe en `header.php` ni en ningún `.php` del legacy grepeado; se quita del sidebar. Ruta permanece viva (deep-link / acceso directo para debugging). |
| Comprobantes Emitidos *(vive en sección Configuración del legacy, no Administración)* | `/gerencia/comprobantes_emitidos.php` | `/comprobantes` "Comprobantes" (sección Administración) | `FIX` (aplicado) — mover de sección a "Configuración" para calcar agrupación legacy. Legacy lo mantiene como link separado de "Facturación Electrónica" (no los fusiona), así que NO se combinan pese al ejemplo tentativo del ticket — se corrige esa suposición. |

### Inventario (admin + tienda)

| Legacy | Ruta legacy | Refactor | Acción |
|---|---|---|---|
| Ingreso Stock | `/tienda/registrar_stock.php` | *(no existe ruta ni página equivalente)* | `GAP` — no se implementa en esta pasada (requeriría página nueva, no arquitectura de nav) |
| Ver Inventario (incluye internamente: filtro Equipos/Accesorios/Chips, modal Kardex, modal Gestión de Traslados, botón "Ver Matriz General") | `/tienda/ver_inventario.php` | `/inventario` "Ver Inventario" | Página madre — ver filas siguientes, `TAB` (aplicado) |
| *(sin equivalente legacy — botón "Ver Matriz General" abre `matriz_inventario.php`, que NO es entrada de sidebar)* | `/tienda/matriz_inventario.php` (drill-down, sin link en sidebar) | `/inventario/matriz` (ruta viva, **ya sin entrada en NAV_ITEMS**) | `OK` — ya consolidado en un ticket previo (confirmado: no aparece en `NAV_ITEMS` actual), ninguna acción |
| *(Kardex es un MODAL dentro de `ver_inventario.php`: `#modalHistorialKardex`, sin página propia)* | — | `/inventario/kardex` "Kardex" — antes entrada propia en el sidebar | `TAB` (aplicado) — se quitó de `NAV_ITEMS`; ahora se navega vía `InventarioTabs` (cabecera compartida en `InventarioPage.tsx`, `TrasladosPage.tsx`, `KardexInventarioPage.tsx`, `ChipsGestionPage.tsx`), tab visible solo para admin (paridad con el rol previo) |
| *(Chips es un FILTRO interno de `ver_inventario.php` — `filtro_tipo == 'CHIP'` en la misma página, no página aparte; confirmado además que `InventarioPage.tsx` YA tiene un filtro `tipo === 'CHIP'` propio vía `SegmentedToggle`, duplicando parte de lo que hace `ChipsGestionPage.tsx`)* | — | `/chips-gestion` "Gestión Chips" — antes entrada propia | `TAB` (aplicado) — se quitó de `NAV_ITEMS`, accesible vía `InventarioTabs` |
| *(Traslados son MODALES dentro de `ver_inventario.php`: `#modalTraslado`, `#modalTrasladoChip`, `#modalGestionTraslados`; aprobación también vive en el dropdown de campanita del header, no en una página)* | — | `/traslados` "Traslados" — antes entrada propia | `TAB` (aplicado) — se quitó de `NAV_ITEMS`, accesible vía `InventarioTabs`; badge de pendientes se movió del sidebar al tab "Traslados" |
| Bitácora Stock | `/gerencia/ver_bitacora_stock.php` | `/bitacora-stock` | `OK` |

### Operaciones

| Legacy | Ruta legacy | Refactor | Acción |
|---|---|---|---|
| Reporte Diario | `/reportes/nuevo_reporte.php` | `/reportes/nuevo` "Reporte Diario" | `OK` |
| Tickets Emitidos (SOLO tienda, sección Operaciones) | `/gerencia/tickets_emitidos.php` | `/tickets` — actualmente 1 sola entrada rol admin+tienda, sección Administración, label "Tickets" para ambos | `FIX` (aplicado) — separar en 2 entradas NAV_ITEMS misma ruta `/tickets`: admin→sección Administración/label "Tickets"; tienda→sección Operaciones/label "Tickets Emitidos", calcando el legacy exactamente |
| QR Asistencia | `/tienda/qr_asistencia.php` | `/asistencias/qr` | `OK` |

### Configuración (admin + tienda parcial)

| Legacy | Ruta legacy | Refactor | Acción |
|---|---|---|---|
| Perfil de Empresa (admin) | `/gerencia/configuracion_empresa.php` | `/configuracion` | `OK` |
| Facturación Electrónica (admin) | `/gerencia/configuracion_facturacion.php` | `/configuracion/facturacion` | `OK` |
| Comprobantes Emitidos (admin) | `/gerencia/comprobantes_emitidos.php` | `/comprobantes` | `FIX` — ver fila en Administración, se reubica aquí |
| Integrador Bipay (admin + tienda) | `/gerencia/configuracion_integrador.php` | `/integrador` | `OK` |

### Ítems del refactor sin ningún rastro en el legacy (candidatos a quitar o re-anclar)

| Refactor | Diagnóstico |
|---|---|
| `/clientes` "Clientes" | No hay página `clientes.php` en el legacy: la gestión de clientes vive dentro de `crm_dashboard.php` (drag&drop pipeline + AJAX `ajax_crm_clientes_filtrados.php`, `buscar_cliente_crm.php`, `ajax_historial_cliente.php`). Se consolida como tab de CRM. `TAB` (aplicado). |
| `/diagnostico` "Diagnóstico" | Sin rastro en `header.php` ni en el árbol de `.php` del legacy. Se quita del menú. `FIX` (aplicado). |

## 2. Resumen de acciones de esta pasada

**Aplicado (`AppLayout.tsx` + páginas de navegación, sin tocar `DashboardPage.tsx`/`AsistenciasPage.tsx`):**
1. `FIX` Tickets: separar entrada por rol (label/sección) calcando legacy.
2. `FIX` Diagnóstico: quitar del sidebar (ruta viva).
3. `FIX` Comprobantes: mover de sección Administración → Configuración.
4. `FIX` Registro de Datos: agregar entrada admin → `/postular` (acento morado, calca legacy).
5. `TAB` Personal ↔ Postulantes: `AgentesPage.tsx` y `PostulacionesPage.tsx` reciben cabecera de tabs compartida (`PersonalTabs.tsx`, patrón `AsistenciasTabs`); se quita "Postulantes" de `NAV_ITEMS`; el badge de pendientes se movió al ítem "Personal".
6. `TAB` CRM ↔ Clientes: se agrega tab "Clientes" al `PageTabs` interno de `CrmPage.tsx` (navega a `/clientes`); `ClientesPage.tsx` recibe cabecera simétrica de vuelta a CRM; se quita "Clientes" de `NAV_ITEMS`.
7. `TAB` Inventario ↔ Traslados ↔ Kardex ↔ Gestión Chips: cabecera compartida `InventarioTabs.tsx` (patrón `AsistenciasTabs`) insertada en las 4 páginas; se quitan las 3 entradas de `NAV_ITEMS` (solo queda "Ver Inventario" + "Bitácora Stock" en la sección Inventario, calcando el legacy); tab "Kardex" restringido a admin (paridad de rol); badge de traslados pendientes se movió del sidebar al tab "Traslados". Esta consolidación estaba inicialmente bloqueada (ver nota arriba) y se completó al liberarse `InventarioPage.tsx` a mitad de sesión.
8. Documentado sin cambio de código: Comisiones/Comisiones Empresa (ya consolidado), Matriz (ya consolidado), Estadísticas/Productividad/Mapa de Calor/Financieras/Bipay/Postpago (ya 1:1 — el ejemplo del ticket asumía consolidaciones que no aplican tras verificar contra el legacy).

**DIFERIDO (deliberado, no por bloqueo de archivo — ver nota al inicio):**
- **Dashboard / Historial / Mi Historial** — `DashboardPage.tsx` y `HistorialPage.tsx` ya están libres, pero fusionarlos toca reportes/comisiones reales (aprobar/denegar edición, eliminar reporte, reversión de comisiones). Requiere una pasada dedicada para leer ambos a fondo y decidir si Dashboard debe relabel-earse "Historial" para tienda o si son 2 vistas que deben coexistir con tabs. Sin cambios de código en esta pasada para no arriesgar una fusión mal verificada en una superficie financiera.

**GAP (fuera de alcance de arquitectura de navegación, requiere backend o página nueva):**
- Reporte BCP: visibilidad condicional a `usuarios.tiene_bcp` no implementada en frontend (falta exponer el flag en `/me`).
- Ingreso Stock (`registrar_stock.php`): sin página/ruta equivalente en el refactor.
- Label "Mi Historial" vs legacy "Mi Reporte Personal" (cosmético, no arquitectura).

## 3. Próxima pasada sugerida
1. Dashboard/Historial: decidir fusión real vs relabel condicional por rol, leyendo `DashboardPage.tsx` + `HistorialPage.tsx` + `MiHistorialPage.tsx` completos; validar con datos reales de cuadre antes de tocar código.
2. Backend: exponer `tiene_bcp` en `/me` para ocultar "Reporte BCP" a tienda sin el flag.
3. Opcional: página/ruta para "Ingreso Stock" si se decide dar paridad total con el legacy.

## 4. Verificación
- `npx tsc -b`: limpio.
- `npx vite build`: limpio (solo warnings preexistentes de React Compiler por `react-hook-form.watch()`, no relacionados a este ticket).
- `npx eslint` sobre los archivos tocados: 0 errores.
- Deep-links verificados por inspección: todas las rutas quitadas del sidebar (`/traslados`, `/inventario/kardex`, `/chips-gestion`, `/admin/postulaciones`, `/clientes`, `/diagnostico`) siguen registradas en `App.tsx` sin cambios — solo se tocó `NAV_ITEMS` y se agregaron cabeceras de tabs, no las rutas.
- Permisos por rol: `AdminRoute`/roles de `NAV_ITEMS` no se modificaron para ninguna ruta existente; el nuevo tab "Kardex" en `InventarioTabs` respeta el mismo gate (antes `roles: ['admin']`, ahora `adminOnly: true` en el componente de tabs).
