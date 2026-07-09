# Runbook — Operación de cron/scheduler/workers

- **Ticket:** `plan/tickets/ticket-025.md`
- **Fecha de redacción:** 2026-07-09
- **Depende de:** `docs/runbook-migraciones.md` (ticket-003, migraciones VPS); coordina con `bitel:facturacion:procesar-cola` (ticket-005).
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas` (crons de referencia)

## 0. Qué problema resuelve esto

El legacy corre sus 5 tareas periódicas vía cPanel/Tareas Programadas de Windows apuntando a scripts PHP sueltos en `cron/*.php` (más un 6to script de reparación manual, no programado). El refactor mueve todo a `Illuminate\Support\Facades\Schedule` en `backend/routes/console.php`, un único punto de entrada (`php artisan schedule:run`) que Laravel resuelve internamente. Este runbook documenta:

1. La matriz completa cron legacy → comando refactor, con garantías verificadas.
2. Cómo debe correr `schedule:run` en el deploy (Dokploy/supervisor/cron del host).
3. Qué mirar cuando algo no corre.
4. Dos gaps de cobertura encontrados y corregidos durante esta auditoría (ver §3).

## 1. Matriz cron legacy → comando refactor

| Cron legacy | Comando refactor (`artisan`) | Frecuencia programada | `withoutOverlapping` | Timezone | Log | Idempotencia |
|---|---|---|---|---|---|---|
| `cron/auto_retorno.php` (00:00 diario) | `bitel:auto-retorno` (`AutoRetornoAgentes`) | `dailyAt('00:05')` | ✅ | `America/Lima` | `$this->info/error` + `logger()->error` por fila | Sí — solo reactiva `estado=INACTIVO AND permiso_largo=1 AND fecha_retorno<=hoy`; una vez reactivado deja de ser candidato. |
| `cron/cron_salida_automatica.php` (cada 30 min) | `bitel:salida-automatica` (`SalidaAutomaticaAsistencias`) | `everyThirtyMinutes()` **(corregido, ver §3.1)** | ✅ | `America/Lima` | `$this->info/line/error` + `logger()->error` por fila | Sí — `whereNull(hora_salida)` + `update()->whereNull` re-chequeado; una fila cerrada deja de matchear. Excluye filas PERMISO/FALTA_INJUSTIFICADA (§3.1). |
| `cron/cron_auditoria_nocturna.php` (sugerido 20:00–23:59) | `bitel:auditoria-nocturna` (`AuditoriaNocturnaBipay`) | `dailyAt('23:30')` | ✅ | `America/Lima` | `$this->info/line` (delegado a `AuditoriaBipayService`) | Sí — recalcula el cruce del día, no inserta filas duplicadas. |
| `cron/procesar_cola_comprobantes.php` (cada minuto) | `facturacion:procesar-cola` (`ProcesarColaComprobantes`) | `everyMinute()` | ✅ | `America/Lima` | `$this->info/warn/error` por fila | Sí — backoff exponencial + `proximo_intento_at`; filas ya `emitida`/`rechazada` no vuelven a matchear `pendientes()`. Registrado en TICKET-005. |
| `cron/limpiar_fotos_asistencia.php` (cada 30 min) | `bitel:limpiar-fotos` (`LimpiarFotosAsistencia`) | `dailyAt('02:15')` **(corregido, ver §3.2)** | ✅ | `America/Lima` | `$this->info/error` + `logger()->error` por fila | Sí — Sección A solo toca `requiere_revision=1`; Sección B solo fotos con `fecha < corte`; ambas dejan de matchear tras procesarse. |
| `cron/reparar_excepciones_pisadas.php` (manual, una sola vez) | `bitel:reparar-excepciones-pisadas` (`RepararExcepcionesPisadas`) | **No programado** — herramienta de reparación puntual, igual que en el legacy (ver §3.1) | N/A (dry-run por defecto) | N/A | `$this->line/info/comment` | Sí — filtra por `estado_asistencia=CIERRE_AUTO` + `hora_ingreso='00:00:00'`, condición que deja de cumplirse tras reparar la fila. |

No hay crons legacy huérfanos: los 6 archivos de `cron/` tienen equivalente en el refactor (5 programados + 1 herramienta manual, exactamente como en el legacy).

## 2. Cómo corre `schedule:run` en producción

- El VPS de producción usa Dokploy sobre `docker-compose.prod.yml` (servicios `backend`, `queue`, `frontend`, `redis`; no hay `mysql` en el compose, la BD vive en un MySQL gestionado externo — ver `docs/runbook-migraciones.md` §1).
- Laravel 11+ resuelve el scheduler con **un solo cron de sistema** apuntando a `schedule:run` cada minuto — no se declara un cron por tarea. Confirmar/crear dentro del contenedor `backend`:
  ```bash
  docker exec <contenedor_backend> crontab -l
  ```
  Debe existir una línea equivalente a:
  ```
  * * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
  ```
  Si no aparece, añadirla (`crontab -e` dentro del contenedor, o vía la imagen/entrypoint si el Dockerfile ya la define — revisar `backend/Dockerfile`).
- Alternativa recomendada si el contenedor no persiste `crontab` entre reinicios (los contenedores suelen perder el cron del sistema en cada redeploy): usar `php artisan schedule:work` como proceso supervisado (supervisor/systemd o un servicio Docker propio) en vez de depender de `cron` del SO dentro del contenedor.
- **Workers de cola:** el compose ya trae un servicio `queue` dedicado (`php artisan queue:work`), separado del scheduler. Los 5 comandos de este runbook son `Schedule::command()` (tareas puntuales), no `Bus::dispatch()` a una cola — no dependen del servicio `queue`, solo de que `schedule:run` se dispare cada minuto.

## 3. Gaps encontrados y corregidos en esta auditoría

### 3.1 — `bitel:salida-automatica` corría 1×/día en vez de cada 30 min (corregido)

`SalidaAutomaticaAsistencias` ya tenía la espera de 90 min tras la hora de salida programada, el resguardo de horario inválido y el alcance a días anteriores (gaps que sí se habían cerrado en un ticket previo — ver `docs/comparacion/gap_api_cron_auth_2026-07-02.md`, "5 gaps más importantes" #1). Pero el schedule seguía en `dailyAt('23:00')`: como el comando espera 90 min desde la hora de salida programada de **cada agente** antes de cerrar, correrlo una sola vez al día podía dejar turnos abiertos hasta 24h de más (y la alerta a gerencia igual de tarde) para cualquier agente cuya salida programada cayera después de ~21:30. Se corrigió a `everyThirtyMinutes()`, paridad real con `cron/cron_salida_automatica.php`.

**Bug adicional descubierto y corregido de paso:** el mismo comando **no excluía** las filas de excepción (`PERMISO`/`FALTA_INJUSTIFICADA`) que `AsistenciaController::registrarExcepcion` inserta con `hora_ingreso='00:00:00'` + `latitud_ingreso='EXCEPCION'` (sentinela) y `hora_salida=NULL`. Como esas filas cumplían `hora_salida IS NULL` + `hora_ingreso IS NOT NULL` (el string `'00:00:00'` no es NULL), el auto-cierre las pisaba y las convertía en `CIERRE_AUTO` con una `hora_salida` falsa — **exactamente el bug que en el legacy motivó `cron/reparar_excepciones_pisadas.php`** como reparación manual recurrente. Se corrigió excluyendo `estado_asistencia IN ('PERMISO','FALTA_INJUSTIFICADA')` de la consulta (`backend/app/Console/Commands/SalidaAutomaticaAsistencias.php`), y se portó la herramienta de reparación (`bitel:reparar-excepciones-pisadas`) por si ya existen filas corrompidas en el VPS desde antes de este fix (ver §5, checklist pendiente).

Regresión cubierta por `SalidaAutomaticaAsistenciasTest::test_no_pisa_excepcion_permiso_de_dia_anterior` y `::test_no_pisa_excepcion_falta_injustificada`.

### 3.2 — `bitel:limpiar-fotos` corría semanal en vez de diario (corregido)

El comando tiene dos secciones: (A) auto-aprobación de fotos `FOTO` pendientes de revisión de días anteriores, (B) purga de archivos con más de 7 días. La Sección A ya estaba implementada (gap cerrado en un ticket previo), pero el schedule era `weekly()->sundays()->at('02:15')` — heredado, probablemente, de pensar la tarea solo como "purga semanal de disco". Eso represaba fotos sin revisar por gerencia hasta 6 días de más en vez de auto-resolverse al día siguiente (paridad legacy: cada 30 min). Se corrigió a `dailyAt('02:15')`. La Sección B (purga >7 días) es idempotente corriendo a diario, sin efecto adverso.

## 4. Qué mirar cuando algo no corre

1. **¿El cron de sistema está disparando `schedule:run`?**
   ```bash
   docker exec <contenedor_backend> crontab -l
   docker exec <contenedor_backend> php artisan schedule:list
   ```
   Si `schedule:list` no muestra las 5 líneas de la matriz (§1), `routes/console.php` no se cargó — revisar que el contenedor tenga el código desplegado (`docker exec <contenedor_backend> git log -1` o el hash de build).
2. **¿Corrió pero falló?** Laravel no loguea por defecto la salida de `schedule:run` a menos que cada comando la loguee él mismo (todos los de esta matriz lo hacen vía `$this->info/error` + `logger()->error`) — revisar:
   ```bash
   docker exec <contenedor_backend> tail -n 200 storage/logs/laravel.log
   ```
3. **¿Se solapó con una corrida anterior?** Todos los comandos usan `withoutOverlapping()` — Laravel guarda el lock en el `cache` default (`CACHE_STORE`). Si el lock quedó pegado (proceso matado a medias), limpiarlo:
   ```bash
   docker exec <contenedor_backend> php artisan schedule:clear-cache
   ```
4. **Verificación puntual de un comando específico**, sin esperar al scheduler:
   ```bash
   docker exec <contenedor_backend> php artisan bitel:salida-automatica
   docker exec <contenedor_backend> php artisan facturacion:procesar-cola --dry-run
   ```
5. **Nota de entorno local (no aplica al VPS):** en esta máquina de desarrollo, `php artisan schedule:list` falla contra el `CACHE_STORE=database` configurado en `.env` porque la BD MySQL local no es accesible (`Access denied for user 'root'@'localhost'`, preexistente, no relacionado con este ticket). Usar `CACHE_STORE=array php artisan schedule:list` para verificar la matriz sin depender de la BD.

## 5. Checklist para el operador (pendiente de ejecutar en el VPS)

- [ ] Confirmar que el contenedor `backend` tiene un cron de sistema (o `schedule:work` supervisado) disparando `php artisan schedule:run` cada minuto (§2).
- [ ] `php artisan schedule:list` dentro del contenedor — debe mostrar las 5 líneas de la matriz (§1) con los horarios corregidos (`bitel:salida-automatica` cada 30 min, `bitel:limpiar-fotos` a diario).
- [ ] Correr `php artisan bitel:reparar-excepciones-pisadas` (dry-run) en el VPS para saber si ya hay filas PERMISO/FALTA_INJUSTIFICADA corrompidas por el bug de §3.1 (probablemente activo desde que se implementó `registrarExcepcion`, ~2026-06-14). Si `N > 0`, correr con `--apply` tras confirmar con el operador (modifica datos de asistencia).
- [ ] Verificar en la UI (`/asistencias` o el módulo de gerencia) que un agente con `PERMISO`/`FALTA_INJUSTIFICADA` registrado no aparece como `CIERRE_AUTO` al día siguiente.

## 6. Verificación en local (2026-07-09)

**Suite completa tras los cambios de este ticket:**
```
$ php artisan test
Tests: 558 passed (1876 assertions)
```
(551 previos + 7 nuevos: 2 regresión en `SalidaAutomaticaAsistenciasTest` + 5 en `RepararExcepcionesPisadasCommandTest`.)

**`schedule:list` (con `CACHE_STORE=array` por el hallazgo del §4.5):**
```
$ CACHE_STORE=array php artisan schedule:list
5    5 * * *  php artisan bitel:auto-retorno .......................... Next Due: en 10 horas
*/30 * * * *  php artisan bitel:salida-automatica ..................... Next Due: en 18 minutos
30   4 * * *  php artisan bitel:auditoria-nocturna ................... Next Due: en 9 horas
*    * * * *  php artisan facturacion:procesar-cola .................. Next Due: en 16 segundos
15   7 * * *  php artisan bitel:limpiar-fotos ......................... Next Due: en 12 horas
```
(Las horas mostradas están en la timezone del sistema/servidor, no en America/Lima — la ejecución real sí respeta `->timezone('America/Lima')` en cada entrada de `routes/console.php`.)

**Dry-run de los 7 comandos (5 programados + 2 manuales) contra una BD sqlite descartable** (`APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/verify_ticket025.sqlite`, migrada con `migrate --force`, sin tocar la BD MySQL local ni la de producción):
```
bitel:auto-retorno              → "0 agentes a reactivar. OK."
bitel:salida-automatica         → "0 turnos abiertos. OK."
bitel:auditoria-nocturna        → "Tiendas auditadas: 0"
facturacion:procesar-cola --dry-run → "Sin comprobantes pendientes de emitir."
bitel:limpiar-fotos             → "Fotos auto-aprobadas: 0" / "Sin fotos antiguas."
bitel:reparar-excepciones-pisadas → "No se encontraron filas candidatas."
inventario:migrar-chips-mal-guardados → "Sin filas CHIP mal guardadas."
```
Todos corrieron limpio, código de salida 0. `bitel:salida-automatica` necesita que exista la tabla `sys_notificaciones` (el comando la auto-crea con DDL MySQL vía `CREATE TABLE ... AUTO_INCREMENT ... ENGINE=InnoDB`, no portable a sqlite — mismo patrón que `AutoRetornoAgentes`/`historial_agentes`; en MySQL real no hay problema, en sqlite hay que pre-crearla a mano para pruebas manuales, cosa que la suite de tests ya hace en su `setUp()`).

## 7. Archivos tocados por esta auditoría

- `backend/routes/console.php` — frecuencia de `bitel:salida-automatica` (1×/día → cada 30 min) y `bitel:limpiar-fotos` (semanal → diario).
- `backend/app/Console/Commands/SalidaAutomaticaAsistencias.php` — excluye filas PERMISO/FALTA_INJUSTIFICADA de la consulta de auto-cierre.
- `backend/app/Console/Commands/RepararExcepcionesPisadas.php` (nuevo) — puerto de `cron/reparar_excepciones_pisadas.php`, reparación manual dry-run/`--apply`.
- `backend/tests/Feature/SalidaAutomaticaAsistenciasTest.php` — 2 tests de regresión nuevos.
- `backend/tests/Feature/RepararExcepcionesPisadasCommandTest.php` (nuevo) — 5 tests.
- `backend/docs/runbook-operacion.md` (este archivo).

## 8. Limitaciones

- No se pudo ejecutar nada contra la BD MySQL real (local ni VPS) — toda la verificación es contra sqlite descartable o dry-runs, siguiendo la instrucción del ticket. El checklist del operador (§5) queda pendiente de ejecutar en el VPS por David.
- No se cuantificó cuántas filas PERMISO/FALTA_INJUSTIFICADA están ya corrompidas en producción por el bug de §3.1 — requiere correr `bitel:reparar-excepciones-pisadas` (dry-run) contra la BD real del VPS.
- El runbook no cubre el comando `inventario:migrar-chips-mal-guardados` en profundidad porque ya tiene su propio runbook dedicado (`docs/runbook-migraciones.md`, ticket-003); se incluye en la verificación de §6 solo para confirmar que sigue sin candidatas.
