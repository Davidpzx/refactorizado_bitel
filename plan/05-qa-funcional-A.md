# 05 — QA funcional end-to-end · MITAD A (TICKET-027, flujos 1–3)

- **Ejecutado por:** worker Opus 4.8 · **Fecha:** 2026-07-09
- **Repo bajo prueba:** `C:\xampp\htdocs\refactorizado_bitel`
- **Oráculo de comportamiento:** legacy `E:\laragon\www\sistema-rolando-salas` (código fuente leído
  directamente, no solo `00-inventario-legacy.md` §4 — donde el doc y el código difieren, **manda el código**).
- **Cobertura:** flujos **1 (cuadre diario), 2 (edición aprobada), 3 (traslado + constancia)**.
  Los flujos 4–6 son de la mitad B (otro worker, en paralelo).

## Veredicto por flujo

| # | Flujo | Veredicto | Bugs |
|---|-------|-----------|------|
| 1 | Cuadre diario completo | ⚠️ **PASA CON DEFECTOS** — el núcleo (stock, chips, `observaciones` por destino, transaccionalidad, borrador) es correcto; fallan 2 controles server-side | 9 |
| 2 | Edición aprobada | ❌ **FALLA** — la reversión/re-aplicación de inventario es exacta, pero el ciclo *solicitar → aprobar → editar* es **inejecutable** por el rol que lo usa | 2 |
| 3 | Traslado con estados + constancia PDF | ⚠️ **PARCIAL** — la máquina de estados es 1:1 con el legacy; **la constancia PDF está rota** (500) en cualquier BD real | 2 |

**Total: 13 defectos** (4 de severidad alta). Ninguno fue corregido — abajo van como borradores de ticket.

## Entorno y método

Setup según `plan/04-qa-visual-setup.md`, con **aislamiento** para no pisar al worker de la mitad B:

- BD propia `backend/database/qa027a.sqlite` (vía `DB_DATABASE` en el entorno del proceso; Laravel
  usa `Dotenv` inmutable, así que la variable de entorno gana sobre `.env` y **no se tocó
  `database.sqlite`**, que es la compartida).
- Backend propio en **:8101** (`php artisan serve --port=8101`), PID 1684. Detenido al cerrar (§ Limpieza).
- Fixtures añadidos sobre `QaDemoSeeder` (que no siembra chips, IMEIs ni planes): 50 chips T01,
  IMEI en el equipo `inv 1`, y 2 filas en `comisiones_planes` (`Plan Max 39` postpago normal,
  `Plan Online 29` para probar la regla de plan online).

**Por qué sin Playwright.** Los 3 flujos se juzgan por *efectos en BD y reglas de negocio*, no por
píxeles: la UI no aporta señal adicional sobre reversión de inventario, guards de stock o
transacciones, y varios de los bugs encontrados (bypass de precio mínimo, `destino_efectivo`
arbitrario) **solo** se ven ejerciendo la API directamente, que es justo lo que un cliente malicioso
o un frontend con bug haría. Se ejerció la API HTTP real con `curl` + verificación directa en BD, más
la suite Feature existente como baseline. El ticket lo permite explícitamente ("el criterio es validar
COMPORTAMIENTO contra el legacy, no píxeles").

**Baseline de la suite existente:** `ReporteStoreParityTest`, `ReporteBorradorTest`,
`ReporteActualizarDestinoTest`, `ReporteDestroyAprobadoTest`, `InventarioRestaurarTest` →
**25 passed, 120 assertions**. Los defectos de abajo **no** los detecta ningún test actual; uno de
ellos (A3-01) está activamente enmascarado por los tests.

---

# FLUJO 1 — Cuadre diario completo

**Pasos ejecutados:** borrador autoguardado (×2, mismo día) → `GET` borrador → `POST /reportes` con
venta de EQUIPO (descuento de stock + IMEI) + línea POSTPAGO ×2 (descuento de chips) + `OTROS_FLUJO`
→ variante `destino_efectivo=ENTREGADO` y variante `EN_CAJA` → verificación en BD de stock,
chips, `observaciones`, borrador e historial → pruebas negativas (revender ítem agotado, duplicado,
precio bajo mínimo, chips insuficientes, plan online, ítem en `TRASLADO`, `destino_efectivo` inválido).

## Lo que SÍ está correcto (verificado en BD)

| Regla (legacy) | Esperado | Obtenido | ✓ |
|---|---|---|---|
| Borrador UPSERT atómico, 1 por agente+tienda+fecha (`ajax_guardar_borrador.php`) | 2º autoguardado actualiza, no duplica | `{"accion":"creado"}` → `{"accion":"actualizado"}`, 1 fila | ✓ |
| Descuento de stock de equipo + `VENDIDO` (`procesar_reporte.php:151-166`) | `inv1`: cantidad 1→0, `estado=VENDIDO`, `vendido_por_id`, `reporte_venta_id` | exactamente eso (`reporte_venta_id=81`) | ✓ |
| Descuento de chips postpago, 1 por activación (`:249-254`) | 50 → 48 con `cantidad=2` | `chips=48`, `ventas.chips_descontados=2` | ✓ |
| Comisión postpago = `(comision_dni_n − costo_chip) × cantidad` (`:220-223`) | `(25 − 1) × 2 = 48` | `comision_generada=48` | ✓ |
| `otros_flujo` → comisión siempre 0 (`:417-431`) | 0 | `comision_generada=0` | ✓ |
| `observaciones` según destino (`:25-29`) | ENTREGADO→texto de entrega; EN_CAJA→obs de cierre | ambos persistidos correctamente | ✓ |
| Guardado transaccional con rollback (`:482-487`) | revender ítem agotado → 422 y **cero** filas nuevas | `422 STOCK_GUARD`; sin reporte, sin ventas huérfanas, inventario intacto | ✓ |
| Fórmula del cuadre | `esperado = Σventas + fijos − no_físico − salidas` | `910` con `diferencia=0` | ✓ |

## Defectos

### 🔴 A1-01 · Precio mínimo no se valida server-side (bypass de control anti-fraude)

- **Legacy:** `reportes/procesar_reporte.php:96-101` — *"Validar precio mínimo (server-side anti-bypass)"*;
  si `precio_total < precio_minimo` lanza excepción y **aborta el cuadre**.
- **Refactor:** `backend/app/Http/Controllers/Api/ReporteController.php:883-928` (`procesarVentas`) —
  `precio_minimo` **no se lee nunca**. `grep -rn precio_minimo app/` solo aparece en
  `InventarioController` (al *fijar* precios), jamás al vender.
- **Obtenido:** vendí `inv 2` (Xiaomi Redmi 13, `precio_minimo=520`) a **S/ 100** → **HTTP 201**,
  `ganancia_snap = -380.00`.
- **Impacto:** cualquier usuario `tienda` puede vender bajo el mínimo con un request manipulado (o un
  frontend con bug). Es exactamente el control que el legacy etiqueta como *anti-bypass*.

### 🔴 A1-02 · El guard de stock no exige `estado='DISPONIBLE'`

- **Legacy:** `procesar_reporte.php:151-166` — `WHERE id=? AND tienda_id=? AND cantidad>0 AND estado='DISPONIBLE'`,
  y si `rowCount() !== 1` → *"El producto ya fue vendido, trasladado o no pertenece a esta tienda."*
- **Refactor:** `ReporteController.php:917-927` — el `WHERE` es solo `id + tienda_id + cantidad>0`.
  **Falta `estado='DISPONIBLE'`**, aunque el mensaje de error sigue diciendo "ya fue vendido, trasladado…".
- **Obtenido:** puse `inv 4` en `estado='TRASLADO'` (ítem en tránsito a otra tienda) y lo vendí → **HTTP 201**,
  cantidad 5→4, `estado` sigue `TRASLADO`.
- **Impacto:** un equipo ya despachado a otra tienda se puede vender desde la de origen. El
  traslado luego lo confirma en destino → **stock duplicado / venta fantasma**. Se combina mal con
  `revertirVentas` (`:1103`), que al revertir fuerza `estado='DISPONIBLE'` y "resucita" el ítem.

### 🟠 A1-03 · Nunca se registra la acción `crear` en `historial_reportes`

- **Legacy:** `procesar_reporte.php:434` — `INSERT INTO historial_reportes (…, 'crear')` dentro de la transacción.
- **Refactor:** `store()` hace `DB::commit()` (`ReporteController.php:557`) sin escribir historial. Las
  únicas acciones que existen son `cambio_destino`, `solicito_edicion`, `edicion_aprobada`,
  `edicion_rechazada`, `edicion_reporte`, `revocado`.
- **Obtenido:** tras crear el reporte 81, `historial_reportes WHERE reporte_id=81` → **0 filas**. En el
  flujo 2 el historial arranca en `solicito_edicion`: **no hay rastro de quién creó el cuadre**.
- **Impacto:** hueco de auditoría en el evento más importante del módulo.

### 🟠 A1-04 · Los planes "online" consumen chip y pierden S/1 de comisión

- **Legacy:** `es_plan_online` = `tipo_servicio != 'PREPAGO'` && el nombre contiene `"online"`
  (`procesar_reporte.php:184-185`). Se usa en dos sitios: `costo_insumo_chip = 0` (`:202`) y
  **no se descuenta chip** (`:240`). Igual para apoyos (`:326-327`, `:338`, `:365`).
- **Refactor:** el concepto **no existe**. `grep -rniE "es_plan_online|'online'" app/` → 0 resultados.
  - `ReporteController.php:947-949` — `consumeChip` solo excluye migración/upgrade/eSIM.
  - `ComisionService.php:56` — `$costoChip = ($esMigracion || $esUpgrade || $esEsim) ? 0.0 : COSTO_CHIP;`
- **Obtenido:** vendí `Plan Online 29` (`comision_dni_n=18`) → chips **48 → 47** (legacy: sin cambio) y
  `comision_generada=17` (legacy: **18**).
- **Impacto:** inventario de chips descuadrado y S/1 menos de comisión al agente por cada plan online.

### 🟠 A1-05 · La limpieza del borrador usa `now()` y `$user->tienda_id` (borra el borrador equivocado)

- **Legacy:** `procesar_reporte.php:438-443` — `DELETE … WHERE agente_id=? AND tienda_id=? AND fecha=?`
  usando **`$fecha_reporte`** (la fecha del cuadre) y la tienda de la sesión.
- **Refactor:** `ReporteController.php:559-565` — la condición es
  `whereDate('fecha', now(...)->toDateString())` (¡hoy, no la fecha del reporte!) y todo el bloque va
  dentro de `if ($user && $user->tienda_id)`.
- **Obtenido (dos fallos distintos):**
  1. Guardé un cuadre con `fecha=2026-07-08` teniendo un borrador de **hoy** → se borró el borrador de
     **hoy** (el que el operador tenía a medias), y el de la fecha del cuadre quedaría huérfano.
  2. El **admin** (que tiene `tienda_id = null`) guardó un cuadre de agente 2 / T01 → el borrador
     **sobrevivió** (1 fila). El legacy lo habría limpiado.
- **Impacto:** pérdida de trabajo en curso del operador (caso 1) y borradores zombis (caso 2).

### 🟠 A1-06 · Chips insuficientes abortan el cuadre completo (el legacy no aborta)

- **Legacy:** el descuento de chips es **best-effort**: `GREATEST(0, stock_actual - ?)` y, si no afecta
  filas, solo `error_log("[BTL-STOCK] Sin stock de chip propio…")` — el cuadre **se guarda igual**
  (`procesar_reporte.php:244-254`). Solo los **equipos** abortan.
- **Refactor:** `ReporteController.php:1006-1010` (`descontarChips`) lanza `RuntimeException`
  *"Stock de chips insuficiente…"* → 422 y rollback de todo.
- **Obtenido:** con `stock_actual=0`, una línea postpago normal devuelve
  `422 {"code":"STOCK_GUARD","error":"Stock de chips insuficiente para T01: se requieren 1 unidades."}`
- **Nota:** esto es un *endurecimiento*, no un descuido, y `00-inventario-legacy.md` §4 ("revierte si es
  insuficiente") lo respalda a medias — pero esa frase describe **equipos**. En producción implica que una
  tienda con el inventario de chips desactualizado **no puede cerrar caja**. Requiere decisión de producto,
  no un fix ciego.

### 🟠 A1-07 · Guard anti-duplicado eliminado sin registrar la decisión

- **Legacy:** `procesar_reporte.php:39-48` — *"GUARDIA ANTI-DUPLICADO (Server-Side)"*: si ya existe un
  reporte para `agente_id + tienda_id + fecha`, redirige con `?error=duplicate`. Concuerda con
  `00-inventario-legacy.md` §4: *"un solo cuadre por agente+tienda+fecha"*.
- **Refactor:** `ReporteController.php:491` — comentario: *"Guard de duplicados eliminado: se permiten
  múltiples cuadres por día (cerrar caja y abrir nueva)."* Introducido en el commit `36b0e22`
  ("fix: nuevo reporte 3.0"); `ReporteStoreParityTest` fija la nueva conducta
  (*"store permite varios cuadres el mismo dia"*).
- **Obtenido:** 3er cuadre del mismo agente/tienda/fecha → **201**.
- **Nota:** el motivo ("cerrar caja y abrir nueva") es plausible, pero **ningún documento del plan lo
  registra** y contradice el oráculo §4. Debe ratificarse o revertirse, no quedar implícito en un comentario.

### 🟡 A1-08 · `observaciones` obligatorio con `ENTREGADO` — el asterisco miente

- **Legacy:** `reportes/nuevo_reporte.php:4433` — `document.getElementById('obs_cuadre_entregado').required = esEntregado;`
- **Refactor:** el label muestra `*` (`frontend/src/pages/reportes/NuevoReportePage.tsx:2176`) pero el
  esquema es `observaciones: z.string().optional().or(z.literal(''))`
  (`NuevoReportePage.tsx:128`) y el backend valida `'observaciones' => 'nullable|string'`
  (`ReporteController.php:435`).
- **Obtenido:** `destino_efectivo=ENTREGADO` sin `observaciones` → **201**, `observaciones=null`
  (reporte 85). Se pierde el rastro de a quién se entregó el efectivo.

### 🟡 A1-09 · `destino_efectivo` sin enum server-side

- **Refactor:** `ReporteController.php:437` → `'destino_efectivo' => 'nullable|string|max:50'`.
- **Obtenido:** `destino_efectivo: "PIZZA"` → **201**, persistido tal cual (reporte 86).
- **Detalle adicional:** el default del backend es `'TIENDA'` (`:550`) pero el legacy usa `'ENTREGADO'`
  (`procesar_reporte.php:20`), y el front maneja **tres** valores (`TIENDA|ENTREGADO|EN_CAJA`,
  `NuevoReportePage.tsx:127`) mientras el legacy solo emite dos (`ENTREGADO|EN_CAJA`). `TIENDA` es un
  valor fantasma que solo aparece como fallback de render.

---

# FLUJO 2 — Edición aprobada (reversión de inventario)

**Pasos ejecutados:** crear reporte (equipo `inv 9` + postpago ×2 = 2 chips) → marcar `enviado` →
`solicitar-edicion` (tienda) → `aprobar-edicion` (admin) → `reprocesar` **como tienda** → `reprocesar`
**como admin** quitando la línea postpago → `reprocesar` quitando también el equipo → auditoría.

## Lo que SÍ está correcto (verificado en BD)

| Regla (legacy) | Esperado | Obtenido | ✓ |
|---|---|---|---|
| Solicitud → `SOLICITADO` + `motivo` + historial (`solicitar_edicion.php:50-54`) | estado y auditoría | `estado_edicion=SOLICITADO`, `solicito_edicion` en historial | ✓ |
| Aprobación admin → `APROBADO` + historial (`aprobar_edicion.php:21-25`) | idem | `APROBADO`, `edicion_aprobada` | ✓ |
| Re-solicitar sobre `APROBADO` se bloquea (`solicitar_edicion.php:45-48`) | rechazo | 422 (por otra guarda, pero el efecto es el correcto) | ✓ |
| **Reversión de chips** (`procesar_edicion.php:98-102`) | quitar la línea postpago devuelve 2 chips | 48 → **50** | ✓ |
| **Reversión de equipo** (`procesar_edicion.php:88-97`) | quitar el equipo devuelve la unidad | `inv9` 4 → **5**, `estado=DISPONIBLE` | ✓ |
| Re-aplicación tras revertir | equipo revertido (+1) y re-vendido (−1) → neto 4 | `inv9=4`, `ventas=1` | ✓ |
| Cierre de edición (`procesar_edicion.php:346`) | `estado_edicion='CERRADO'` | `CERRADO` | ✓ |
| `edicion_critica` / `edicion_restaurada` (`procesar_edicion.php:363-374`) | detección de cambio de vendedor | implementado (`ReporteController.php:1254-1265`, `esRestauracionComision`) | ✓ |

La reversión + re-aplicación —la parte difícil y la que el ticket señala— **es fiel al legacy**.

## Defectos

### 🔴 A2-01 · `reprocesar` es admin-only: la edición aprobada es inejecutable por quien la pidió

- **Legacy:** `reportes/procesar_edicion.php:49-54` — si `rol !== 'admin'`, exige
  `tienda_id` propia **y** `estado_edicion === 'APROBADO'`, y entonces **procede**. Ése es el sentido
  entero del ciclo: la tienda pide, el admin autoriza, **la tienda edita**.
- **Refactor:** `ReporteController.php:1125` → `abort_unless($request->user()->rol === 'admin', 403);`
  El `estado_edicion=APROBADO` no habilita a nadie: solo el admin puede reprocesar, y él ya podía.
- **Obtenido:** usuario `tienda` con `estado_edicion=APROBADO` sobre su propio reporte →
  **HTTP 403**. El reporte queda en `borrador`/`APROBADO`, en un limbo del que solo el admin lo saca.
- **Impacto:** el flujo de negocio completo (solicitar → aprobar → editar) **no se puede completar** por
  el rol que lo usa. `aprobar-edicion` no tiene efecto útil para la tienda.

### 🟠 A2-02 · No se audita el retiro de equipos de un cuadre (`edicion_equipo_eliminado`)

- **Legacy:** `procesar_edicion.php:376-386` — al quitar equipos de un reporte inserta
  `edicion_equipo_eliminado` con el detalle de los ítems (nombre + IMEI), el motivo y **la identidad de
  quien lo autorizó** (`agente_verificado_id`, `agente_verificado_dni`; columnas auto-migradas en `:27-41`).
- **Refactor:** `grep -rn "edicion_equipo_eliminado" app/` → **0 resultados**. Las columnas
  `agente_verificado_id` / `agente_verificado_dni` **no existen** en `historial_reportes`
  (columnas reales: `id, reporte_id, usuario_id, accion, detalle, snapshot_antes, snapshot_despues, created_at, updated_at`).
- **Obtenido:** reprocesé quitando el equipo `Poco X7 Pro` (inv9 4→5) → el historial solo registra
  `edicion_reporte`. No queda constancia de **qué** equipo salió del cuadre ni **quién** lo autorizó.
- **Impacto:** es un control **anti-fraude** del legacy (retirar un equipo de un cuadre borra la venta y
  devuelve stock). Su ausencia deja el movimiento sin trazabilidad.

---

# FLUJO 3 — Traslado con estados + constancia PDF

**Pasos ejecutados:** solicitud (tienda, no gerente) → rechazo (admin) → nueva solicitud → confirmar sin
aprobar → aprobar → confirmar desde tienda origen → confirmar desde tienda destino (usuario T02 real,
`auth_dni` de agente activo) → nueva solicitud → aprobar → cancelar → cancelar un `CONFIRMADO` →
constancia PDF.

## Lo que SÍ está correcto (verificado en BD)

| Regla (legacy) | Esperado | Obtenido | ✓ |
|---|---|---|---|
| Tienda no-gerente crea en `PENDIENTE_APROBACION` (`procesar_traslado.php`) | estado inicial | `PENDIENTE_APROBACION`, `enviado_por_id=2`, `enviado_dni` | ✓ |
| Traslado **parcial** divide la fila (`procesar_traslado.php:198-218`) | origen `cantidad−1`; fila nueva en `TRASLADO` | `inv3: 2→1 DISPONIBLE`, `inv11: 1 TRASLADO` — idéntico | ✓ |
| Rechazo solo desde `PENDIENTE_APROBACION`, devuelve stock (`gestionar_…:79-107`) | `RECHAZADO` + `DISPONIBLE` | exactamente eso | ✓ |
| Aprobar `PENDIENTE_APROBACION → PENDIENTE` (`:63-66`) | idem | `PENDIENTE` | ✓ |
| Confirmar exige `PENDIENTE` | confirmar sin aprobar falla | 422 *"no encontrado o ya fue procesado"* | ✓ |
| Solo la tienda destino confirma (`confirmar_traslado_equipo.php:75-79`) | origen no puede | 422 *"Solo la tienda destino puede confirmar"* | ✓ |
| Confirmación: borra origen, inserta/fusiona en destino, snapshot (`confirmar_…:104-130`) | `inv11` borrado; `Cargador` en T02 | `inv11` no existe; `inv12 T02 cant=1 DISPONIBLE`; `CONFIRMADO`, `confirmado_por_id=3`, `confirmado_dni`, `observacion_recepcion` | ✓ |
| Cancelar solo `PENDIENTE`/`PENDIENTE_APROBACION` (`gestionar_…:84`) | cancelar un `CONFIRMADO` falla | 422 *"Solo se pueden cancelar traslados activos"* | ✓ |

La máquina de estados de traslados es **1:1 con el legacy**, incluida la división de lotes parciales.

## Defectos

### 🔴 A3-01 · `ConstanciaController` consulta la tabla `configuraciones`, que **no existe** → todos los PDF dan 500

- **Refactor:** `ConstanciaController.php:98, 127, 156, 252` → `DB::table('configuraciones')->first();`
  (sin guarda). Pero **ninguna migración crea esa tabla**: `grep -rn "Schema::create('configuraciones'" database/` → 0.
  La migración `2026_07_09_000002_create_configuracion_empresa_table.php:27` crea **`configuracion_empresa`**,
  que es además el nombre del legacy (`00-inventario-legacy.md` §2 línea 92: *"★ `configuracion_empresa` —
  identidad de la empresa"*, y `gerencia/configuracion_empresa.php`). `ConstanciaController` **nunca**
  nombra `configuracion_empresa` (`grep -c` → 0).
- **Obtenido:** contra una BD creada con `php artisan migrate:fresh --seed`:
  ```
  constancias/agente/1                     -> HTTP 500
  constancias/reporte/91                   -> HTTP 500
  constancias/traslado?tipo=equipos&id=2   -> HTTP 500
  SQLSTATE[HY000]: General error: 1 no such table: configuraciones
  ```
  Al crear a mano la tabla `configuraciones`, `agente` y `reporte` devuelven **200 `application/pdf`**
  (`%PDF-1.7`, 2 641 bytes) — o sea, el motor de PDF está sano; **el único problema es el nombre de la tabla**.
- **⚠️ Los tests enmascaran el bug.** `ConstanciaAgenteAuthTest:31-36`, `ConstanciaReporteAuthTest:39-44` y
  `ConstanciaReporteDetalleTest:160-165` hacen `if (! Schema::hasTable('configuraciones')) Schema::create('configuraciones', …)`
  **dentro del propio test**. Fabrican el esquema que la app espera y las migraciones nunca producen: por
  eso *"endpoint descarga pdf real sin error"* pasa en verde mientras el endpoint está roto en cualquier
  BD real. Es un falso verde, no una prueba.
- **Impacto:** severidad alta. Si en el VPS funciona es solo porque la BD viene migrada del legacy y
  arrastra una tabla que el esquema Laravel no declara — un despliegue limpio queda sin constancias.
  El `ReporteController.php:180` sí se defiende (`Schema::hasTable('configuraciones') ? … : 'BITEL'`),
  lo que confirma que el nombre es conocido-frágil y que la defensa se olvidó en `ConstanciaController`.

### 🟠 A3-02 · `COLLATE utf8mb4_unicode_ci` hardcodeado impide toda cobertura de la constancia de traslado

- **Refactor:** `ConstanciaController.php:33-34, 50-51, 70-71` — los `leftJoin` con `tiendas` usan
  `DB::raw('te.codigo COLLATE utf8mb4_unicode_ci')`. Las **tres** ramas (`chips`, `lote`, individual) lo hacen.
- **Obtenido:** aun con la tabla `configuraciones` presente, `constancias/traslado` sigue en **500**:
  `no such collation sequence: utf8mb4_unicode_ci`.
- **Alcance:** 18 ocurrencias en 5 archivos (`ChipsController` ×5, `ConstanciaController` ×6,
  `InventarioController` ×4, `TrasladoChipsController` ×2, `CuadreBitelService` ×1).
- **Impacto:** en producción (MySQL) funciona, así que **no es un fallo de runtime en el VPS**; pero la
  suite corre en SQLite (`phpunit.xml`: `DB_DATABASE=:memory:`), de modo que **estas rutas son
  intestables por construcción** — y en efecto `grep -rln "constancias/traslado" tests/` → **ninguno**.
  Combinado con A3-01, la constancia de traslado no tiene ni una sola prueba.
- **Estado de la verificación:** la **constancia PDF de traslado quedó SIN VERIFICAR** en el entorno de QA
  documentado (SQLite). Es el único sub-paso de los 3 flujos que no pude ejercer. Todo lo demás del flujo 3
  sí se validó contra BD.

---

# Borradores de ticket

> Ningún bug fue corregido (regla del TICKET-027). Estos son borradores listos para la cola.

## ticket-031 — Restaurar controles server-side del cuadre (precio mínimo + estado DISPONIBLE)
- **Severidad:** alta · **Modelo sugerido:** `gpt-5.3-codex` / `medium` · **Origen:** A1-01, A1-02
- **Contexto:** `ReporteController::procesarVentas` perdió dos guardas que el legacy aplica en cada venta.
- **Tareas:**
  1. En `ReporteController.php:883-928`, leer `precio_minimo` del ítem y lanzar `RuntimeException`
     (→ 422 `STOCK_GUARD`) si `precio_venta < precio_minimo && precio_minimo > 0`, con el mismo texto del
     legacy (`procesar_reporte.php:97-101`). Aplicar también en `reprocesar`.
  2. Añadir `->where('estado', 'DISPONIBLE')` al `update` de `inventario_tiendas` (`:917-921`), para que
     el mensaje "ya fue vendido, trasladado…" sea cierto.
- **Aceptación:** test que venda bajo el mínimo → 422 y sin reporte creado; test que venda un ítem en
  `TRASLADO` → 422. Ambos fallan hoy.

## ticket-032 — La edición aprobada debe poder ejecutarla la tienda que la solicitó
- **Severidad:** alta · **Modelo sugerido:** `gpt-5.3-codex` / `medium` · **Origen:** A2-01
- **Contexto:** `reprocesar` es admin-only, así que `aprobar-edicion` no habilita a nadie.
- **Tareas:** sustituir `abort_unless(rol === 'admin')` (`ReporteController.php:1125`) por la regla del
  legacy (`procesar_edicion.php:49-54`): admin pasa siempre; no-admin requiere reporte de **su** tienda
  **y** `estado_edicion === 'APROBADO'`.
- **Aceptación:** un usuario `tienda` con edición aprobada reprocesa su reporte (200) y no puede tocar el
  de otra tienda (403); admin conserva el bypass.

## ticket-033 — Corregir el nombre de tabla en ConstanciaController y desenmascarar los tests
- **Severidad:** alta · **Modelo sugerido:** `gpt-4o` / `low` (cambio puntual) · **Origen:** A3-01
- **Tareas:**
  1. `ConstanciaController.php:98,127,156,252` → `configuracion_empresa` (o un helper único).
  2. **Quitar** los `Schema::create('configuraciones', …)` de `ConstanciaAgenteAuthTest:31`,
     `ConstanciaReporteAuthTest:39` y `ConstanciaReporteDetalleTest:160,296`. Los tests deben apoyarse en
     las migraciones; si hoy pasan solo por fabricar la tabla, están mintiendo.
- **Aceptación:** con `migrate:fresh`, `constancias/{agente,reporte,boleta}` devuelven `application/pdf`;
  la suite pasa sin crear tablas a mano.

## ticket-034 — Auditoría faltante del cuadre (`crear`) y del retiro de equipos
- **Severidad:** media · **Modelo sugerido:** `gpt-5.3-codex` / `medium` · **Origen:** A1-03, A2-02
- **Tareas:**
  1. `store()` inserta `HistorialReporte` con `accion='crear'` dentro de la transacción (legacy `:434`).
  2. Migración: añadir `agente_verificado_id` / `agente_verificado_dni` a `historial_reportes`.
  3. En `reprocesar`, cuando desaparecen equipos del payload, registrar `edicion_equipo_eliminado` con
     nombre + IMEI + motivo + identidad verificada (legacy `procesar_edicion.php:376-386`).
- **Aceptación:** `GET /reportes/{id}/historial` arranca en `crear`; quitar un equipo deja `edicion_equipo_eliminado`.

## ticket-035 — Paridad de "plan online": ni chip ni costo de chip
- **Severidad:** media · **Modelo sugerido:** `gpt-5.3-codex` / `medium` · **Origen:** A1-04
- **Tareas:** introducir `esPlanOnline(plan)` = `tipo_servicio != 'PREPAGO' && stripos(nombre,'online')`
  (legacy `:184-185`) y usarlo en `ReporteController.php:947-949` (`consumeChip`) y
  `ComisionService.php:56` (`$costoChip`), líneas y apoyos.
- **Aceptación:** vender `Plan Online 29` no mueve `inventario_chips` y comisiona `18`, no `17`.

## ticket-036 — Limpieza del borrador por fecha del reporte y tienda del reporte
- **Severidad:** media · **Modelo sugerido:** `gpt-4o` / `low` · **Origen:** A1-05
- **Tareas:** en `ReporteController.php:559-565` usar `$validated['fecha']` en vez de `now()` y `$tiendaId`
  (el del reporte) en vez de `$user->tienda_id`, sacando el bloque del `if ($user->tienda_id)` para que el
  admin también limpie.
- **Aceptación:** guardar un cuadre retro-fechado **no** borra el borrador de hoy; un cuadre guardado por
  admin sí borra el borrador del agente/tienda/fecha correspondientes.

## ticket-037 — Decidir: ¿chips insuficientes abortan el cuadre? ¿se permiten cuadres duplicados?
- **Severidad:** media (decisión de producto, **no** fix directo) · **Origen:** A1-06, A1-07
- **Contexto:** dos desviaciones deliberadas o semi-deliberadas frente al legacy, ninguna documentada:
  1. `descontarChips` (`:1006-1010`) aborta el cuadre; el legacy solo loguea y continúa.
  2. El guard anti-duplicado se eliminó en `36b0e22` (`ReporteController.php:491`) con justificación en un
     comentario ("cerrar caja y abrir nueva"), contra `00-inventario-legacy.md` §4.
- **Tareas:** que David ratifique cada una. Si se ratifican → documentarlas en `00-inventario-legacy.md` §4 y
  en el master plan. Si no → restaurar el comportamiento legacy y ajustar
  `ReporteStoreParityTest::store permite varios cuadres el mismo dia`.

## ticket-038 — Validaciones de formulario del cuadre (`observaciones` requerido, enum de `destino_efectivo`)
- **Severidad:** baja · **Modelo sugerido:** `gpt-4o` / `low` · **Origen:** A1-08, A1-09
- **Tareas:**
  1. `destino_efectivo` → `'required|in:ENTREGADO,EN_CAJA'` (`ReporteController.php:437`; revisar el default
     `TIENDA` de `:550` y el enum de tres valores en `NuevoReportePage.tsx:127`).
  2. Hacer `observaciones` obligatorio cuando `destino_efectivo === 'ENTREGADO'`, en zod
     (`NuevoReportePage.tsx:128`, con `superRefine`) **y** en el backend (`required_if`), tal como el
     legacy (`nuevo_reporte.php:4433`). Hoy el `*` del label no lo respalda nada.
- **Aceptación:** `destino_efectivo:"PIZZA"` → 422; `ENTREGADO` sin `observaciones` → 422.

## ticket-039 — Erradicar `COLLATE utf8mb4_unicode_ci` hardcodeado (bloquea la cobertura en SQLite)
- **Severidad:** media · **Modelo sugerido:** `gpt-5.3-codex` / `medium` · **Origen:** A3-02
- **Contexto:** 18 ocurrencias en 5 archivos. La suite corre en SQLite, así que toda ruta que las use es
  intestable; `constancias/traslado` no tiene ni un test.
- **Tareas:** normalizar la comparación sin `COLLATE` (p.ej. columnas ya con la misma colación en la
  migración, o `whereRaw('LOWER(TRIM(a)) = LOWER(TRIM(b))')`), empezando por `ConstanciaController.php:33-71`.
- **Aceptación:** test Feature que descargue la constancia PDF de un traslado `CONFIRMADO` en SQLite y
  reciba `application/pdf`. Hoy es imposible escribirlo.

---

# Limpieza (estado al cerrar)

- Servidor propio `:8101` (PID **1684**) **detenido** por PID. No se usó `taskkill /IM` en ningún momento.
  No se tocó ningún proceso ajeno ni el puerto de la mitad B.
- BD de trabajo `backend/database/qa027a.sqlite` **eliminada**. La BD compartida
  `backend/database/database.sqlite` **no se modificó** en ningún momento (todas las escrituras fueron con
  `DB_DATABASE` apuntando a la BD propia).
- Ficheros temporales (`/tmp/qa027a_*`, PDF de prueba) eliminados. Playwright no se instaló.
- **Sin cambios en el repo** salvo este documento. Sin `commit` ni `push`.
