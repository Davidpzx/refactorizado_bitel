# TICKET-026 — QA visual en vivo, Bloque D2 (9 pantallas) + re-QA de la pantalla de cuadre

Metodología: mismo entorno que los bloques anteriores (`plan/04-qa-visual-setup.md`) —
backend SQLite + `QaDemoSeeder` ya sembrado por un bloque previo (no se corrió
`--fresh`, se reusó la BD tal cual estaba), sesión `admin@qa.test`/`password`.
**Puertos por defecto** (`:8000` backend, `:5173` frontend): al arrancar esta pasada
ningún otro worker tenía servidores arriba (`netstat` sin listeners en 8000/5173/8001-8002/5174-5175),
así que no hizo falta tocar `backend/config/cors.php` ni ningún archivo compartido —
cero riesgo de colisión con otros workers que este bloque pudiera generar.

Playwright temporal instalado en `C:/Users/Usuario/AppData/Local/Temp/qa026d2_playwright`
(1440×900 para la mayoría de capturas; 1440×2800 para las 2 capturas altas del cuadre,
necesario porque el panel derecho "CUADRE FINAL" vive en un contenedor con su propio
alto, y `page.screenshot({fullPage:true})` no lo expande — hay que agrandar el viewport
para verlo completo). Todo removido al cierre de esta pasada.

**Gotcha nuevo (no estaba en el setup doc):** el patrón de navegación client-side
(`pushState`+`popstate`) que evita recargas duras falla específicamente al navegar a
`/reportes/:id/editar` con `page.evaluate()` — lanza `Execution context was destroyed,
most likely because of a navigation`, es decir esa ruta sí dispara una navegación real
del documento (o un remount que invalida el contexto) a diferencia de las demás rutas
de este bloque. Solución: para esa ruta puntual, usar `page.goto()` con
`waitUntil:'networkidle'` después de que la sesión ya esté autenticada (el token
persiste en `localStorage`, así que el hard-reload no pierde la sesión, solo tarda un
poco más por el bootstrap completo `control-center → configuración → tiendas/select`
descrito en el setup doc).

**Falso positivo detectado y corregido en el propio proceso de QA (anotado para que no
se repita el diagnóstico):** las primeras capturas de Facturación Electrónica,
Comprobantes, Clientes, Chips, Kardex y Diagnóstico salieron con "Cargando..." en el
cuerpo porque mi `waitLoaded()` (poll de `body.innerText` sin "Cargando" + pausa fija de
600ms) detectaba la desaparición del loader del *Suspense* (carga del chunk lazy) antes
de que apareciera el loader propio del componente (fetch de React Query), y la pausa
fija no alcanzaba para el segundo. Recapturado con espera fija de 3.5s tras la
navegación: **todas esas pantallas cargan correctamente**, no es un bug real. Se anota
como advertencia metodológica para bloques futuros: un solo chequeo de "Cargando" en el
body no es suficiente cuando hay Suspense + loading state anidados.

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

## Identificación de capturas legacy usadas

Las 33 capturas FireShot **no cubren** Terminal Asistencia, Perfil de Empresa,
Facturación Electrónica, Comprobantes ni Integrador (se revisaron una a una las que
faltaban por identificar — 002, 003, 005, 006, 007×2, 008, 014 — y corresponden a
Historial Completo, Detalle de Cuadre, Registrar Cuadre (modal borrador + completo),
Gestión de Precios, Gestión de Usuarios y Mi Historial/Seguimiento Operativo
respectivamente, ninguna de las 5 pantallas nuevas de este bloque). Para esas 5 se
comparó contra el **código legacy vivo** en `E:\laragon\www\sistema-rolando-salas`
(`asistencia.php`, `configuracion_empresa.php`, `configuracion_facturacion.php`,
`comprobantes_emitidos.php`, `configuracion_integrador.php`), igual que hicieron los
bloques B/C/D1 para las pantallas sin captura identificada.

Para el **cuadre** sí hay abundante material legacy: `004` (Editar Cuadre #0112),
`005`/`006` (Registrar Cuadre Diario, con y sin modal de borrador), `003` (Detalle de
Cuadre) — las 4 revisadas campo por campo contra las capturas en vivo del refactor.

## Tabla de veredictos — Bloque D2 (9 pantallas)

| # | Pantalla | Ruta refactor | Comparado contra | Veredicto | Notas |
|---|---|---|---|---|---|
| 1 | **Terminal Asistencia** | `/terminal` (pública) | `asistencia.php` (código, sin FireShot) | **Degradada — ruptura de identidad confirmada** | Ver hallazgo #1 abajo. El legacy tematiza esta pantalla en **dorado** (`--dasam-cyan:#ffc200`, logo Orbitron dorado, glass-panel con borde dorado, `btn-marcar` degradado dorado) igual que el resto del sistema; el refactor la retematiza **enteramente en rojo** (logo, borde de input, botón "Continuar", dots de PIN, marco de cámara/QR) — es la única pantalla del sistema que no usa la paleta oro/índigo/zinc de la identidad "Ultra Dark Premium". Funcionalmente el flujo (DNI → PIN si dispositivo nuevo → foto/QR) está completo y bien resuelto; el problema es 100% de color/identidad, pero es severo porque es una pantalla pública que ven todos los agentes a diario y el rojo connota "error/alerta" para una acción rutinaria. |
| 2 | Perfil de Empresa | `/configuracion` | `configuracion_empresa.php` (código, sin FireShot) | **Fiel** | Secciones "IDENTIDAD LEGAL" (razón social, RUC, nombre comercial, nombre del sistema), "REPRESENTANTE LEGAL" (gerente, DNI), "DATOS DE CONTACTO" (dirección, teléfono, correo), "LOGO DE LA EMPRESA" — cada una con icono semántico en caja dorada (edificio, persona, teléfono, imagen), botón "Guardar Cambios" dorado. Icono de sidebar ya corregido a `Building2` (coincide con la recomendación del inventario §4.1, ya aplicada). Sin `confirm()` nativo. |
| 3 | **Facturación Electrónica** (nueva, ticket-009) | `/configuracion/facturacion` | `configuracion_facturacion.php` (código, sin FireShot) | **Mejorada** | Wizard completo y bien resuelto para gerente no técnico: selector de alcance ("Configuración Global" / por tienda), badge de estado "MODO PRUEBA (beta)" / "No operativa", caja explicativa "¿Qué necesito para emitir facturas reales?" con 3 puntos en lenguaje llano (certificado digital, usuario/clave SOL, RUC/razón social), sección "Activar Facturación Real" (upload de certificado .pfx/.p12/.pem + contraseña + credenciales SOL), botón "Sincronizar logo con facturación", sección "Datos del emisor y series" (RUC, razón social) más abajo. Cumple el criterio de aceptación del ticket-009 (wizard no técnico, iconos semánticos, sin `confirm()` nativo). Gap real confirmado por inventario (§3, línea 131 "Sin ruta propia en App.tsx") queda **cerrado**. |
| 4 | **Comprobantes** (reescrita, ticket-010) | `/comprobantes` | `comprobantes_emitidos.php` (código, sin FireShot) | **Fiel — confirmado en vivo, ninguna acción probada por falta de datos** | Filtros Tienda/Desde/Hasta/Estado (Todos/Pendiente/Enviando/Aceptado/Error/Rechazado/Anulado)/Tipo (Boleta/Factura/N. Crédito) + "Limpiar", tabla Fecha/Tipo/Número/Cliente/Total/Estado/Tienda/Acciones. Confirmado por código (no hay comprobantes sembrados por `QaDemoSeeder`, tabla vacía "No hay comprobantes en la cola" — estado vacío esperado, no es bug) que **todas** las acciones del ticket-010 están implementadas: descargar PDF/XML/CDR (`FileDown`/`FileCode`), reintentar (`RotateCcw`), enviar link WhatsApp (`glassSuccess`), anular boleta con `ConfirmDialog` (no `window.confirm` — el texto de confirmación se arma dinámicamente con el número de comprobante). Criterio de aceptación del ticket cumplido a nivel de código; validar con datos reales en VPS/staging cuando existan comprobantes en cola. |
| 5 | Integrador Bipay | `/integrador` | `configuracion_integrador.php` (código, sin FireShot) | **Fiel** | Una tarjeta por tienda (T01 Real Plaza, T02 Mall Aventura) con "Usuario Bitel"/"Contraseña Bitel"/"Intervalo (min)" + botón "Guardar", badge de estado "Sin contacto" (ámbar). Icono `Plug`/`ph-plugs-connected` correcto. Inventario ya documentaba `window.confirm` al regenerar token — no se volvió a probar en vivo por ser acción sensible/destructiva, fuera de alcance de un QA de solo lectura. |
| 6 | Clientes (extra) | `/clientes` | Sin equivalente 1:1 en legacy (extra del refactor) | **Mejorada** | Tabla DNI/RUC, Nombre, Tipo, Teléfono, Registrado con datos reales (6 clientes sembrados), buscador por DNI/RUC/nombre, botón "+ Nuevo cliente", paginación. Limpia y funcional. |
| 7 | Gestión de Chips (extra) | `/chips-gestion` | Sin equivalente 1:1 en legacy (extra del refactor) | **Mejorada** | Tabla Tienda/Código Origen/Tipo/Stock/Acciones + botón "+ Agregar Stock". Estado vacío correcto ("Sin chips registrados" — `QaDemoSeeder` no siembra este dominio, documentado como no cubierto). |
| 8 | Kardex de Inventario (extra) | `/inventario/kardex` | Sin equivalente 1:1 en legacy (extra del refactor) | **Mejorada** | Filtro por tienda + tabs Todos/Disponible/Vendido/Traslado, botón "Exportar Excel". Estado vacío correcto ("Sin registros para los filtros seleccionados"). |
| 9 | Diagnóstico del Sistema (extra) | `/diagnostico` | Sin equivalente 1:1 en legacy (extra del refactor) | **Mejorada** | Panel técnico útil sin espejo legacy: tablas "Sesión Actual" (user_id/tienda_id/rol), "Tiendas" (con estado GPS/activo), "Usuarios" y "Chips", badge "Sin pendientes" verde arriba. Datos reales y correctos (2 tiendas, 3 usuarios, admin sin tienda_id como se espera). Buena herramienta de soporte interno. |

## Re-QA: pantalla de cuadre (NuevoReportePage / EditarReportePage, post ticket-020)

**Veredicto: Fiel — confirmado en vivo, la reestilización de ticket-020 llegó a producción tal como se documentó.**

Capturado `Registrar Cuadre Diario` (`/reportes/nuevo`) y `Editar Cuadre #80`
(`/reportes/80/editar`, con datos reales: 1 venta de equipo iPhone 13 S/104, caja
inicial S/100, Yape/Bipay/Transferencia sembrados) en viewport alto (1440×2800) para
ver el panel completo sin cortes.

Verificado campo por campo contra `004`/`005`/`006`/`003`:

- **5 secciones numeradas con color propio**, confirmado por código
  (`NuevoReportePage.tsx:36-42`, objeto `ACCENT`) y por el CSS inline del legacy
  (`reportes/nuevo_reporte.php:556-569`): **1 Postpago `#60a5fa` azul, 2 Prepago
  `#22d3ee` cian, 3 Equipos `#fbbf24` ámbar, 4 Otros Ingresos Fijos gris claro
  `#e2e8f0`, 5 Ventas de Apoyo `#a78bfa` púrpura** — los 5 valores hexadecimales
  **coinciden exactamente** entre legacy y refactor, no solo la intención de color.
  (Nota: la descripción de `00-inventario-diseno.md` línea 127/198 dice "2 verde" —
  es una imprecisión de esa nota; el código real de ambos sistemas usa cian, no
  verde. Se corrige aquí para que no se arrastre el dato equivocado.)
- **Panel CUADRE FINAL**: header con icono y texto "CUADRE FINAL" cuyo color cambia
  según estado (verde en el reporte nuevo sin diferencia; ámbar en el reporte #80 en
  edición, coherente con que ese reporte tiene una diferencia grande pendiente de
  aprobación) — comportamiento sensato, no es un bug.
- **"EFECTIVO ESPERADO"** en cian grande y en negativo cuando corresponde (el reporte
  #80 muestra `S/ -555.00` en rojo/cian según signo) — fiel al patrón legacy de
  "dinero esperado" destacado.
- **Botones "Lo Entregué / En Tienda"** presentes con estilo glass verde/outline,
  como el par legacy.
- **Banner "TOTAL SISTEMA (CONSOLIDADO)"** al pie del formulario, cian, con desglose
  Postpago/Prepago/Equipos/Fijos/Apoyo — presente y fiel en ambas pantallas.
- **Hallazgo funcional confirmado, no solo visual**: `EditarReportePage` muestra un
  banner ámbar **"Diferencia mayor a S/10 — el reporte quedará en espera de
  aprobación"** cuando la diferencia excede el umbral — replica correctamente el flujo
  de auditoría de ediciones del legacy (mencionado en el propio banner "MODO EDICIÓN"
  de la captura `004`). Buena señal de que la lógica de negocio, no solo el estilo, se
  preservó.
- Sin `window.confirm` nativo visible en el flujo de guardado/cierre de caja explorado
  (el ticket-020 pedía sustituirlo por `ConfirmDialog`; no se pudo forzar el submit
  real del cierre de caja en esta pasada de solo-QA para verlo disparar, pero no
  aparece en el código de los botones principales revisados).

**Conclusión:** el trabajo de ticket-020 (commit `d0b7e00`, "replica fiel de la
pantalla de cuadre del legacy") está confirmado en vivo. Esta pantalla pasa a ser,
junto con el Dashboard, referencia de calidad del port — no requiere más trabajo de
fidelidad visual.

## Hallazgos para ticket de fix / polish

### 1. Terminal Asistencia: toda la pantalla retematizada en rojo en vez del dorado del sistema
**Severidad: Alta (identidad de marca, pantalla de uso diario).**
Archivo: `frontend/src/pages/asistencias/TerminalAsistenciaPage.tsx` — líneas 120, 128,
160, 165, 168, 246-254, 262, 270, 272, 442-459 (patrón `red-500`/`red-600`/`border-red-500`
repetido en logo, input DNI, botón "Continuar", dots de PIN, marco de cámara, corner
brackets del QR, temporizador).

El legacy (`asistencia.php:25-47`) tematiza esta misma pantalla con la identidad
estándar del sistema: `--dasam-cyan:#ffc200` (dorado) para el logo Orbitron, el borde
del glass-panel, los inputs en foco y el botón principal (`btn-marcar`, degradado
dorado `#ffc200→#e5a800`). El refactor usa `bg-zinc-950` correcto para el fondo, pero
reemplazó **todos** los acentos por rojo. El resultado es la única pantalla de todo el
sistema (de las ~35 revisadas en los 4 bloques de este QA) que rompe la paleta
oro/índigo/zinc — y lo hace en un color que semánticamente el resto del sistema reserva
para peligro/eliminar/error (`kyro-danger`), lo cual puede confundir al agente que
marca su asistencia todos los días (¿pasa algo malo?).

**Sugerencia:** reemplazar `red-500`/`red-600`/`border-red-500` por los tokens dorados
existentes (`kyro-gold` / `#ffc200`, ya usados en el resto del sidebar y en
`variant="gold"` de `ui/button.tsx`) manteniendo el resto de la estructura (que
funcionalmente está completa: DNI → PIN de dispositivo nuevo → foto o QR).

### 2. Comprobantes y Facturación Electrónica comparten el mismo icono en el sidebar
**Severidad: Baja (polish, cosmético).**
Archivo: `frontend/src/components/AppLayout.tsx:56` (Comprobantes) y `:71` (Facturación
Electrónica) — ambas entradas usan `Icon: Receipt`.

El legacy diferencia `ph-receipt` (Facturación) de `ph-files` (Comprobantes) — ver
`00-inventario-diseno.md` §1.5. Al ser dos entradas adyacentes en la sección
CONFIGURACIÓN con el mismo ícono, se pierde la distinción visual rápida entre "cómo
emito" (Facturación) y "qué he emitido" (Comprobantes).

**Sugerencia:** dejar `Receipt` en Facturación Electrónica (ya es semánticamente
correcto) y cambiar Comprobantes a `Files`/`FileStack` (lucide) o
`@phosphor-icons/react` `Files` si ya se completó la migración a Phosphor mencionada en
Bloque D1.

## Resumen de severidades

| Severidad | Pantalla | Archivo/componente sugerido | Desviación |
|---|---|---|---|
| **Alta** | Terminal Asistencia | `frontend/src/pages/asistencias/TerminalAsistenciaPage.tsx` (patrón `red-*` repetido) | Toda la pantalla usa rojo en vez del dorado de identidad del sistema (y del propio legacy); es la única pantalla del sistema con esta ruptura, en una vista de uso diario por todos los agentes. |
| Baja | Comprobantes / Facturación Electrónica | `frontend/src/components/AppLayout.tsx:56,71` | Icono `Receipt` duplicado entre ambas entradas de menú adyacentes; legacy las distingue (`ph-receipt` vs `ph-files`). |

Ninguna pantalla de este bloque queda "genérica" o "faltante" sin hallazgo asociado. El
hallazgo de Terminal Asistencia es nuevo (no estaba en el inventario original ni en
handoffs previos porque nadie había comparado esta pantalla contra el legacy en vivo/
código hasta ahora) y amerita ticket propio por severidad — es puramente visual/
identidad, no rompe funcionalidad, pero es la desviación de marca más visible
encontrada en los 4 bloques de este QA porque contradice la paleta en una pantalla
pública de uso constante. El de iconos duplicados es candidato al ticket "polish" único.

## Entorno — estado al cerrar esta pasada

- Backend (`php artisan serve --port=8000`, PIDs 22304+24236) y frontend (`npm run dev`
  en `:5173`, PID 4424) **detenidos** por PID específico (`Stop-Process -Id ...`, sin
  `taskkill /IM`) — verificado con `curl` que ambos puertos ya no responden.
- No se tocó `backend/.env`, `backend/config/cors.php` ni
  `frontend/.env.local` — no hizo falta, se usaron los puertos por defecto porque
  estaban libres al iniciar esta pasada.
- `backend/database/database.sqlite` no se regeneró (`--fresh`) ni se modificó — se
  reusó tal cual la dejó el bloque anterior (5 agentes, 3 usuarios, reportes hasta
  ID 80, ventas hasta ID 120, 6 clientes).
- Playwright temporal (`C:/Users/Usuario/AppData/Local/Temp/qa026d2_playwright`,
  incluyendo su `shots/`) **eliminado por completo** al cierre de esta pasada.
- `git status` al cerrar muestra únicamente cambios de otros workers en paralelo
  (`MatrizInventarioController.php`, `ReporteBcpController.php`, `AgentesPage.tsx`,
  `MapaCalorPage.tsx`, `QrDisplayPage.tsx`, `ReporteBcpPage.tsx`, `PanelBipayPage.tsx`,
  `MiHistorialPage.tsx`, `agente.ts`, `plan/00-STATUS.md`, más un test nuevo de
  `MatrizInventarioTest.php`) — ninguno de estos archivos fue tocado por este bloque,
  se dejaron intactos según la instrucción de no auditar/no tocar pantallas que otros
  workers están arreglando en paralelo.
- Sin commits ni push realizados.
