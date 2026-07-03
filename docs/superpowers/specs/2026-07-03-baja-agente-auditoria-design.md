# P0 #10 — Baja de agente con motivo/clasificación + auditoría `historial_agentes` (diseño final)

Estado: APROBADO — Opción C (paridad total). Supersede el DRAFT
(`2026-07-03-baja-agente-auditoria-DRAFT.md`), que queda como registro de investigación.
Rama: `p0-7-baja-agente`.

## 1. Alcance (decidido)

Paridad total con el legacy (`gerencia/editar_agente.php`, `gerencia/ver_agente.php`,
`exportar_excel_agentes_pro.php`):

1. Campos de baja en `agentes`: `clasificacion_baja`, `motivo_baja`, `fecha_baja`, `observacion`.
2. Auditoría de los 5 tipos de evento en `historial_agentes`: `CESE`, `REINGRESO`, `TIENDA`,
   `HORARIO`, `FICHA` — más `ESTADO` (default legacy que ya usa el cron `bitel:auto-retorno`).
3. Endpoint de lectura `GET /agentes/{id}/historial` (role:admin), ordenado descendente.
4. Modal de historial en la página de ver agente (paridad con `#modalHistorial` legacy).
5. Sección "DATOS DE BAJA" en el export Excel de fichas (`exportarFichaTecnica`).

**Clasificación y motivo son OPCIONALES** al dar de baja (paridad exacta con el legacy, que solo
los sugiere por UI; no hay validación server-side que los exija). Dar de baja sin ellos funciona.

**Quién ve el historial:** `admin` (como legacy).

## 2. Decisiones cerradas

- **Estado `BAJA`**: el frontend SÍ lo usa (`types/agente.ts` unión `'ACTIVO'|'INACTIVO'|'BAJA'`
  y `AgenteForm.tsx` lo ofrece como opción de estado). Se trata como **CESE** en la auditoría:
  cualquier transición a un estado ≠ `ACTIVO` (`INACTIVO` o `BAJA`) escribe `CESE`; la transición a
  `ACTIVO` escribe `REINGRESO`. No se elimina `BAJA` de la validación.
- **Auto-reactivación al marcar asistencia**: FUERA DE ALCANCE. Solo existe el cron
  `bitel:auto-retorno`; queda como follow-up.
- **Migración idempotente**: 100 % `hasColumn`/`hasTable` (patrón de
  `2026_06_14_000001_fix_legacy_schema_drift.php`). En producción (BD heredada del dump legacy) las
  columnas/tabla probablemente ya existen; la migración solo versiona lo que falte.
- **`registrado_por`** = `id` del usuario autenticado (`$request->user()?->id`) en cada inserción.

## 3. Backend

### 3.1 Migración `2026_07_03_000001_baja_agente_auditoria.php`

- `agentes` (con `hasColumn`): `clasificacion_baja` VARCHAR(20) NULL, `motivo_baja` TEXT NULL,
  `fecha_baja` DATE NULL, `observacion` TEXT NULL.
- `historial_agentes`: si no existe, se crea con el shape completo del legacy
  (`id`, `id_agente`, `estado`, `clasificacion_baja`, `observacion`, `fecha_registro`
  `useCurrent()`, `tipo_cambio` default `'ESTADO'`, `campo_anterior`, `campo_nuevo`,
  `registrado_por`, índice `idx_id_agente`). Si ya existe (creada en runtime por
  `AutoRetornoAgentes` con shape mínimo, o heredada del legacy), se agregan con `hasColumn` las
  columnas extendidas que falten (`tipo_cambio`, `campo_anterior`, `campo_nuevo`, `registrado_por`,
  `clasificacion_baja`, `observacion`).
- `down()`: no-op (correctivo aditivo; no se revierte para no perder datos).

### 3.2 Modelo `Agente`

Agregar a `$fillable`: `clasificacion_baja`, `motivo_baja`, `fecha_baja`, `observacion`
(sin ellos `$agente->update()` los ignora). `fecha_baja` ya está casteado a `date`.

### 3.3 `UpdateAgenteRequest`

Reglas nuevas (todas opcionales):
- `clasificacion_baja` → `['sometimes','nullable', Rule::in(['LISTA_BLANCA','LISTA_NEGRA'])]`
- `motivo_baja`        → `['sometimes','nullable','string','max:1000']`
- `fecha_baja`         → `['sometimes','nullable','date']`
- `observacion`        → `['sometimes','nullable','string','max:1000']`

### 3.4 `HistorialAgenteService` (nuevo)

Encapsula la escritura tolerante (Schema-guard) y la lógica de diff:

- `auditarActualizacion(Agente $antes, Agente $despues, ?int $usuarioId)`:
  - Estado cambió → `CESE` (a ≠ ACTIVO, guarda `clasificacion_baja` + observación concatenada
    `motivo_baja | observacion`) o `REINGRESO` (a ACTIVO, sin clasificación).
  - `tienda_base` cambió → `TIENDA` (`campo_anterior`/`campo_nuevo`).
  - Horario (`hora_ingreso`/`hora_salida`/`dia_descanso`) cambió → `HORARIO`
    (`campo_anterior`/`campo_nuevo` = `"E:.. S:.. D:.."`).
- `auditarFicha(Agente $antes, Agente $despues, ?int $usuarioId)`:
  - Campos `nombres`, `telefono`, `correo`, `sistema_pension`. Escribe `FICHA` **solo si hubo
    diferencia** en alguno; `campo_anterior`/`campo_nuevo` = JSON de los 4 campos.

### 3.5 `AgenteController`

- `update`: snapshot `clone $agente` → `service->actualizar` → `historial->auditarActualizacion`.
- `actualizarPerfilRrhh`: snapshot antes → update → `historial->auditarFicha`.
- `historial(int $id)`: `GET /agentes/{id}/historial` (role:admin), 404 si no existe el agente,
  `{ data: [] }` si la tabla no existe, si no filas de `historial_agentes` ordenadas por
  `fecha_registro` desc, `id` desc.
- `construirFichaAgente`: sección "DATOS DE BAJA" (fecha/clasificación/motivo/observación) cuando
  el agente tiene algún dato de baja.

### 3.6 Ruta

`Route::get('agentes/{id}/historial', [AgenteController::class, 'historial'])->middleware('role:admin');`
en el bloque de acciones adicionales de agentes.

## 4. Frontend

- `types/agente.ts`: agregar `clasificacion_baja`, `motivo_baja`, `observacion` a `Agente`
  (`fecha_baja` ya existe) y a `AgenteFormData` (opcionales).
- `AgenteForm.tsx`: campos de baja (select clasificación LISTA_BLANCA/LISTA_NEGRA, textarea motivo,
  fecha_baja, textarea observación) dentro del bloque visible cuando `estado !== 'ACTIVO'`
  (INACTIVO o BAJA — traducción fiel del "inactivo" legacy).
- `agentes.api.ts`: `historial(id)` → `GET /agentes/{id}/historial`.
- `VerAgentePage.tsx`: botón "Historial" (admin) que abre un modal listando eventos con badge por
  `tipo_cambio` (CESE=rojo, REINGRESO=verde, TIENDA/HORARIO/FICHA/ESTADO=colores distintos).

## 5. Tests (TDD, sqlite `:memory:`, RefreshDatabase)

`AgenteBajaAuditoriaTest`:
1. Baja con clasificación+motivo persiste y escribe `CESE`.
2. Baja SIN clasificación/motivo también funciona (opcionales) y escribe `CESE`.
3. Reingreso (INACTIVO→ACTIVO) escribe `REINGRESO`.
4. Cambio de tienda escribe `TIENDA` con `campo_anterior`/`campo_nuevo`.
5. Cambio de horario escribe `HORARIO`.
6. Edición de ficha (`actualizarPerfilRrhh`) escribe `FICHA` solo si hubo diferencia; sin cambio no
   escribe.
7. `GET /agentes/{id}/historial` devuelve ordenado desc y `registrado_por` = usuario autenticado.
8. `GET /agentes/{id}/historial` con rol `tienda` → 403.

## 6. Follow-ups (no en este alcance)

- Portar la auto-reactivación al marcar asistencia (3 puntos legacy:
  `registrar_asistencia.php`/`registrar_marcacion.php`/`procesar_asistencia.php`); hoy solo el cron.
- Limpieza automática de `clasificacion_baja`/`motivo_baja`/`fecha_baja` al reingresar manualmente
  (el cron sí lo hace; el update manual solo limpia lo que el frontend envíe).
