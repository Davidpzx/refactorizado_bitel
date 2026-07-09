# Runbook — Migraciones pendientes en VPS de producción

- **Ticket:** `plan/tickets/ticket-003.md`
- **Fecha de redacción:** 2026-07-08
- **Entorno objetivo:** VPS de producción (Dokploy), stack `docker-compose.prod.yml`, host `https://refactor.kyrocodelabs.cloud`
- **Estado de esta ejecución:** 🟢 **EJECUTADA por el orquestador el 2026-07-08** (el usuario confirmó acceso SSH — clave `~/.ssh/id_ed25519`, registrada en Hostinger como `david-sys-trading-local`, host `2.24.105.11`, contenedor `erpcrmbitel-backend-5othkr...`). Resultado: **sin pendientes**. Ver §6 (evidencia real).

## Update 2026-07-08 — resultado real

1. `php artisan migrate:status` en el contenedor: **todas** las migraciones desplegadas figuran `Ran`, cero `Pending`. No hizo falta `migrate --force`.
2. `php artisan inventario:migrar-chips-mal-guardados` (sin `--force`, o sea dry-run): `Sin filas CHIP mal guardadas en inventario_tiendas. Nada que migrar.` No hizo falta `--force` porque no hay filas que mover.
3. **Importante — brecha real distinta a la asumida:** el código nuevo generado hoy en esta sesión (incluida la migración `2026_07_08_000001_create_facturacion_config_table` del ticket-001, y lo que produzcan los tickets siguientes) **todavía no está desplegado** en el VPS — vive solo en el working tree local. Cuando se despliegue (build + `docker service update` / redeploy en Dokploy), ahí sí habrá que correr `migrate --force` para esas migraciones nuevas. Esta sección del runbook (pasos 1-5 abajo) sigue vigente para ESE momento futuro, no para el estado actual (que ya se verificó limpio).

## 0. Qué problema resuelve esto

Según `plan/00-inventario-refactorizado.md` §5.1, el código está al día pero **nadie ha confirmado que el VPS de producción tenga aplicadas**:

1. Las migraciones de julio 2026, en particular `2026_07_02_000001_create_integrador_bitel_tables` (14 tablas del integrador Bitel) y las posteriores (`2026_07_03_*`, `2026_07_04_*`, `2026_07_08_000001_create_facturacion_config_table`).
2. El comando `php artisan inventario:migrar-chips-mal-guardados --force`. Este comando es **dry-run por defecto**; si nunca se corrió con `--force`, los chips históricos guardados por error en `inventario_tiendas` (tipo `CHIP`) siguen sin aparecer en `inventario_chips`.

Ninguna de las dos cosas es visible desde el repositorio local — solo se puede confirmar corriendo comandos dentro del contenedor `backend` en el VPS.

## 1. Pre-requisitos (obligatorio antes de tocar el VPS)

1. **Backup de la base de datos de producción antes de cualquier comando.** La BD (`DB_DATABASE`, por defecto `migracion`) vive en un MySQL gestionado fuera de este `docker-compose.prod.yml` (no hay servicio `mysql` en el compose; solo `backend`, `queue`, `frontend`, `redis`). Usar el backup nativo de Dokploy si está configurado, o manualmente:
   ```bash
   # Desde una máquina con acceso de red al DB_HOST de producción,
   # o desde dentro del contenedor backend (trae mysql-client instalado):
   docker exec <contenedor_backend> sh -c \
     'mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"' \
     > backup_migracion_$(date +%Y%m%d_%H%M%S).sql
   ```
2. Confirmar que el backup se generó y tiene tamaño > 0 antes de continuar.
3. Identificar el nombre real del contenedor backend en el VPS (Dokploy suele prefijar el nombre del servicio con el nombre del proyecto/stack):
   ```bash
   docker ps --format '{{.Names}}' | grep -i backend
   ```
   En los comandos de abajo, `<contenedor_backend>` = el nombre que devuelva ese `docker ps`.

## 2. Paso A — Aplicar migraciones pendientes

1. Ver el estado actual (no modifica nada):
   ```bash
   docker exec <contenedor_backend> php artisan migrate:status
   ```
2. Si aparecen filas con `Pending` (o el comando falla porque falta la tabla `migrations`), aplicar:
   ```bash
   docker exec <contenedor_backend> php artisan migrate --force
   ```
   `--force` es obligatorio porque `APP_ENV=production` bloquea `migrate` interactivo por defecto.
3. Verificar de nuevo que todo quedó en `Ran`:
   ```bash
   docker exec <contenedor_backend> php artisan migrate:status
   ```
   Confirmar en particular estas migraciones (las de julio, las más recientes y menos probables de haberse corrido):
   - `2026_07_02_000001_create_integrador_bitel_tables`
   - `2026_07_03_000001_add_desembolso_auditoria_to_ventas`
   - `2026_07_03_000001_add_formato_ticket_to_usuarios`
   - `2026_07_03_000002_baja_agente_auditoria`
   - `2026_07_03_000003_create_excepciones_jornada_table`
   - `2026_07_03_000004_add_fuente_nombre_and_index_to_crm_tables`
   - `2026_07_03_000004_add_razon_social_to_cuentas_bipay`
   - `2026_07_04_000001_add_venta_id_to_tickets_emitidos`
   - `2026_07_04_000002_create_log_ediciones_asistencia_table`
   - `2026_07_08_000001_create_facturacion_config_table`
4. Pegar la salida real de `migrate:status` (paso 3) en la sección §6 de este archivo cuando se ejecute.

## 3. Paso B — Migrar chips mal guardados (correr DESPUÉS del paso A)

1. Dry-run primero (no mueve nada, solo informa):
   ```bash
   docker exec <contenedor_backend> php artisan inventario:migrar-chips-mal-guardados
   ```
   Revisar el conteo de `Se moverían: N | Omitidas (sin tienda): M`. Si `N = 0`, no hay nada pendiente y se puede saltar el paso 2.
2. Si `N > 0`, ejecutar de verdad:
   ```bash
   docker exec <contenedor_backend> php artisan inventario:migrar-chips-mal-guardados --force
   ```
3. Verificar el resultado (`Movidas: N | Omitidas: M`). Las filas "Omitidas" son de tiendas que no existen en la tabla `tiendas` — revisar manualmente esos casos, el comando las deja intactas en `inventario_tiendas` a propósito (no borra datos sin destino claro).
4. El comando es idempotente y transaccional por fila (ver `backend/app/Console/Commands/MigrarChipsMalGuardados.php`): correrlo dos veces con `--force` no duplica stock. Si algo sale mal a mitad de camino, las filas ya migradas permanecen consistentes; no hace falta rollback manual fila por fila.

## 4. Rollback

- **Migraciones (paso A):** si una migración de julio falla o deja el esquema inconsistente:
  ```bash
  docker exec <contenedor_backend> php artisan migrate:rollback --step=1 --force
  ```
  Repetir `--step=1` por cada migración a revertir, verificando `migrate:status` entre cada una. Si el rollback de Laravel no es seguro (p. ej. datos ya insertados en columnas nuevas), restaurar el backup del paso 1:
  ```bash
  docker exec -i <contenedor_backend> sh -c \
    'mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"' \
    < backup_migracion_YYYYMMDD_HHMMSS.sql
  ```
- **Migración de chips (paso B):** no tiene comando de rollback dedicado. Si se movieron filas por error, es reversible manualmente restaurando el backup del paso 1 (correr `--force` es la única operación destructiva de este runbook: hace `DELETE` en `inventario_tiendas` fila por fila dentro de una transacción, con `INSERT`/`increment` en `inventario_chips`).

## 5. Checklist para el operador (pendiente de ejecutar)

Este agente **no tiene acceso SSH ni credenciales de Dokploy** al VPS de producción, así que ninguno de los comandos de §2 y §3 se ejecutó contra producción. Falta que el operador (David) los corra manualmente:

- [ ] Backup de la BD de producción tomado y verificado (§1.1-1.2)
- [ ] `docker ps` para confirmar el nombre real del contenedor backend (§1.3)
- [ ] `php artisan migrate:status` (antes) — pegar salida aquí
- [ ] `php artisan migrate --force` si había pendientes
- [ ] `php artisan migrate:status` (después) — confirmar que las migraciones de julio listadas en §2.3 están en `Ran`
- [ ] `php artisan inventario:migrar-chips-mal-guardados` (dry-run) — revisar conteo `N`
- [ ] `php artisan inventario:migrar-chips-mal-guardados --force` si `N > 0`
- [ ] Confirmar en la UI (`/chips-gestion` o `/inventario`) que el stock de chips migrado aparece correctamente en al menos una tienda

## 6. Salida real (a completar por quien ejecute en el VPS)

```
$ docker exec <contenedor_backend> php artisan migrate:status
(pegar aquí)

$ docker exec <contenedor_backend> php artisan inventario:migrar-chips-mal-guardados
(pegar aquí)

$ docker exec <contenedor_backend> php artisan inventario:migrar-chips-mal-guardados --force
(pegar aquí, solo si se ejecutó)
```

## 7. Verificación en local (ya hecha, ver §8)

Como no hay acceso al VPS, ambos comandos se verificaron localmente contra una base de datos de prueba (sqlite, la misma que usa `phpunit.xml`/`RefreshDatabase`), **sin tocar la BD real `migracion` de MySQL local**. Resultado: ver §8.

## 8. Evidencia de la verificación local (2026-07-08)

**Migraciones — `migrate:fresh` + `migrate:status` contra sqlite de prueba (44 archivos de migración, 44 corridas, 0 pendientes):**

```
$ APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/verify_ticket003.sqlite php artisan migrate --env=testing --force
... (44 migraciones, todas DONE, sin errores) ...

$ APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/verify_ticket003.sqlite php artisan migrate:status --env=testing
...
2026_07_02_000001_create_integrador_bitel_tables ............. [1] Ran
2026_07_03_000001_add_desembolso_auditoria_to_ventas .......... [1] Ran
2026_07_03_000001_add_formato_ticket_to_usuarios .............. [1] Ran
2026_07_03_000002_baja_agente_auditoria ........................ [1] Ran
2026_07_03_000003_create_excepciones_jornada_table ............. [1] Ran
2026_07_03_000004_add_fuente_nombre_and_index_to_crm_tables .... [1] Ran
2026_07_03_000004_add_razon_social_to_cuentas_bipay ............ [1] Ran
2026_07_04_000001_add_venta_id_to_tickets_emitidos ............. [1] Ran
2026_07_04_000002_create_log_ediciones_asistencia_table ........ [1] Ran
2026_07_08_000001_create_facturacion_config_table .............. [1] Ran
```
(44 filas totales en `Ran`, 0 en `Pending` — coincide con `ls database/migrations/*.php | wc -l` = 44.)

**Comando de chips — suite existente `tests/Feature/MigrarChipsMalGuardadosCommandTest.php` (dry-run, `--force`, acumulación, idempotencia, filas sin tienda, filas no-CHIP):**

```
$ php artisan test --filter=MigrarChipsMalGuardadosCommandTest

  PASS  Tests\Feature\MigrarChipsMalGuardadosCommandTest
  ✓ dry run no mueve nada                                                0.52s
  ✓ force mueve filas de inventario tiendas a inventario chips           0.04s
  ✓ force suma varias filas de la misma tienda en un solo registro       0.03s
  ✓ force es idempotente                                                0.03s
  ✓ force acumula sobre stock existente en inventario chips              0.03s
  ✓ force omite filas de tienda sin mapeo y las deja intactas            0.03s
  ✓ no toca filas equipo o accesorio                                    0.03s

  Tests:    7 passed (21 assertions)
  Duration: 0.98s
```

Ambos comandos corren limpios en local. Lo único que falta es la ejecución real en el VPS (§5).
