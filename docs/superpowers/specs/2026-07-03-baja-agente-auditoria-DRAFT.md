Estado: DRAFT — pendiente de decisión del usuario. NO commitear sin revisión.

# P0 #10 — Baja de agente sin motivo/clasificación ni auditoría

Investigación de gap. Referencia previa: `docs/comparacion/gap_gerencia_rrhh_stock_2026-07-02.md`
(fila "Baja sustentada... + auditoría `historial_agentes`", gap #1 de los 5 más importantes).

## 1. Hallazgos — legacy (`E:\laragon\www\sis_bipay`)

### 1.1 Columnas de baja en `agentes`

Creadas por auto-migración (`agregarColumnaSiNoExiste`) en `gerencia/gestionar_agentes.php:52-56`
y en `DB.sql:19-22`:

| Columna | Tipo | Obligatoriedad real (código) |
|---|---|---|
| `clasificacion_baja` | `VARCHAR(20) NULL` — valores `LISTA_BLANCA` / `LISTA_NEGRA` | **Opcional.** `editar_agente.php:77`: `!empty($_POST['clasificacion_baja']) ? ... : null` — se puede dar de baja sin clasificar. |
| `motivo_baja` | `TEXT NULL` | Opcional en BD, pero el `<textarea>` en `gestionar_agentes.php:888` no tiene `required`; el label lo trata como "obligatorio" solo por convención de UI, no hay validación server-side que lo exija. |
| `fecha_baja` | `DATE NULL` | Si se omite, se autocompleta con `date('Y-m-d')` (hoy) — `editar_agente.php:79`. |
| `permiso_largo` | `TINYINT(1) DEFAULT 0` | Solo se puede activar si `clasificacion_baja === 'LISTA_BLANCA'` (`editar_agente.php:80`) — permite reactivación automática futura. |
| `fecha_retorno` | `DATE NULL` | Fecha programada de reactivación; usada por el cron `auto_retorno.php` / comando `bitel:auto-retorno`. |
| `observacion` | `TEXT NULL` | Campo libre adicional, se concatena con `motivo_baja` en el historial (`editar_agente.php:141`). |

**Conclusión clave:** en legacy, la "clasificación obligatoria" es más una convención de UI que una regla dura — el
backend permite guardar `INACTIVO` sin clasificación ni motivo. Esto es una decisión de producto a confirmar con
el usuario (sección 3), no algo que se pueda copiar 1:1 como "obligatorio" sin preguntarlo.

### 1.2 Tabla `historial_agentes` — qué escribe y quién

Definición (`DB.sql:595-604`, también recreada por `editar_agente.php:7-16` y `AutoRetornoAgentes.php:31-44`
en el refactor):

```sql
CREATE TABLE historial_agentes (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    id_agente        INT NOT NULL,
    estado           VARCHAR(20) NOT NULL,
    clasificacion_baja VARCHAR(20) NULL,
    observacion      TEXT NULL,
    fecha_registro   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    tipo_cambio      VARCHAR(40) NOT NULL DEFAULT 'ESTADO',  -- ALTER posterior, DB.sql:23
    campo_anterior   TEXT NULL,                               -- ALTER posterior, DB.sql:24
    campo_nuevo      TEXT NULL,                               -- ALTER posterior, DB.sql:25
    registrado_por   INT NULL,                                -- ALTER posterior, DB.sql:26
    KEY idx_id_agente (id_agente)
)
```

Eventos que insertan una fila, con `tipo_cambio`:

| Evento | Archivo:línea | `tipo_cambio` | Qué guarda |
|---|---|---|---|
| Baja de agente (estado → INACTIVO) | `gerencia/editar_agente.php:147-156` | `CESE` | `clasificacion_baja`, `observacion` = motivo_baja + observacion concatenados, `registrado_por` = admin que hizo el cambio |
| Reactivación manual (estado → ACTIVO) | `gerencia/editar_agente.php:147-156` | `REINGRESO` | sin clasificación, `registrado_por` |
| Cambio de tienda base | `gerencia/editar_agente.php:159-166` | `TIENDA` | `campo_anterior`/`campo_nuevo` = tienda vieja/nueva |
| Cambio de horario (entrada/salida/día libre) | `gerencia/editar_agente.php:168-177` | `HORARIO` | `campo_anterior`/`campo_nuevo` = strings "E:.. S:.. D:.." |
| Edición de ficha RRHH (nombre/tel/correo/pensión) | `gerencia/ver_agente.php:138-157` | `FICHA` | `campo_anterior`/`campo_nuevo` = JSON con los 4 campos, solo si hubo diferencia |
| Reactivación automática (fecha_retorno cumplida) | `cron/auto_retorno.php:74-80`, `api/registrar_asistencia.php:75-78`, `api/registrar_marcacion.php:98-102`, `procesar_asistencia.php:58-65` | (sin `tipo_cambio` explícito → default `'ESTADO'`) | `observacion` = "Reactivación automática..." |

Nótese que **4 puntos distintos** disparan la reactivación automática (cron batch + 3 endpoints que la resuelven
al vuelo cuando el agente intenta marcar asistencia). El refactor solo tiene el equivalente del cron
(`AutoRetornoAgentes.php`), no los otros 3 puntos "reactivar al marcar".

### 1.3 Dónde se lee/muestra el historial en legacy

- `gerencia/ver_agente.php:1423-1436` — query `SELECT ... FROM historial_agentes WHERE id_agente = ? ORDER BY fecha_registro DESC`, envuelta en `try/catch` (tolera que la tabla no exista).
- Se muestra en un modal (`#modalHistorial`, líneas 1438-1499+) con badges de color por `tipo_cambio` (CESE=rojo, REINGRESO=verde, TIENDA=cian, HORARIO=violeta, default=gris "ESTADO").
- Acceso: el botón que abre el modal (línea 486-490) está dentro del bloque visible solo para `admin` (`ver_agente.php` no filtra el modal en sí por rol adicional — quien puede entrar a `ver_agente.php` como admin ve el historial completo).
- También se usa para mostrar el badge "🚫 Lista Negra / ✅ Lista Blanca + motivo" directamente en la tarjeta de perfil del agente inactivo (líneas 449-462) y en el export Excel de `gestionar_agentes.php` (columnas FECHA BAJA / CLASIFICACIÓN / MOTIVO BAJA, líneas 214-215, 245-247) y en `exportar_excel_agentes_pro.php` (sección "DATOS DE BAJA", líneas 191-196).

## 2. Hallazgos — refactor (`C:\xampp\htdocs\refactorizado_bitel`)

### 2.1 Estado del esquema (importante, cambia el análisis)

- `backend/database/migrations/2026_06_07_000002_create_agentes_minimal_table.php` crea `agentes` con **solo 5 columnas**
  (`id`, `nombres`, `estado`, `tienda_base`, `sueldo_base`) y es explícitamente **no-op en producción**
  ("la tabla ya existe con schema completo" — comentario línea 8) porque la BD de producción fue heredada
  del dump legacy.
- Ninguna migración Laravel del refactor crea `clasificacion_baja`/`motivo_baja`/`fecha_baja`/`observacion`/
  `historial_agentes`. Solo `2026_06_11_000005_complete_asistencias_legacy_parity.php:25-26` agrega
  `permiso_largo` y `fecha_retorno` (condicionalmente, si no existen).
- **Implicación:** en el VPS de producción (BD heredada del dump legacy), es muy probable que las columnas
  `clasificacion_baja`/`motivo_baja`/`fecha_baja`/`observacion` **ya existan** en `agentes` (por eso
  `AutoRetornoAgentes.php` puede hacer `UPDATE agentes SET clasificacion_baja = null, ...` sin que Laravel
  lance error de columna desconocida). Pero en un entorno nuevo levantado solo con `php artisan migrate`
  (ej. entorno de test/CI), esas columnas **no existirán**, y tampoco `historial_agentes` a menos que se
  ejecute `AutoRetornoAgentes.php` una vez (que la crea con `CREATE TABLE IF NOT EXISTS`).
- Esto es una **inconsistencia de esquema no versionada** que ya existe hoy, independiente de si se resuelve
  este gap. Cualquier opción de diseño abajo debería incluir una migración formal que declare estas columnas/tabla
  con `IF NOT EXISTS` para dejar de depender del estado heredado del VPS.

### 2.2 Qué hace hoy `AgenteController` y `UpdateAgenteRequest`

- `AgenteController::destroy` (`AgenteController.php:55-62`) — **elimina físicamente** el registro (`$agente->delete()`)
  si no tiene reportes asociados. No hay "baja lógica" vía `destroy`; es un hard delete con un solo guard.
- El cambio a `estado = INACTIVO` se hace vía `AgenteController::update` → `UpdateAgenteRequest` → `AgenteService::actualizar`.
  `UpdateAgenteRequest::rules()` (`UpdateAgenteRequest.php:29`) valida `estado` como `in:ACTIVO,INACTIVO,BAJA`
  (nótese: 3 valores, incluye `BAJA` que **no existe en legacy**, donde solo hay `ACTIVO`/`INACTIVO` — origen
  y semántica de este tercer estado no está clara en el código revisado y debe confirmarse antes de diseñar).
- `UpdateAgenteRequest` valida `permiso_largo` y `fecha_retorno` (líneas 39-40) pero **no** valida ni acepta
  `clasificacion_baja`, `motivo_baja`, `fecha_baja`, `observacion`. Si el frontend los enviara, `AgenteService::actualizar`
  probablemente los ignoraría (no están en `$request->validated()`).
- `StoreAgenteRequest.php` — cero referencias a estos campos (confirmado, agente nuevo siempre nace sin datos de baja, lo cual es correcto y no es parte del gap).
- `AgenteForm.tsx` (frontend) no tiene campos de clasificación/motivo/fecha de baja — confirmado por el gap doc previo.

### 2.3 `historial_agentes` en el refactor — solo escritura, cero lectura

- Único punto de escritura: `backend/app/Console/Commands/AutoRetornoAgentes.php:61-65`, inserta una fila
  `estado=ACTIVO` con observación de reactivación automática. Es el equivalente exacto del `cron/auto_retorno.php`
  legacy — **correcto y completo para ese caso puntual**.
- No existe ningún INSERT para CESE/REINGRESO manual, TIENDA, HORARIO ni FICHA — los 4 tipos de auditoría manual
  que sí tenía legacy están ausentes.
- No existe ningún controller/ruta que haga `SELECT ... FROM historial_agentes` — confirmado, `grep` sobre
  `backend/routes/api.php` y todos los controllers no arroja coincidencias de lectura de esa tabla.
- Los 3 puntos legacy de "reactivar al intentar marcar asistencia" (`api/registrar_asistencia.php`,
  `api/registrar_marcacion.php`, `procesar_asistencia.php`) no tienen equivalente confirmado en
  `AsistenciaController.php` — solo se verificó que la tabla aparece referenciada ahí (ver grep de la sección de
  investigación), pero no se confirmó si replica la misma lógica de auto-reactivación al marcar. **Requiere
  verificación adicional si se decide portar ese flujo** (fuera del alcance mínimo de este gap, que es sobre
  captura/auditoría de la baja).

### 2.4 Qué se pierde exactamente respecto al legacy

1. Al inactivar un agente desde el refactor, no hay forma de registrar **por qué** (motivo) ni **cómo se clasifica**
   (lista blanca = puede volver / lista negra = no debe volver) — dato usado en RRHH para decidir si un ex-agente
   puede ser re-contratado.
2. No queda ningún rastro auditable de **quién** dio de baja al agente ni **cuándo**, más allá del `updated_at`
   genérico del modelo (que no distingue qué cambió ni quién lo hizo).
3. No hay auditoría de cambios de tienda/horario/ficha — cambios operativos sensibles (ej. mover a un agente de
   tienda, o cambiarle el horario) quedan sin registro histórico.
4. No hay pantalla ni endpoint para consultar ese historial aunque se empezara a escribir hoy mismo.
5. El export Excel de agentes (`exportarFichaTecnica` en el refactor) no incluye la sección "DATOS DE BAJA"
   que sí tiene el legacy (`fecha_baja`/`clasificacion_baja`/`motivo_baja`) — puede añadirse sin depender de
   las opciones de diseño de abajo si se decide, pero está mencionado aquí porque toca los mismos campos.

## 3. Opciones de diseño

### Opción A — Mínima: solo campos de baja + 1 registro de historial en cada baja/reingreso

- Migración: agregar `clasificacion_baja`, `motivo_baja`, `fecha_baja`, `observacion` a `agentes` con
  `IF NOT EXISTS` (columnas ya casi seguro presentes en prod, pero hay que versionarlas) + migración que crea
  `historial_agentes` con `IF NOT EXISTS` (mismo shape que legacy, incluyendo `tipo_cambio`/`registrado_por`/
  `campo_anterior`/`campo_nuevo` para no tener que volver a alterar la tabla si se amplía después).
- `UpdateAgenteRequest`: agregar reglas para los 4 campos nuevos (todos `nullable`, no forzar `required` salvo
  que el usuario confirme que sí quiere obligatoriedad — ver pregunta abierta 3.1).
- `AgenteService::actualizar` (o `AgenteController::update`): al detectar `estado` cambiando a `INACTIVO`/`ACTIVO`,
  insertar 1 fila en `historial_agentes` con `tipo_cambio = CESE|REINGRESO`, igual que legacy.
- `AgenteForm.tsx`: agregar los campos de clasificación (select LISTA_BLANCA/LISTA_NEGRA)/motivo/fecha, visibles
  solo cuando `estado = INACTIVO` (como el legacy).
- **No incluye**: lectura del historial (endpoint/UI), ni auditoría de TIENDA/HORARIO/FICHA.
- Trade-off: cierra la parte de compliance más urgente (¿por qué se dio de baja a X?) con el menor esfuerzo,
  pero deja el historial "ciego" — se escribe pero nadie puede verlo desde el nuevo sistema (habría que mirar
  la BD directo, como hoy).

### Opción B — Media: Opción A + endpoint de lectura + panel UI simple

- Todo lo de la Opción A.
- `AgenteController::historial(int $id)` — `GET /agentes/{id}/historial`, devuelve filas de `historial_agentes`
  ordenadas por fecha descendente (paridad directa con la query de `ver_agente.php:1426-1435`).
- Panel/modal en `VerAgentePage.tsx` (paridad con `#modalHistorial` legacy) que lista los eventos con badge por
  `tipo_cambio`.
- Sigue sin auditar TIENDA/HORARIO/FICHA (solo CESE/REINGRESO, que es lo único que Opción A escribe).
- Trade-off: agrega valor real (RRHH puede consultar el motivo de baja de un ex-agente sin ir a la BD), esfuerzo
  moderado adicional sobre A.

### Opción C — Completa: paridad total con legacy (todos los eventos + lectura + UI)

- Todo lo de la Opción B.
- Auditar también cambios de TIENDA, HORARIO y FICHA (nombre/teléfono/correo/pensión) en
  `AgenteController::update` / `actualizarPerfilRrhh`, comparando valores anteriores vs nuevos como hace
  `editar_agente.php:159-177` y `ver_agente.php:138-157`.
- Registrar `registrado_por` = usuario autenticado (`$request->user()->id`) en cada inserción — dato que hoy
  ni siquiera se captura en la Opción A/B tal como está planteada arriba (hay que añadirlo explícitamente).
- Trade-off: paridad completa y la auditoría más útil para RRHH/gerencia a futuro, pero es la que más superficie
  toca (3 tipos de evento adicionales, cada uno con su lógica de "detectar si cambió"), y por tanto más
  esfuerzo/riesgo de introducir bugs en el flujo de edición de agente que hoy funciona bien.

## 4. Preguntas abiertas para el usuario

1. **¿La clasificación (`LISTA_BLANCA`/`LISTA_NEGRA`) y el motivo deben ser obligatorios al dar de baja, o
   opcionales como en legacy?** El legacy los trata como opcionales en el backend (solo el frontend los sugiere
   como importantes). Si ahora se quiere volverlos obligatorios, es un cambio de comportamiento respecto al
   legacy, no una simple paridad — hay que decidirlo explícitamente.
2. **¿Qué significa el estado `BAJA` que ya está validado en `UpdateAgenteRequest.php:29`
   (`in:ACTIVO,INACTIVO,BAJA`) pero que no existe en el legacy (solo `ACTIVO`/`INACTIVO`)?** Antes de diseñar
   la solución hay que confirmar: ¿es un estado real usado en algún flujo actual del refactor, o quedó de un
   experimento sin terminar? Si es real, el diseño de auditoría debe cubrir también la transición a/desde `BAJA`.
3. **¿Quién debe poder ver el historial de un agente?** Legacy lo muestra a cualquier `admin` que entre a
   `ver_agente.php`. ¿Se mantiene igual, o se restringe más (ej. solo RRHH, no gerentes de tienda con acceso
   parcial)?
4. **¿Qué eventos deben auditarse en la primera iteración?** Solo CESE/REINGRESO (Opción A/B) es lo mínimo con
   valor de compliance. TIENDA/HORARIO/FICHA (Opción C) es "nice to have" de trazabilidad operativa — ¿hay
   presión real (ej. un incidente pasado) que justifique incluirlos ahora, o pueden quedar para una iteración
   posterior?
5. **¿Se debe portar la auto-reactivación al marcar asistencia** (los 3 puntos legacy en
   `registrar_asistencia.php`/`registrar_marcacion.php`/`procesar_asistencia.php`, además del cron), **o basta
   con el cron `bitel:auto-retorno` que ya existe en el refactor?** Afecta si un agente con `fecha_retorno`
   vencida queda bloqueado hasta la próxima corrida del cron, o se reactiva al instante al intentar marcar.
6. **¿Se debe agregar la sección "DATOS DE BAJA" al export Excel de fichas del refactor** (paridad con
   `exportarFichaTecnica` legacy)? Es un cambio pequeño e independiente de las opciones A/B/C, pero toca los
   mismos campos — conviene decidirlo junto con el resto para no hacerlo dos veces.
7. **Confirmar el estado real de las columnas en el VPS de producción** (`clasificacion_baja`, `motivo_baja`,
   `fecha_baja`, `observacion`, `historial_agentes`) antes de escribir la migración — si ya existen (lo más
   probable, ver sección 2.1), la migración debe ser 100% `IF NOT EXISTS` para no romper nada al correr
   `php artisan migrate` en el VPS.
