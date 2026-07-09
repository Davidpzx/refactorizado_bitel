# TICKET-026 — QA visual en vivo, Bloque B (8 pantallas)

Metodología: igual que Bloque A (ver `plan/04-qa-visual.md`) — backend (SQLite +
`QaDemoSeeder`) y frontend levantados en local (ver `plan/04-qa-visual-setup.md`),
navegados con Playwright temporal en 1440×900, sesión `admin@qa.test`.

**Ampliación de datos hecha en esta pasada:** el setup original (Bloque A) dejaba
explícitamente sin cubrir asistencias/planilla/tickets. Se amplió
`backend/database/seeders/QaDemoSeeder.php` (commit pendiente de este bloque) con:
13 días de asistencias para los 5 agentes (incluye una falta injustificada, dos
tardanzas, dos fotos pendientes de revisión con GPS/foto, un registro con
coordenadas GPS) y 10 tickets emitidos variados por forma de pago. Planilla no
necesitó datos propios: se calcula sobre las `ventas` ya sembradas en Bloque A.

Cada pantalla se comparó contra su FireShot legacy cuando se identificó cuál de
las 33 capturas le correspondía (esta pasada identificó 5 capturas nuevas:
`012`→Personal, `009`→Usuarios, `015`→Asistencias/Gestión, `016`→Planilla,
`017`→Tickets), y contra `00-inventario-diseno.md` §3 en los demás casos.

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

## Bloque B (8 pantallas) — COMPLETO

| # | Pantalla | Ruta refactor | Comparado contra | Veredicto | Notas |
|---|---|---|---|---|---|
| 1 | Tiendas | `/tiendas` | Notas de inventario (sin FireShot identificado) | **Fiel** | Tabla Código/Nombre/Dirección/Teléfono/Estado/Ubicación/Acciones, badge "Activa" verde, badge "Sin GPS" cuando no hay coordenadas. Carga y renderiza sin problemas con los 2 registros sembrados (T01/T02). Inventario apuntaba `confirm()` nativo en eliminar — no se volvió a probar en vivo (acción destructiva, fuera de alcance de un QA de solo-lectura). |
| 2 | Usuarios | `/usuarios` | `FireShot Capture 009...png` (match directo: "Gestión de Usuarios") | **Fiel / Mejorada** | Legacy arma alta/edición en un panel lateral fijo con "Razón Social Bipay/Anypay", "Formato de Ticket Térmico" y toggle "¿Tiene Agente BCP?"; refactor mueve lo mismo a un modal "Nuevo usuario" con dos secciones claras (Datos de la cuenta / Acceso y permisos) que **sí conserva** el checkbox "Módulo BCP" y el selector "Formato de ticket (impresión) 80mm" — paridad funcional confirmada en vivo. Única ausencia notada: el campo "Razón Social Bipay/Anypay" no aparece en el modal (podría estar en Tiendas u otro lugar; no se verificó). Tabla lista ID/Usuario/Rol/Tienda/Agente/Estado/BCP/Acciones con badges de rol coloreados (admin dorado, tienda azul, vendedor celeste) — más informativa que la tabla legacy (Código Bitel/Rol/BCP). |
| 3 | Personal | `/agentes` | `FireShot Capture 012...png` (match directo: "Administración de Personal") | **Parcial** | Tabla con DNI/Nombres/Tienda/Jornada y Descanso/Baja-Retorno/Sueldo/Estado/Ingreso + acciones (ver/editar/documento/pin/eliminar) — buena paridad funcional con el legacy (que agrega columna Refrigerio y PIN visible en texto plano, cosa que el refactor oculta correctamente por seguridad, mejora real). **Deviación:** la tabla del refactor muestra el nombre de pila solamente ("Ana", "Luis") en la columna NOMBRES en vez de "Nombre y Apellidos" completo como el legacy ("ABDÓN ALEX MAYTA CHINO"); con datos reales del seeder los apellidos existen (`apellidos` sembrado) pero no se están concatenando/mostrando en esa columna. Botones de cabecera "URL Registro de Datos", "URL Asistencia", "Excel + Fichas", "Nuevo agente" presentes y con buena paridad. |
| 4 | Asistencias — Gestión | `/asistencias` | `FireShot Capture 015...png` (match directo: "Gestión de Asistencias") | **Parcial — gap confirmado** | KPIs Presentes/Ausentes/Tardanzas/Pend. Revisión + tabla Fecha/Día/Agente/Tienda/Ingreso/Salida/Método/Estado/Acciones, con badges de color por método (MANUAL/FOTO/GPS) y estado (OK verde / Revisión naranja) — funciona correctamente y es incluso más legible que el legacy (que usa una columna "BALANCE" con textos como "-20m (Tardanza)" o "NO MARCÓ SALIDA" en rojo). **Gap real confirmado en vivo:** el legacy incluye, en la misma pantalla, un bloque **"Monitor de Fraude de Dispositivos"** (tabla con Fecha/Hora, Agente que intentó marcar, DNI ingresado, DNI dueño real del celular, tienda del intento — 39 alertas en la captura legacy). El backend refactor sí tiene la tabla `log_fraude_dispositivo` y la escribe (`AsistenciaController`, `DispositivoController`), pero **no existe ninguna pantalla ni componente que la muestre** — es un gap funcional real de seguridad/auditoría, no solo estético, sin ticket previo identificado. También ausente: botón "Descargar PDF" del legacy (el refactor sí tiene "Exportar Excel" y agrega "Plantilla Neiry", que el legacy no tiene). |
| 5 | Asistencias — Control mensual | `/asistencias/control` | Notas de inventario (sin FireShot específico de esta sub-vista) | **Fiel** | Matriz agente×día agrupada por tienda, con leyenda de colores (OK/Tardanza-Falta/Feriado/Permiso/Medio tiempo/Descanso) y celdas clicables para alternar excepción de medio tiempo. Verificado en vivo con datos reales: muestra correctamente "FALTA" en rojo para el día sembrado como falta injustificada y "16m"/"20m" en ámbar para las tardanzas sembradas. Funciona tal cual se espera; refactor separa esto en un tab propio ("Control mensual") en vez de estar mezclado en la tabla principal como en legacy — mejora de organización, aceptable según el criterio ya fijado en inventario §3 para el conjunto de Asistencias. |
| 6 | Asistencias — Liquidación | `/asistencias/liquidacion` | Notas de inventario (sin FireShot específico) | **Fiel** | Pantalla nueva respecto al legacy tradicional (no hay vista equivalente 1:1 identificada entre las 33 capturas), pero implementa bien el propósito: selector Agente + Mes, tarjetas KPI (Tardanza total/Deuda acumulada/Comodines usados/Descuento total) y tabla día a día con Estado/Entrada/Salida/Tardanza/Deuda/Comodín/Turno/Descuento. Probada en vivo seleccionando "Ana (T01)" para julio 2026: refleja correctamente el día de falta injustificada (`FALTA_INJUSTIFICADA`, fila sin entrada/salida) entre los días regulares. Sin desviaciones detectadas. |
| 7 | Revisar fotos | `/revisar-fotos` | Notas de inventario (sin FireShot específico; sí existe legacy capture 028 de "QR Asistencia", pantalla distinta) | **Fiel** | Grid de tarjetas con foto de marcación, badge de método (FOTO), nombre/tienda/fecha-hora, enlace a Google Maps cuando hay GPS ("Sin coordenadas GPS" cuando no), y botones Aprobar/Anular. Probada en vivo con las 2 fotos pendientes sembradas (Rosa, dos fechas) — el contador del tab "Revisar fotos (2)" coincide exactamente con las tarjetas mostradas. Zoom de imagen al hacer clic funciona (overlay a pantalla completa). Sin desviaciones. |
| 8 | Planilla | `/planilla` | `FireShot Capture 016...png` (match directo: "Planilla CD08") | **Fiel** | Réplica muy cercana del legacy: mismos 7-8 chips KPI de cabecera (Agentes/Total Remun./Com. Planes/Com. Equipos/Com. Online/Descuentos/Adelantos/Total a Pagar) con los mismos colores por categoría, y tabla con Agente/Tienda/Estado/Sueldo Base/Días/Sueldo×Días/Comisiones (Jefe/Equipo/Planes/Online)/Total Remun./Retención/Faltas/Tardanzas y fila de TOTALES. Verificado en vivo: los montos de comisión de equipo calculados coinciden con las ventas EQUIPO sembradas por agente (S/66.70, 90.60, 128.40, etc. — fallback S/5/equipo aplicado correctamente). Únicas ausencias menores frente al legacy: el toggle "Auto-sistema / Manual" y el botón "Comisiones automáticas activas" del header legacy no tienen equivalente visible (podrían no ser necesarios si el refactor no requiere ese modo dual). Columna "CARGO" del legacy (JEFE DE TIENDA/AGENTE DE VENTAS) no visible en el viewport capturado pero sí existe en el payload (`cargo`), por lo que probablemente esté más a la derecha en scroll horizontal — no se confirmó, posible falso negativo. |
| 9 | Tickets | `/tickets` | `FireShot Capture 017...png` (match directo: "Tickets Emitidos") | **Fiel / Mejorada** | Refactor agrega chips rápidos de filtro por forma de pago (Todas/Efectivo/Yape/Bipay/Plin/Transf/Mixto) que el legacy no tiene — mejora real de UX. Tabla Ticket#/Tienda/Vendedor/Cliente/Descripción/Monto/Forma de pago/Fecha/Acciones (editar/imprimir/eliminar) con badges de color por forma de pago, coincide en estructura y datos con lo sembrado (10 tickets, distintas formas de pago). **Deviaciones menores:** (a) el legacy distingue columnas "Cajero" y "Vendedor" por separado (el refactor las fusiona en una sola "Vendedor"); (b) el legacy tiene búsqueda exacta por "N° Ticket" y un botón "Reset" explícito que el refactor no replica (solo tiene "Buscar descripción" y limpiar filtros implícito). Inventario ya apuntaba `window.confirm` nativo al anular — no se volvió a probar en vivo (acción destructiva). |

### Resumen de desviaciones para ticket de fix

| Severidad | Pantalla | Archivo/componente sugerido | Desviación |
|---|---|---|---|
| **Alta (gap nuevo, sin ticket previo)** | Asistencias — Gestión | `frontend/src/pages/asistencias/AsistenciasPage.tsx` (o nuevo componente) | Falta el bloque **"Monitor de Fraude de Dispositivos"** que sí existe en el legacy y sí tiene tabla poblada en el backend refactor (`log_fraude_dispositivo`, escrita por `AsistenciaController`/`DispositivoController`) pero sin ninguna UI que la muestre. Gap de seguridad/auditoría, no solo visual. |
| Media (bug de datos, no solo visual) | Personal | `frontend/src/pages/agentes/AgentesPage.tsx` (columna NOMBRES) | Solo se muestra el nombre de pila, no "Nombre y Apellidos" completo como en legacy, pese a que el dato `apellidos` existe en el modelo `Agente` y en el seed. |
| Baja | Usuarios | Modal "Nuevo usuario" / `UsuariosPage.tsx` | Falta el campo "Razón Social Bipay/Anypay" presente en el panel legacy (no confirmado si vive en otra pantalla, ej. Tiendas). |
| Baja | Tickets | `frontend/src/pages/tickets/TicketsPage.tsx` | Sin búsqueda exacta por "N° Ticket" ni columna "Cajero" separada de "Vendedor" (legacy las distingue). |
| Baja (a confirmar) | Planilla | `frontend/src/pages/planilla/PlanillaPage.tsx` | Columna "CARGO" no confirmada en viewport visible (puede requerir solo scroll horizontal, no es necesariamente un gap real). |

Ninguna pantalla del Bloque B queda "degradada" o "genérica" sin ticket asociado
(regla del ticket). El único hallazgo de severidad alta (Monitor de Fraude de
Dispositivos) es nuevo — no estaba en el handoff ni en inventario — y amerita
ticket propio dado que es funcional/seguridad, no solo estético. El resto son
candidatas al ticket "polish" único que se redactará cuando cierren los Bloques C y D.

## Entorno y datos — notas para el orquestador

- Se corrió `php artisan migrate:fresh --seed` + `db:seed --class=QaDemoSeeder`
  (versión ampliada) al iniciar esta pasada. **El seeder amplía la ya existente
  del Bloque A** — no la reemplaza; conviene commitear el `QaDemoSeeder.php`
  actualizado para que los Bloques C/D (que también corren en paralelo sobre el
  mismo repo/BD) puedan reusar los datos de asistencias/tickets si los necesitan.
- **Advertencia de concurrencia real detectada:** durante esta pasada,
  `frontend/.env.local` cambió sin intervención propia ("`.env.local changed,
  restarting server`" en el log de Vite) y una petición terminó resolviendo
  contra `http://localhost:8002` en vez de mi propio `:8000` — evidencia de que
  otro worker (Bloque C o D) está corriendo su propio `php artisan serve` sobre
  el mismo `database/database.sqlite` compartido y tocando el mismo
  `frontend/.env.local`. Las respuestas fueron consistentes (mismos IDs/datos
  sembrados), así que no hubo corrupción de datos, pero **cualquier bloque que
  corra `migrate:fresh` de nuevo mientras otro bloque sigue probando borraría
  los datos de ese bloque**. Recomendado para el orquestador: coordinar que
  `migrate:fresh --seed` se corra una sola vez al inicio y que los bloques
  restantes solo agreguen datos (`db:seed --class=...`) sin `--fresh`.
- Un par de capturas iniciales mostraron "Cargando…" indefinido (Tiendas,
  Asistencias) por arranque en frío del backend (primera consulta SQLite +
  posible colisión de puerto con el otro worker) — se re-verificaron con esperas
  más largas y cargaron con normalidad; no son bugs reales, están excluidas del
  resumen de desviaciones.

## Cleanup

Playwright temporal (`/tmp/qa026b_playwright`, con su propio `package.json`) y
capturas (`C:/tmp/qa026b_shots`, `C:/tmp/qa026b_shots2` — Node en Windows resuelve
`/tmp/...` a la raíz del drive actual, no a `AppData/Local/Temp`) se eliminan al
cierre de esta pasada, según regla del ticket. Servidores propios (`php artisan
serve --port=8000` y `npm run dev` de este worker) se detienen por PID al
terminar — no se tocan procesos de otros workers.
