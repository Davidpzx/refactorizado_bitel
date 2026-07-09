# TICKET-026 — QA visual en vivo, Bloque D1 (8 pantallas)

Metodología: mismo entorno documentado en `plan/04-qa-visual-setup.md` (backend SQLite +
`QaDemoSeeder`, login `admin@qa.test`/`password`), pero en **puertos alternos** para no
chocar con otros bloques corriendo en paralelo: **backend `:8002`**, **frontend `:5175`**.
Playwright temporal en 1440×900, instalado y removido igual que en el Bloque A.

**Gotcha nuevo (no estaba en el setup doc):** `backend/config/cors.php` tiene
`allowed_origins` hardcodeado a `5173`/`5174`/`3000` — el puerto `5175` no estaba permitido
y el login fallaba en silencio por CORS (bloqueado en preflight, sin mensaje claro en la UI).
Hubo que añadir `http://localhost:5175` temporalmente y reiniciar el backend. **Al cerrar
esta pasada se revirtió** (`git checkout -- backend/config/cors.php` habría bastado, pero el
archivo quedó con los 4 orígenes correctos porque otro worker en paralelo editaba el mismo
archivo al mismo tiempo — ver nota de colisión abajo). Si un bloque futuro usa un puerto
nuevo, anticipar este mismo problema.

**Colisión detectada con otro worker:** mientras yo tenía el backend arriba, otro proceso
sobrescribió `config/cors.php` y borró la línea `5174` que yo no había tocado (mi edición
solo agregaba `5175`). Se detectó por la nota automática de "archivo modificado externamente"
del harness y se corrigió reinsertando `5174` junto a mi `5175`. **Lección para la orquesta:**
`config/cors.php` es un archivo compartido de alto riesgo cuando varios workers de QA corren
en paralelo con puertos distintos — cada worker debería revisar el diff completo (no solo su
propia línea) antes de seguir, no asumir que su `Edit` fue la única en vuelo.

Identificación de capturas FireShot: de las 33 capturas legacy, se identificaron por
contenido (no venían nombradas) `FireShot Capture 025` = Ingreso Stock, `026` = Ver
Inventario, `027` = Bitácora Stock — además de las ya conocidas `024` = Postulación pública y
`028` = QR Asistencia. Para **Mapa de Calor**, **Postulantes (admin)** y **Matriz de
Inventario** no se encontró una captura FireShot identificable entre las 33 (se revisaron
manualmente 022, 023 y varias más buscando estas pantallas sin éxito) — se comparó contra las
notas de `00-inventario-diseno.md` §3 y contra el código legacy citado ahí.

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

## Bloque D1 (8 pantallas) — COMPLETO

| # | Pantalla | Ruta refactor | Comparado contra | Veredicto | Notas |
|---|---|---|---|---|---|
| 1 | Mapa de Calor | `/mapa-calor` | Notas de inventario (sin FireShot identificado) | **Degradada — bug bloqueante confirmado** | Las 3 pestañas (Calendario / Geográfico con mapa Leaflet real de Perú / Por Hora) renderizan el *shell* correctamente y el icono del sidebar ya está corregido (`MapPin`, fix de ticket-017), pero **ninguna de las 3 nunca carga datos**: las 3 llamadas (`/heatmap/calendario`, `/heatmap/geografico`, `/heatmap/horario`) devuelven 404 porque `MapaCalorPage.tsx` (líneas 78, 243, 403) las arma con `api.get('/heatmap/...')`, **sin el prefijo `/v1/`** que usan absolutamente todos los demás servicios del frontend (ej. `inventario.api.ts:7` usa `'/v1/inventario'`). Confirmado por código, no es artefacto del entorno QA — pasa igual contra producción. Bug adicional menor: warning de React "each child in a list should have a unique key" en `TabHorario`. |
| 2 | Onboarding RRHH (postulación pública) | `/postular` | `FireShot Capture 024` (match directo) | **Mejorada** | Formulario extenso y bien organizado: Datos personales, Sistema de pensión (ONP/AFP/Ninguno), Datos de salud (grupo sanguíneo, alergias), Antecedentes (penales/policial/judiciales), Carga familiar (repetible), Formación académica (repetible), Experiencia laboral (repetible), Contactos de emergencia (3), Documentos requeridos (foto + DNI). **Hallazgo cruzado importante:** estos son exactamente los mismos campos que el Bloque A marcó como faltantes en `VerAgentePage` (T2.5 — "Ficha de Registro de Datos HR" y "Contactos de Emergencia"). Esto confirma que el modelo de datos ya existe (se captura aquí, en la postulación); el gap de T2.5 es puramente de **visualización/edición** en la ficha del agente, no de datos ausentes — dato útil para quien tome ese ticket. |
| 3 | Postulantes (admin) | `/admin/postulaciones` | Notas de inventario ("badge dentro de Personal" en legacy) — sin FireShot identificado | **Mejorada** | Confirma el inventario: página propia + entrada de menú (vs. badge dentro de Personal en legacy). Filtros por estado (Todos/Pendiente/Entrevista/Aprobado/Rechazado), tabla con DNI/Nombres/Apellidos/Teléfono/Tienda postulada/Estado/Fecha, paginación. Estado vacío ("Sin resultados") es esperado — el `QaDemoSeeder` no siembra postulantes (documentado en el setup como no cubierto). |
| 4 | Ingreso Stock | `/inventario` (modal "+ Nuevo item") | `FireShot Capture 025` (match directo) | **Parcial — pierde identidad de flujo** | Legacy: página propia de menú ("Gestión de Ingresos a Tienda") con selector de 3 tarjetas grandes (Accesorio / Chip Bitel / Equipo), captura de IMEIs orientada a scanner (`Agregar otro IMEI`, contador vivo "1 equipo", Enter agrega línea), aviso explícito **"Los precios de venta serán asignados por gerencia tras el registro"** (separación de responsabilidades: la tienda ingresa stock sin precio, gerencia lo fija después desde Precios/Pendientes) y botón final "CONFIRMAR INGRESO AL STOCK". Refactor: no existe como pantalla/entrada de menú propia (confirma el gap ya anotado en el inventario) — solo un modal "Nuevo item de inventario" alcanzable desde Ver Inventario, con un `<select>` genérico para el tipo, un textarea plano para "IMEI/Series (uno por línea)" sin UX de scanner, y **pide Precio costo/mínimo/normal directamente al crear el ítem** — colapsa el flujo de 2 pasos del legacy (ingreso sin precio → fijar precio por gerencia) en 1 solo paso, cambiando quién puede fijar precio y cuándo. Es una desviación funcional, no solo estética. |
| 5 | Ver Inventario | `/inventario` | `FireShot Capture 026` (match directo) | **Parcial** | La tabla principal es fiel: mismas columnas (Producto/Tipo/IMEI-Serie/Tienda/Cant./Precio/Estado/Fecha), tabs Todos/Equipos/Accesorios/Chips, sección secundaria "Chips en Inventario", botones Exportar Excel / Bitácora Stock / Ver Matriz / Nuevo item. **Deviación confirmada:** legacy abre con una franja de 6 KPI cards (CAPITAL INVERTIDO — Equipos / Accesorios / Chips + TOTAL Equipos / Accesorios / Chips) que da contexto financiero inmediato; el refactor no tiene esa franja, va directo al toolbar y la tabla. |
| 6 | Matriz Inventario | `/inventario/matriz` | Notas de inventario ("extra del refactor, mejora") — sin FireShot identificado | **Mejorada, con bug de portabilidad confirmado** | Es un extra sin espejo 1:1 en legacy (correcto per inventario). Una vez se ve el contenido (ver nota), la UI es limpia: tabla cruzada Producto × Tienda con columna Total, tabs Equipos/Accesorios/Chips, exportar CSV. **Bug confirmado (con fix temporal local para poder verla, revertido después — no queda en el código):** `/inventario/matriz` devuelve **500** contra SQLite porque `MatrizInventarioController.php:66-67` arma un `CASE ... WHEN c.tienda_origen REGEXP '^[0-9]+$' THEN (SELECT ... CAST(c.tienda_origen AS UNSIGNED) ...)` — `REGEXP` y `CAST(...AS UNSIGNED)` son sintaxis MySQL-only; SQLite lanza `PDOException: no such function: REGEXP` al preparar la consulta (pasa aunque la tabla `inventario_chips` esté vacía, porque SQLite falla al *parsear*, no al *evaluar* filas). Contra MySQL de producción esto probablemente funciona, pero es SQL no portable y **hace imposible el QA local de esta pantalla en el entorno documentado** (SQLite) sin parchear. Recomendación: resolver el `codigo_origen` en PHP (loop simple) en vez de SQL crudo específico de motor. |
| 7 | Bitácora Stock | `/bitacora-stock` | `FireShot Capture 027` (match directo) | **Fiel** | Franja de KPIs (Total movimientos / Entradas / Salidas / Balance neto / Tiendas afectadas / Agentes involucrados) replica el patrón de stats del legacy (645 registros, +4,131, -1, +4,130, 10, 8 en la captura). Filtros Desde/Hasta/Tienda/Agente/Categoría/Buscar/Acción y columnas de tabla (Fecha/Hora, Tienda, Agente, Producto, Tipo, Acción, Cant., IMEI/Serie, Precio, DNI Autoriz., Motivo) cubren lo mismo que legacy. Vacío solo por falta de datos sembrados (documentado como no cubierto por `QaDemoSeeder`), no es un bug. |
| 8 | QR Asistencia | `/asistencias/qr` | `FireShot Capture 028` (match directo) | **Degradada — bug bloqueante confirmado para rol admin** | El *shell* es fiel: contador "QR EN VIVO" con temporizador de renovación, instrucciones numeradas "1 Entra al DNI / 2 Ingresa PIN / 3 Escanea QR", URL de terminal para agentes. **Pero el recuadro del QR queda permanentemente en blanco** para cualquier usuario admin/gerencia: `QrDisplayPage.tsx:60` usa `const tiendaId = usuario?.tienda_id ?? 'DEFAULT'`, y como el admin no tiene `tienda_id` (es `null`), pide `/attendance/qr-stream/DEFAULT`, que el backend siempre devuelve **404** (`AsistenciaController::qrStream` → `buscarTienda('DEFAULT')` no encuentra ninguna tienda con ese código — confirmado leyendo el controlador). No hay selector de tienda como fallback para el admin. Cualquier usuario con `tienda_id` real sí debería funcionar (no se pudo probar con ese rol en esta pasada por alcance/tiempo). |

## Chequeos transversales (aplicados a las 8 pantallas)

- **`confirm()`/`window.confirm()` nativo:** no se encontró ninguno en las 8 pantallas de este
  bloque (`MapaCalorPage`, `PostulacionesPage`, `PostulacionPublicaPage`, `InventarioPage`,
  `InventarioForm`, `MatrizInventarioPage`, `BitacoraStockPage`, `QrDisplayPage`) — el
  reemplazo por `ConfirmDialog`/`useConfirmDialog` (mencionado como iniciativa global en el
  inventario) ya llegó a este bloque.
- **Iconos:** confirmado que la migración completa `lucide-react → @phosphor-icons/react`
  (commit `3b1f2e8`, ticket-018) y la corrección de mapeos del sidebar (commit `755c2c9`,
  ticket-017) ya están aplicadas — `Mapa de Calor` usa el icono de pin correcto, `QR
  Asistencia` un icono de QR, no hay duplicados evidentes en este bloque. El hallazgo de
  iconos del inventario original (`00-inventario-diseno.md` §4) está **desactualizado** en
  ese sentido: ya no aplica a lucide, el sistema corre sobre Phosphor.

## Resumen de desviaciones para ticket de fix

| Severidad | Pantalla | Archivo/componente sugerido | Desviación |
|---|---|---|---|
| **Alta (bug funcional)** | Mapa de Calor | `frontend/src/pages/analytics/MapaCalorPage.tsx` (líneas 78, 243, 403) | Las 3 llamadas a `/heatmap/...` faltan el prefijo `/v1/` → 404 permanente, la pantalla nunca muestra datos en ningún entorno. Fix: `'/v1/heatmap/calendario...'`, etc. |
| **Alta (bug funcional)** | QR Asistencia | `frontend/src/pages/asistencias/QrDisplayPage.tsx:60` | Fallback `usuario?.tienda_id ?? 'DEFAULT'` rompe siempre para admin/gerencia (sin tienda asignada) porque el backend no resuelve "DEFAULT" como tienda → QR nunca se pinta. Necesita selector de tienda para admin o manejo explícito de "sin tienda". |
| **Media (bug de portabilidad, no bloquea producción)** | Matriz Inventario | `backend/app/Http/Controllers/Api/MatrizInventarioController.php:56-72` | SQL crudo con `REGEXP` y `CAST(...AS UNSIGNED)` (MySQL-only) rompe 500 contra SQLite — impide QA/testing local de esta pantalla sin parchear. Reescribir la resolución de `codigo_origen` en PHP. |
| **Media (deviación funcional, no solo visual)** | Ingreso Stock | `frontend/src/pages/inventario/InventarioForm.tsx` + entrada de sidebar en `AppLayout.tsx` | No existe como pantalla/menú propio (solo modal dentro de Ver Inventario); además pide precios al crear el ítem en vez de diferir la fijación de precio a gerencia como hace el legacy — cambia el flujo de negocio, no solo el layout. |
| Baja | Ver Inventario | `frontend/src/pages/inventario/InventarioPage.tsx` | Falta la franja de 6 KPI cards (capital invertido + total por tipo) que el legacy muestra al abrir la pantalla. |
| Baja (polish, cosmético) | Mapa de Calor | `frontend/src/pages/analytics/MapaCalorPage.tsx` (`TabHorario`) | Warning de React por falta de `key` única en una lista — no afecta visualmente pero ensucia la consola. |

Las dos "Alta" son bugs funcionales confirmados por código y por comportamiento en vivo — no
son solo temas de fidelidad visual, ameritan ticket de bug propio (no agrupar en el polish
único). La de Matriz Inventario es de portabilidad/SQL, con impacto real solo si el entorno
de destino no es MySQL — igual amerita ticket propio por ser código no portable. Ingreso
Stock es una decisión de arquitectura de producto (colapsar el flujo en 2 pasos), no un bug,
pero cambia una regla de negocio del legacy (separación ingreso/precio) y debería decidirse
conscientemente, no quedar como efecto colateral de la implementación actual. La de Ver
Inventario es candidata al ticket "polish" único que se arme al cerrar todos los bloques.

## Entorno — estado al cerrar esta pasada

- Backend (`:8002`) y frontend (`:5175`) **detenidos** (solo PIDs propios, sin `taskkill /IM`).
- `frontend/.env.local` revertido a `http://localhost:8000/api` (valor documentado en el setup).
- `backend/config/cors.php` revertido a su estado original de 4 orígenes (sin `5175`).
- `backend/app/Http/Controllers/Api/MatrizInventarioController.php` revertido con
  `git checkout` tras usarlo solo para poder capturar la pantalla (ver hallazgo de Matriz).
- Playwright temporal (`/tmp/qa026d1_playwright`, `/tmp/qa026d1_shots`) — pendiente de borrar
  al final de esta pasada, según regla del ticket.
- Sin commits ni push realizados.
