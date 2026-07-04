# Reconocimiento facial en `validarSeguridad()` — diseño

Fuente del gap: `docs/comparacion/gap_api_cron_auth_2026-07-02.md`, fila "Reconocimiento facial (transversal)".
Decisión de producto (2026-07-03, confirmada por David): el mecanismo sigue en uso → se porta.

## 1. Cómo funcionaba en el legacy (`E:\laragon\www\sis_bipay`)

### 1.1 Origen del hash facial

No hay generación de reconocimiento facial en PHP. El hash es un string opaco que llega ya calculado
en el campo `device_hash` del POST, generado por un dispositivo/app externa (terminal de reconocimiento
facial física, fuera de este repo). Convención: el hash facial siempre trae el prefijo `dasam-face-`;
el hash de huella de navegador/celular (WebAuthn-like) no lo tiene. **No hay reconocimiento facial que
implementar aquí** — sólo la validación server-side de un hash con prefijo reservado.

### 1.2 `config/attendance_helpers.php::verificarHashDispositivo()`

Usada por `api/registrar_marcacion.php` y `api/registrar_asistencia_qr.php` (y su duplicado inline en
el huérfano `api/registrar_asistencia.php`). Firma:
`verificarHashDispositivo(PDO $pdo, array $agente, string $device_hash, bool $usando_token, string $codigo_tienda): void`
(responde JSON + `exit` en caso de error; no lanza excepción).

Lógica (en orden):

1. Si `$usando_token` → `return` inmediato. El token de emergencia anula toda esta validación.
2. `$es_facial = str_starts_with($device_hash, 'dasam-face-')`. Columna a usar: `hash_facial` si
   `$es_facial`, si no `hash_dispositivo`. **Cada prefijo tiene su propia columna en `agentes`** — un
   agente puede tener registrados un celular (`hash_dispositivo`) y una cara (`hash_facial`) en
   simultáneo, de forma independiente.
3. Si `$device_hash` viene vacío → `return` (permite marcar; terminal nueva sin huella todavía).
4. Si la columna ya tiene un valor guardado (`$hash_db !== ''`) y coincide exactamente con
   `$device_hash` → `return` (dispositivo/cara ya vinculado, todo bien).
5. Si la columna tiene un valor guardado, **no** coincide, y ese valor guardado **no** empieza con
   `dasam-sf-` → FRAUDE: inserta en `log_fraude_dispositivo` (`dni_duenio_hash = 'HASH_DISTINTO'`) y
   responde error (bloquea la marcación).
6. Si la columna tiene un valor guardado, no coincide, pero el valor guardado **sí** empieza con
   `dasam-sf-` → bypass silencioso: `return` sin bloquear y **sin re-vincular** (el sentinel
   `dasam-sf-...` se queda igual en BD). Es el mecanismo para que un admin desactive permanentemente
   el candado de esa columna para un agente puntual (ej. agente cuya cara/celular cambia seguido) sin
   tener que actualizar el hash cada vez. Confirmado por `admin/_reset_hashes_dispositivo.php`, que sólo
   limpia (`NULL`) ambas columnas para *todos* los agentes — el sentinel `dasam-sf-` se asume escrito a
   mano en BD por un admin, no hay UI que lo genere.
7. Si la columna está vacía (`$hash_db === ''`) → primer registro en este dispositivo/cara:
   - Busca si otro agente ya tiene esa misma columna con ese mismo valor (`WHERE {col} = ? AND id != ?`).
     Si existe → FRAUDE tipo duplicado (log con `dni_duenio_hash = <nombre del dueño>`) y bloquea.
   - Si no hay duplicado → vincula: `UPDATE agentes SET {col} = device_hash,
     tienda_registro_inicial = COALESCE(NULLIF(tienda_registro_inicial,''), :tienda),
     fecha_registro_disp = COALESCE(fecha_registro_disp, NOW()) WHERE id = :id`
     (usa `COALESCE` → sólo graba tienda/fecha de registro la primerísima vez que el agente vincula
     *cualquier* dispositivo, sea facial o celular).

### 1.3 Post-verificación: re-vínculo tras token de emergencia (asimetría a preservar)

En los 2 endpoints activos, justo **después** de llamar a `verificarHashDispositivo()` (que no hizo
nada porque `$usando_token` era `true`), hay un bloque separado que SIEMPRE opera sobre
`hash_dispositivo` — nunca sobre `hash_facial`, sin importar si el `device_hash` recibido tiene
prefijo `dasam-face-`:

```php
if ($usando_token && !empty($device_hash)) {
    $dup = «SELECT id FROM agentes WHERE hash_dispositivo = ? AND id != ?»;
    if ($dup) UPDATE agentes SET hash_dispositivo = NULL WHERE id = <dup>;   // libera al dueño anterior
    UPDATE agentes SET hash_dispositivo = ?, tienda_registro_inicial = ?, fecha_registro_disp = NOW()
        WHERE id = <agente_actual>;
}
```

Es decir: el flujo de "perdí mi celular, uso token de emergencia para re-registrar" **sólo re-vincula
la columna `hash_dispositivo`**, jamás `hash_facial`, incluso si por lo que sea el frontend mandó un
`device_hash` con pinta de facial durante ese intento. Se replica tal cual (no se "arregla" esta
asimetría — es el comportamiento observado y paridad exacta pide preservarlo).

### 1.4 Columna en BD

`agentes.hash_facial varchar(120) DEFAULT NULL` (`DB.sql:48`). **Ya existe en el refactor**: migración
`2026_06_11_000005_complete_asistencias_legacy_parity.php` ya la crea (`varchar(128)`, nullable) y
`App\Models\Agente::$hidden` ya la oculta. No hace falta ninguna migración nueva — el gap es puramente
de lógica en `AsistenciaController::validarSeguridad()`, que hoy sólo conoce `hash_dispositivo`.

## 2. Diseño del port

### 2.1 Alcance

Modificar únicamente `AsistenciaController::validarSeguridad()` (privado, usado por `mark()`,
`markQr()`, `markPhoto()` — las 3 rutas de marcación, igual que en legacy). No se toca `status()` ni
`DispositivoController::autorizar` (el legacy tampoco los usa — `verificar_asistencia_hoy.php` /
`obtener_estado_asistencia.php` no referencian `hash_facial`, y `autorizar_dispositivo.php` es un flujo
de PIN aparte, ya con paridad completa según el gap doc).

### 2.2 Cambios en `validarSeguridad()`

1. Tras el bloque de token (sin cambios) y el `if ($deviceId === '') return null;`, detectar
   `$columnaHash = str_starts_with($deviceId, 'dasam-face-') ? 'hash_facial' : 'hash_dispositivo'` y
   leer `$hashDb` de esa columna (antes hardcodeaba `'hash_dispositivo'`).
2. En el chequeo de mismatch (sólo aplica si `! $usaToken`), si `$hashDb` no vacío y no coincide:
   - si `str_starts_with($hashDb, 'dasam-sf-')` → `return null;` (bypass, sin log, sin re-vínculo).
   - si no → fraude + `DEVICE_MISMATCH` (como hoy, sin cambios de mensaje/código).
3. El resto del método (chequeo de duplicado + `DB::transaction` que vincula) sigue igual en estructura,
   pero la columna a usar depende de si estamos en la rama "token" o no:
   - `$usaToken === true` → columna de duplicado/relink = **siempre `hash_dispositivo`** (paridad con
     §1.3 — el re-vínculo post-token nunca toca `hash_facial`).
   - `$usaToken === false` → columna de duplicado/relink = `$columnaHash` (facial-aware, paridad §1.2.7).
4. No se toca el comportamiento existente para `hash_dispositivo` cuando `$deviceId` no es facial: el
   diff es mínimo y sólo introduce ramas nuevas para el prefijo `dasam-face-` y el sentinel `dasam-sf-`.

### 2.3 Fuera de alcance / no se implementa

- Reconocimiento facial real (captura, comparación biométrica, liveness): vive en el dispositivo/app
  externa que ya generaba el hash en el legacy. No hay UI ni hardware de esto en este repo.
- No se agrega ningún endpoint para que un admin escriba el sentinel `dasam-sf-`; en el legacy tampoco
  existía (era edición manual en BD). Si el negocio lo pide después, es un ticket aparte.
- `es_restauracion` (P2, alcance adicional del prompt): investigado en el legacy — vive en
  `reportes/procesar_edicion.php` (auditoría de ediciones de *reportes de venta/comisión*, tabla
  `historial_reportes`), no en el flujo de edición de asistencias (`gerencia/acciones_asistencia.php`,
  `gerencia/admin_editar_asistencia.php` no tienen esa lógica). No aplica a este módulo — declarado
  fuera de alcance, no se porta nada.

## 3. Plan de pruebas (TDD)

Nuevo archivo `tests/Feature/AsistenciaFacialTest.php`:

1. Primer `mark()` con `device_hash = dasam-face-...` y agente sin `hash_facial` previo → 200, y
   `hash_facial` queda vinculado en BD (mismo valor); `hash_dispositivo` no se toca.
2. Segunda marca con el mismo `dasam-face-...` → 200 (coincide, no bloquea).
3. Marca con `dasam-face-...` distinto al ya vinculado → 403 `DEVICE_MISMATCH` + fila en
   `log_fraude_dispositivo`.
4. `dasam-face-...` ya vinculado a **otro** agente → 403 `DEVICE_DUPLICATE` al intentar vincularlo a un
   segundo agente.
5. `hash_facial` en BD = `dasam-sf-cualquier-cosa`, llega un `dasam-face-...` que no coincide → 200 (no
   bloquea) y el `hash_facial` en BD sigue siendo el sentinel `dasam-sf-...` (no se sobreescribe).
6. Un agente con `hash_dispositivo` distinto al enviado pero **sin** `hash_facial` guardado, marca con
   `device_hash` facial → no lo bloquea el mismatch de `hash_dispositivo` (las columnas son
   independientes).
7. Token de emergencia válido + `device_hash` facial → tras la marca, `hash_dispositivo` queda
   actualizado al valor facial recibido (paridad exacta con la asimetría legacy de §1.3) y
   `hash_facial` no cambia.

Regresión obligatoria (no deben romperse): `AsistenciaTest`, `SalidaAutomaticaAsistenciasTest`,
`DispositivoTest`.
