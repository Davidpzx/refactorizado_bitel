# Plan 17 — Hallazgos de QA (informe 2026-07-14, Nasheli)

Fuente: `informe_pruebas_sistema.md` (raíz del repo), 20 hallazgos / 9 módulos.
Decisiones de alcance confirmadas con David antes de ejecutar:
- Item 13 (tipo de pago editable desde Ventas): **no se implementa**. La edición de forma de pago queda centralizada en Tickets (item 2) para no duplicar el punto de verdad del cuadre.
- Item 15 (bloquear descuentos sin efectivo en caja): se diseña en esta ronda aunque la feature "descuento" no existe todavía en el sistema refactorizado.
- Item 20: bloqueado, no se asigna (falta que Nasheli aclare qué Excel es).

Ejecución: Claude planifica archivo/línea exacto, Codex implementa vía `mcp__codex-cli__codex`, Claude revisa el diff. Cambios de 1-2 líneas los hace Claude directo.

---

## Grupo A — Cuadre de caja (crítico)

### A1 — Item 1 [URGENTE] Venta no se guarda si falta agente
- **Frontend** `frontend/src/pages/reportes/NuevoReportePage.tsx`: antes de permitir submit (o al intentar guardar), si `tienda_id` tiene valor y `agente_id` no, mostrar toast/alerta explícita "Debe seleccionar un agente" y no dejar avanzar. El modal `AgregarRegistroModal` (línea ~866-871) hoy solo deshabilita el botón sin mensaje — agregar el mismo aviso ahí.
- **Backend** `VentaController::store()` ya valida `vendedor_id required` (422) — no tocar, solo asegurar que el frontend muestre ese error si llega sin bloqueo previo.
- Criterio de aceptación: seleccionar tienda sin agente y presionar guardar muestra mensaje claro, no hay guardado silencioso vacío.

### A2 — Item 2 [URGENTE] "Editar ticket" = editar forma de pago
- **Frontend** `frontend/src/pages/tickets/TicketsPage.tsx`, componente `EditarTicketForm` (líneas 296-357) y título del diálogo (línea 524): renombrar a "Editar forma de pago del ticket #..." y agregar copy/aviso corto ("Esto puede afectar el cuadre de caja").
- Evaluar registrar auditoría del cambio (quién, cuándo, forma de pago anterior→nueva) — si no existe tabla de auditoría genérica, dejar como TODO explícito documentado, no bloquear el fix de UI por esto.
- **Backend** `TicketController::update()` (líneas 146-189): agregar log (Laravel `Log::info` o tabla `ticket_auditoria` si existe algo similar) del cambio de forma de pago.
- Criterio de aceptación: el diálogo deja claro que edita forma de pago; queda registro server-side del cambio.

### A3 — Item 14 [MEDIA] Gerencia asignable como vendedor
- **Backend** `ReporteController::vendedores()` (líneas 400-416): agregar `->where('es_gerencia', false)` (o el campo equivalente real — confirmar nombre de columna en tabla `agentes` antes de aplicar) al query.
- Criterio de aceptación: un agente con rol Gerencia no aparece en los selects de "Vendedor"/"Agente responsable".

---

## Grupo B — Mensajes de error en español

### B1 — Items 6 y 7 [MEDIA] Validación en inglés
- Causa raíz: `backend/config/app.php` locale `en`, sin `backend/lang/es/validation.php`.
- Crear `backend/lang/es/validation.php` (publicar traducción estándar de Laravel al español) y cambiar `APP_LOCALE=es` (o `'locale' => 'es'` en config, confirmar si se maneja por env).
- Verificar que esto no rompe mensajes ya customizados en español (ej. `StoreAgenteRequest`) — deben seguir funcionando igual, solo se traduce el fallback automático.
- Criterio de aceptación: registrar correo duplicado y código de tienda duplicado devuelven mensaje en español.

### B2 — Item 8 [MEDIA] Falta horario, error sin motivo
- Antes de asignar a Codex: reproducir en dev server creando un agente sin horario para capturar el payload 422 real (el código muestra `hora_ingreso`/`hora_salida` como `nullable` en request y `optional()` en frontend, por lo que el bloqueo real viene de otra validación — posible duplicado de nombre).
- Una vez identificada la validación real, aplicar el mismo patrón que B1: mensaje custom claro en `StoreAgenteRequest::messages()` y que `AgenteForm.tsx::aplicarErroresBackend()` lo muestre en vez del fallback genérico.
- Criterio de aceptación: crear agente con dato faltante muestra el motivo específico, no "no se puede".

---

## Grupo C — RRHH/Personal (requiere reproducir en runtime)

El código backend de estos tres puntos (perfil-rrhh, certificado, boleta PDF) luce correcto a nivel estático (rutas, controllers, vistas Blade existen). Antes de tocar código, reproducir en navegador con dev server levantado para capturar el error real (consola/network), porque el bug puede ser de permisos de middleware, CORS, o error de render Blade — no de lógica ausente.

### C1 — Item 4 "Guardar ficha" / "Generar certificado" no funcionan
- `frontend/src/pages/agentes/VerAgentePage.tsx` (`PerfilRrhhEditor` líneas 308-402, botón certificado 806-812)
- `backend/routes/api.php:287-288` (`middleware('role:admin')` — sospechoso: revisar si el rol real de quien probó es distinto de `admin` exacto)
- `ConstanciaController::agente()` + `constancias/agente.blade.php`

### C2 — Item 5 "Generar PDF" no genera archivo
- Mismo bloque que C1 + `BoletasPanel` (líneas 411-604) → `ConstanciaController::crearBoleta()`/`boleta()`.

### C3 — Item 9 "Exportar PDF constancia" en Planilla
- Revisar `frontend/src/pages/planilla/PlanillaPage.tsx` completo (no se ubicó el botón exacto en el mapeo inicial) antes de asignar.

---

## Grupo D — Asistencia

### D1 — Item 10 [MEDIA] "Editar asistencia" no completa el flujo
- `AsistenciaController::editar()` (líneas 1736-1835): candidato — bloqueo en líneas 1801-1809 (`minutos_refrigerio_asignado` solo permitido si `turno_extendido`), devuelve 422 fuera de ese caso.
- `AsistenciasPage.tsx` (mutation líneas 165-166, mensaje genérico 590-592) no muestra el motivo real del 422.
- Fix: (a) que el frontend muestre `error.response.data.message` en vez del genérico, (b) confirmar si la regla de refrigerio es intencional — si lo es, no es bug sino falta de mensaje; si no, ajustar la condición backend.

---

## Grupo E — Agentes

### E1 — Item 3 [ALTA] Re-registro pantalla negra
- Candidato: ruta pública `/postular` → `PostulacionPublicaPage.tsx` (tiene su propio `QueryClientProvider`, línea 513-533 — punto de sospecha típico de pantalla en blanco/negro: provider mal anidado o error no capturado antes del primer render).
- Reproducir primero en navegador (consola de errores) antes de asignar a Codex.

---

## Grupo F — Tickets / UI

### F1 — Item 11 [MEDIA] Excel no respeta filtros
- El código (`TicketsPage.tsx::exportarExcel()` + `TicketController::exportar()`/`baseQuery()`) ya aplica los mismos filtros en ambos lados — contradice el hallazgo tal como está escrito.
- Reproducir con un caso concreto (mismo filtro aplicado en pantalla y en la exportación) antes de tocar código. Posible causa real: `fetch()` directo en `exportarExcel()` (línea 436) no usa el cliente `api` con interceptores de auth — revisar si eso hace que el backend caiga a un scope distinto (ej. admin ve todo).

### F2 — Item 16 [MEDIA] Buscador no indexa Traslados
- `frontend/src/components/AppLayout.tsx`: agregar entrada de "Traslados" a `NAV_ITEMS` (falta actualmente) para que `GlobalSearch` la indexe. Confirmar si debe ser visible en sidebar normal o solo indexada para búsqueda (si es solo-admin como el link manual actual, replicar esa restricción de visibilidad).

### F3 — Items 18 y 19 [BAJA] Filtros en una fila + botón "Hoy"
- `TicketsPage.tsx` → `<ListToolbar>` (líneas 465-509): reorganizar en una fila (grid/flex-wrap consistente) y agregar botón "Hoy" que setee `desde=hasta=hoy`.

---

## Grupo G — Menores

### G1 — Item 12 [MEDIA] Boleta de reporte diario sin efectivo/vuelto por venta
- `backend/resources/views/constancias/reporte.blade.php`: hoy solo muestra el total agregado "Efectivo Entregado" (línea 57). Agregar columna/desglose por venta indicando si fue efectivo y el vuelto entregado (dato ya existe en el ticket individual, `TicketImpresionPage.tsx` líneas 174-188 — reutilizar esos campos).

### G2 — Item 17 [BAJA] DNI duplicado
- `StoreAgenteRequest.php` ya valida `unique:agentes,dni` en creación. Revisar `UpdateAgenteRequest.php` (no auditado) — si no repite la regla `unique` (con `ignore` del propio id), agregarla. Confirmar también si la columna `dni` en la tabla `agentes` tiene índice único real a nivel de motor (si no, agregar migración).

### G3 — Item 15 [MEDIA] Diseño: bloquear descuentos sin efectivo en caja — IMPLEMENTADO
No existía campo de descuento en venta. Implementación final (sin cambios de schema backend):
- `CarritoEquipoItem` (`NuevoReportePage.tsx`) gana un campo `descuento` (UI-only). El precio que el usuario tipea en "Precio S/" se trata como precio de lista; `precioFinalCarrito(item) = max(0, precio_venta - descuento)` es lo que realmente se envía al backend como `precio_venta` — cero cambios de contrato con `ReporteController::store()`, ganancia/comisión siguen calculándose igual porque ya usan `precio_venta` como precio real.
- Regla de bloqueo: se pasa `efectivoDisponible={efectivoEsperado}` (de `calcularCuadre()`) como prop a `AgregarRegistroModal`; el input de descuento se deshabilita y muestra "(sin efectivo en caja)" cuando `efectivoEsperado <= 0`.
- No se agregó trazabilidad/auditoría de descuentos aplicados (fuera de alcance, sugerencia de prioridad MEDIA — se puede añadir como columna `descuento_monto` en `ventas` si se pide en una ronda futura).

---

## Orden de ejecución sugerido

1. Grupo A (crítico, cuadre de caja)
2. Grupo B (rápido, alto valor — mensajes en español)
3. Grupo F2, F3, G1, G2 (bien definidos, bajo riesgo)
4. Grupo G3 (feature nueva, más grande)
5. Grupo D (requiere leer 1 archivo más para confirmar causa)
6. Grupos C, E, F1 (requieren reproducir en runtime antes de tocar código — se marcan como bloqueados hasta reproducir)

## Bloqueado / no asignado
- Item 20: pendiente de aclarar con Nasheli.

## Estado final (2026-07-14)
Implementados 17 de 19 items asignables (todo salvo el bloqueado #20 y confirmar en vivo #3/#4/#5/#9/#11, que quedaron con fix aplicado pero requieren QA visual manual):

- **Grupo A** (1, 2, 14): validación de agente en NuevoReportePage + ReporteController, "editar ticket"→"editar forma de pago" con log de auditoría, exclusión de Gerencia (`es_gerencia`) en `vendedores()`. Codex, verificado.
- **Grupo B** (6, 7): `backend/lang/es/validation.php` creado, `APP_LOCALE=es` ya estaba en `.env`. Implementado directo por Claude tras 3 fallos de Codex (contaminación de sesión — ver nota de infraestructura abajo).
- **Grupo C/D/E** (3, 4, 9, 10): postular sin `QueryClientProvider` duplicado + `ErrorBoundary`, middleware RRHH corregido a `role:administrador,gerente`, catch silencioso en Planilla corregido, payload de refrigerio en Asistencias corregido. Codex, verificado — falta QA visual con datos reales.
- **Grupo F/G** (12, 16, 17, 18, 19): buscador indexa Traslados, boleta de reporte diario con forma de pago/vuelto por venta, migración defensiva de índice único en `agentes.dni`, botón "Hoy" en Tickets. Implementado directo por Claude tras 2 fallos de Codex.
- **G3** (15): descuento en carrito de equipos con bloqueo cuando `efectivoEsperado <= 0`, sin cambios de schema backend. Implementado directo por Claude.
- **Item 11** (Excel no respeta filtros): revisado a fondo (rutas, `baseQuery()`, orden de registro de rutas) — el código ya filtra correctamente en ambos lados. No reproducible desde código estático; requiere QA en vivo con un caso concreto.
- **Item 13**: decisión confirmada con David — no se implementa, forma de pago queda centralizada en Tickets.

**Verificación:** `php artisan test` → 792 passed. `npx tsc -b` → limpio.

### Nota de infraestructura — MCP de Codex
Durante esta sesión, `mcp__codex-cli__codex` mostró contaminación de sesión: llamadas concurrentes (y a veces incluso secuenciales sin `resetSession: true`) devolvían resultados de una tarea distinta ya en curso en el mismo `workingDirectory`, o Codex respondía con un saludo genérico ignorando el prompt. Además, los modelos `gpt-5.3-codex`/`gpt-5.1-codex-max`/`gpt-5-codex` fallan con 400 en esta cuenta ChatGPT — solo funciona omitiendo `model` por completo. Recomendación para próximas sesiones: ejecutar Codex en **serie** (nunca en paralelo dentro del mismo repo), siempre con `resetSession: true`, nunca pasar `model`, y verificar cada resultado con `git diff` antes de aceptarlo.
