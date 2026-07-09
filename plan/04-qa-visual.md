# TICKET-026 — QA visual en vivo, pantalla por pantalla

Metodología: backend (SQLite + `QaDemoSeeder`) y frontend levantados en local (ver
`plan/04-qa-visual-setup.md`), navegados con Playwright temporal en 1440×900, sesión
`admin@qa.test`. Cada pantalla se comparó contra su captura FireShot legacy cuando se pudo
identificar cuál de las 33 le correspondía, y contra las notas de `00-inventario-diseno.md`
§3 en los demás casos (la mayoría de las 33 capturas no traen nombre de pantalla en el
archivo — identificarlas todas una por una no entraba en el presupuesto de este bloque).
La pantalla de **cuadre** (Reporte Diario / Editar Reporte) sigue **excluida**: pendiente de
QA post-020, no se toca aquí aunque haya capturas legacy de ella (`legacy_04`, `legacy_05`).

Leyenda: **fiel** / **mejorada** / **degradada** / **genérica** / **faltante** / **parcial**.

## Bloque A (8 pantallas) — COMPLETO

| # | Pantalla | Ruta refactor | Comparado contra | Veredicto | Notas |
|---|---|---|---|---|---|
| 1 | Login | `/login` | Criterio de diseño (sin FireShot de `auth/` identificado entre las 33) | **Mejorada** | Confirma inventario: `public-premium-shell`, gradiente índigo/dorado, icono shield, tipografía Orbitron en "SIS-KYRO". Sin deviaciones nuevas detectadas. |
| 2 | Dashboard | `/` | `legacy_01.png` (match directo) | **Fiel** | Casi 1:1: mismas 4 KPI cards con borde de color (azul/cyan/verde/gris), bloque "Dinero digital del período" (Yape morado / Bipay azul / Transferencia verde), banner "Ganancia total" verde, tabla "Últimos Reportes" con las mismas columnas, botón "Ver Historial de Movimientos". Única diferencia real: el logo (caja dorada con icono `Users` genérico vs. isotipo propio de Vitaltel) — ya cubierto en inventario §4.1, no es específico de esta pantalla. |
| 3 | Productividad | `/estadisticas` | Notas de inventario (Recharts, iconos) — sin FireShot identificado | **Parcial → confirmado en vivo** | Carga y funciona: KPIs (Total Ventas, Postpago, Prepago/Chips, Eq. Cuotas, Eq. Contado, Accesorios) + 4 tabs (Resumen/Por Tienda/Top Productos/Ranking Agentes) + tabla de ranking con medallas 🥇🥈. Deviación menor: el tab activo por defecto es "Por Tienda", no "Resumen" (que debería ser la vista de entrada con los charts Recharts) — revisar estado inicial en `EstadisticasPage`. |
| 4 | CRM | `/crm` | Notas de inventario — sin FireShot identificado | **Parcial → confirmado en vivo** | 4 tabs (Tabla/Pipeline Kanban/Temperatura/Analytics) + KPIs coloreados (Leads azul, Conversión verde, Convertidos verde, Perdidos rojo, Interacciones morado) + gráfico de embudo (barras horizontales) + gráfico de canal de captación + tendencia diaria. **Deviación confirmada** (ya apuntada en inventario): en el sidebar, "CRM y Marketing" se resalta en dorado/gris al estar activo, no en el púrpura `#c084fc` que usa el legacy para esta sección. |
| 5 | Precios | `/revisar-stock` | `FireShot Capture 007...png` (match directo) | **Fiel / Mejorada** | Legacy: lista larga agrupada por tienda con chips de tipo (PUNDA/ACCESORIO) y botón "Fijar" por fila. Refactor replica el agrupado-por-tienda + chip de tipo + botón "Fijar" casi igual en la pestaña **"Matriz completa"**, y además separa una pestaña **"Pendientes"** que en legacy no existe como vista propia (era todo junto) — mejora real de usabilidad, no pérdida de identidad. Terminología: legacy usa "PUNDA" como nombre de tipo, refactor usa "EQUIPO" (dominio distinto, no es bug). |
| 6 | Historial admin | `/historial` | `legacy_02.png` (match directo) | **Parcial** | Muy cercano: mismas 4 KPI cards + bloque Yape/Bipay/Transferencia + tabla paginada. **Deviación confirmada:** legacy muestra columna "Ganancia" por fila y un badge de color en "Estado Efectivo" (`En Tienda` dorado / `Entregado` verde); refactor solo muestra la ganancia agregada en el KPI superior y usa dos columnas separadas sin el mismo tratamiento visual — "DESTINO" (badge naranja "TIENDA") y "ESTADO" (texto plano gris "borrador", sin badge de color). Ya estaba apuntado en inventario que usa `confirm()` nativo al eliminar (no se volvió a probar en vivo esta pasada). |
| 7 | Mi Reporte Personal | `/mi-historial` | Notas de inventario (`mi_historial.php`) | **Parcial — con bug nuevo** | Carga con datos reales (KPIs Total Vendido / Diferencia Acumulada / Reportes con Descuadre, filtros por estado, tabla paginada) y el panel "Salvavidas — recuperar tardanza" sí está presente. **Bug nuevo encontrado en vivo:** la columna FECHA de la tabla muestra literalmente `Invalid Date` en todas las filas — el parseo de fecha del endpoint no coincide con lo que espera el componente de tabla. No estaba documentado en el inventario (ese solo cubría el gap ya conocido de T1.2 "jefe de tienda" sin UI, que sigue igual). |
| 8 | Ver Agente | `/agentes/:id` | `FireShot Capture 013...png` (match directo) | **Parcial — gap grande confirmado** | El header (card con borde superior cyan, avatar, badges ACTIVO/rol, botones Editar Ficha/Certificado/Dispositivo/Historial) es fiel. Pero **se confirma en vivo** que faltan estructuralmente dos secciones completas que sí existen en legacy: (a) **"Ficha de Registro de Datos (HR)"** (borde violeta: teléfono, correo, dirección, fecha/lugar nacimiento, grupo sanguíneo, alergias, AFP/CUSPP, antecedentes, carga familiar, formación académica, experiencia laboral) y (b) **"Contactos de Emergencia"** (borde naranja). Tampoco aparece el panel de **liquidación/boletas** de legacy (KPIs Sueldo Base/Bonos/Descuentos/Adelantos + "Liquidaciones Generadas" con botón "Nueva Boleta"); el refactor solo tiene un formulario de "Registrar Adelanto" suelto. Esto es el gap T2.5 ya identificado en el handoff — esta pasada lo pasa de "sin UI (handoff)" a **confirmado visualmente contra el legacy real**, con la lista exacta de lo que falta. |

### Resumen de desviaciones para ticket de fix

Ninguna pantalla del Bloque A queda "degradada" o "genérica" sin ticket asociado (regla del
ticket): las 3 con deviaciones reales (CRM, Historial admin, Mi Reporte Personal) son
"parcial", y Ver Agente es el único gap grande, ya cubierto por el handoff T2.5 existente.

| Severidad | Pantalla | Archivo/componente sugerido | Desviación |
|---|---|---|---|
| Alta (ya con ticket) | Ver Agente | `frontend/src/pages/.../VerAgentePage.tsx` | Faltan secciones "Ficha RRHH" + "Contactos de Emergencia" + panel de liquidación/boletas (T2.5, handoff existente — confirmado en vivo esta pasada) |
| Media (bug, no solo estético) | Mi Reporte Personal | `frontend/src/pages/.../MiHistorialPage.tsx` (columna FECHA de la tabla) | `Invalid Date` en la columna FECHA — parseo de fecha roto, no es solo un tema de fidelidad visual |
| Baja | Historial admin | `frontend/src/pages/.../HistorialPage.tsx` | Falta columna "Ganancia" por fila + badge de color en estado efectivo (legacy: dorado "En Tienda" / verde "Entregado"; refactor: texto plano) |
| Baja | CRM | `frontend/src/components/AppLayout.tsx` (entrada de menú "CRM y Marketing") | Color de resaltado activo no usa el púrpura `#c084fc` del legacy para esta sección |
| Baja (polish) | Productividad | `frontend/src/pages/.../EstadisticasPage.tsx` | Tab inicial por defecto es "Por Tienda" en vez de "Resumen" (la vista con los charts Recharts debería ser la de entrada) |

Las dos de severidad "baja" y la de "polish" son candidatas a agruparse en un ticket
"polish" único siguiendo el formato de la cola, **una vez que blocks B/C/D terminen** (para
no fragmentar el mismo ticket de polish en cuatro pasadas distintas). Las de severidad media
y alta ya tienen owner (T2.5 handoff) o ameritan su propio ticket de bug por ser
funcionales, no solo visuales.

## Bloque B — pendiente

Tiendas, Usuarios, Personal, Asistencias, Planilla, Tickets, Comisiones, Comisiones Empresa.

## Bloque C — pendiente

Financieras, Reporte BCP, Bipay/Anypay, Churn/Postpago, Mapa de Calor, Registro de Datos
RRHH (`public_onboarding`), Postulantes, Ingreso Stock, Ver Inventario.

## Bloque D — pendiente

Bitácora Stock, QR Asistencia, Terminal asistencia, Perfil de Empresa, Facturación
Electrónica, Comprobantes, Integrador Bipay, Traslados, y los extras del refactor sin
equivalente 1:1 (Clientes, Chips, Kardex, Diagnóstico, Reportes).

**Excluida de todos los bloques:** pantalla de cuadre (Reporte Diario / Editar Reporte) —
pendiente de QA post-020.
