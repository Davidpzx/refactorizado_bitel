# Módulo 3 — Asistencia/RRHH: matriz mensual, excepciones de jornada, auto-aprobación de fotos

**Fecha:** 2026-07-03
**Gaps:** filas 21-22 de `docs/comparacion/gap_gerencia_rrhh_stock_2026-07-02.md` (matriz mensual + toggle excepción) y fila `limpiar_fotos_asistencia.php` de `docs/comparacion/gap_api_cron_auth_2026-07-02.md` (Sección A ausente).

## 1. Vista matricial mensual de asistencias

**Legacy:** `gerencia/control_asistencias.php` — grid agente × día del mes, agrupado por tienda, 5 filas por agente (INGRESO/SAL.REFRI/RET.REFRI/SALIDA/STATUS), coloreado por estado (OK/tardanza/falta/permiso/feriado/½T/descanso). Rol: solo `admin` (`$_SESSION['rol'] !== 'admin'` redirige). Click en celda STATUS abre modal de edición (reutiliza `admin_editar_asistencia.php`).

**Backend** — `AsistenciaController::matriz` (nuevo), `GET /v1/asistencias/matriz?mes=YYYY-MM`, middleware `role:admin`.
- Igual que el legacy: si `mes` es el mes actual, `fecha_fin = hoy` (no muestra días futuros); si es un mes pasado, `fecha_fin` = último día de ese mes.
- Trae agentes `estado=ACTIVO` ordenados por `tienda_base, nombres`; asistencias del rango; `excepciones_jornada` del rango (si la tabla existe — incluye la ya usada por `AsistenciaNeiryController`).
- Para cada agente × día calcula un estado ya resuelto en servidor (paridad con el cálculo PHP del legacy): `DESCANSO` (día de descanso del agente sin marcación), `FUTURO` (día > hoy dentro del mismo mes render, no debería ocurrir dado el corte de `fecha_fin` pero se deja por si el query trae basura), `SIN_MARCA`, `FALTA` (`estado_asistencia=FALTA_INJUSTIFICADA`), `PERMISO`, `FERIADO`, `MEDIO_TIEMPO` (excepción del día), `TARDANZA` (`minutos_tardanza>0`), `OK`.
- Respuesta agrupada por tienda para que el frontend no tenga que hacer el `GROUP BY` en el cliente:
  ```json
  {
    "mes": "2026-07",
    "dias_mostrar": 3,
    "tiendas": [
      { "tienda": "T01", "agentes": [
        { "id": 1, "nombre": "...", "dia_descanso": "DOMINGO",
          "dias": { "1": { "estado": "OK", "asistencia_id": 55, "hora_ingreso": "08:05:00", ..., "excepcion_tipo": null }, "2": {...} } }
      ]}
    ]
  }
  ```
- Cada celda trae también los 4 horarios crudos + `asistencia_id` para que el frontend reutilice el modal de edición ya existente (`PATCH /v1/asistencias/{id}`, el mismo que usa `AsistenciasPage.tsx`) — no se duplica lógica de edición.

**Frontend** — `frontend/src/pages/asistencias/ControlAsistenciasPage.tsx` (nueva), ruta `/asistencias/control`, ítem de sidebar solo `admin` bajo la sección "Personal". Selector de mes + tabla sticky-header agrupada por tienda con celdas coloreadas por estado; clic en celda con `asistencia_id` abre el mismo modal de edición (import compartido o duplicado mínimo del formulario de `AsistenciasPage`). Cada celda también expone un botón pequeño para alternar la excepción MEDIO_TIEMPO (ver punto 2).

## 2. Excepciones de jornada (`excepciones_jornada`) — migración + toggle

**Legacy:** tabla **no está** en `DB.sql` base — se crea de forma perezosa vía `CREATE TABLE IF NOT EXISTS` embebido en 3 sitios (`gerencia/ajax_excepcion_jornada.php`, `gerencia/planilla_agentes.php`, y de forma más completa en `sql/migracion_esquema_brisel.sql`, que añade una columna `notas` que los otros dos no tienen). Se usa esta última como shape canónico porque es el script de "tablas faltantes" mantenido explícitamente para nivelar instalaciones.

Shape verificado (`sql/migracion_esquema_brisel.sql` líneas 13-26):
```sql
CREATE TABLE IF NOT EXISTS `excepciones_jornada` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agente_id INT NOT NULL,
  fecha DATE NOT NULL,
  tipo ENUM('MEDIO_TIEMPO','TURNO_LIBRE','OTRO') NOT NULL DEFAULT 'MEDIO_TIEMPO',
  horas_trabajadas DECIMAL(4,2) NOT NULL DEFAULT 4.00,
  registrado_por INT DEFAULT NULL,
  notas TEXT,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agente_fecha (agente_id, fecha),
  KEY idx_agente (agente_id),
  KEY idx_fecha (fecha)
);
```

**Backend**
- Migración nueva `database/migrations/2026_07_03_000003_create_excepciones_jornada_table.php`, guardada con `Schema::hasTable()` (la BD de prod puede ya tenerla creada por el legacy con este shape exacto — no se debe recrear ni chocar).
- `AsistenciaController::excepcionJornada` (nuevo), `POST /v1/asistencias/excepcion-jornada`, `role:admin`. Comportamiento toggle idéntico a `ajax_excepcion_jornada.php`: si ya existe fila `(agente_id, fecha)` → `DELETE` (estado `off`); si no existe → `INSERT` (estado `on`). Valida `tipo` en `{MEDIO_TIEMPO, TURNO_LIBRE, OTRO}` y `horas_trabajadas` en `[0,24]` igual que el legacy (`max(0, min(24, ...))`).
- Es la única escritura necesaria — "CRUD mínimo" = crear (toggle-on) + eliminar (toggle-off); no hay UPDATE en el legacy tampoco (para cambiar el tipo, se apaga y se prende de nuevo).
- `AsistenciaNeiryController` y la matriz (punto 1) ya leen esta tabla; no requieren cambios más allá de que ahora existirá.

**Frontend** — botón/badge de toggle en cada celda STATUS de `ControlAsistenciasPage.tsx` (punto 1) que llama `POST /v1/asistencias/excepcion-jornada` con `{agente_id, fecha}` y refresca la matriz.

## 3. Auto-aprobación de fotos pendientes (Sección A de `limpiar_fotos_asistencia.php`)

**Legacy:** el cron corre Sección A antes que Sección B. Sección A busca `asistencias` con `fecha < hoy AND requiere_revision = 1 AND metodo_marcacion = 'FOTO'`, y para cada una: borra el archivo físico si existe, pone `requiere_revision = 0` y `foto_marcacion = NULL` (auto-aprobación silenciosa de días vencidos que gerencia no revisó a tiempo).

**Backend** — `App\Console\Commands\LimpiarFotosAsistencia::handle()`: se antepone la Sección A a la Sección B existente, usando el mismo `Storage::disk('public')` que ya usa la Sección B (el comando actual no maneja rutas de filesystem crudas como el legacy PHP, así que se sigue el patrón ya establecido en este archivo en vez de introducir uno nuevo). No cambia el `$signature` (`bitel:limpiar-fotos {--dias=7}`) ni la frecuencia programada (`Schedule::weekly()`) — eso es una preocupación operativa distinta (el legacy corre cada 30 min; documentado como concern, no se toca el scheduler en este cambio).

## Testing

- Feature test: migración crea `excepciones_jornada` con la unique key `(agente_id, fecha)`.
- Feature test: `POST excepcion-jornada` crea la fila la primera vez (`estado=on`) y la borra la segunda vez con los mismos `agente_id`/`fecha` (`estado=off`).
- Feature test: `GET /v1/asistencias/matriz` devuelve estados `OK/TARDANZA/FALTA/DESCANSO/MEDIO_TIEMPO` correctos para un mes con datos fabricados, agrupados por tienda, y rechaza usuarios no-admin con 403.
- Feature test: `bitel:limpiar-fotos` auto-aprueba (requiere_revision 1→0, foto_marcacion→null) una asistencia `FOTO` de un día anterior, sin tocar una de **hoy** ni una que no sea `FOTO`.

## Fuera de alcance

- No se cambia la frecuencia del scheduler de `bitel:limpiar-fotos` (semanal) — la Sección A funcionaría igual de bien corriendo semanalmente ya que solo mira "días anteriores", pero la cadencia idónea (diaria) es una decisión operativa aparte.
- No se implementa edición del `tipo`/`horas_trabajadas` de una excepción ya creada (el legacy tampoco lo permite — solo toggle on/off).
