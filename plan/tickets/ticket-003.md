# TICKET-003 — Runbook operativo: migraciones en VPS + migración de chips

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada; si falta acceso SSH, entregar el runbook igual y marcar la ejecución como bloqueada-por-acceso (eso cuenta como completo).
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel`

## Contexto
Según el inventario del refactorizado (§5.1), la **única pendiente real de código-a-producción** es operativa: no hay evidencia de que en el VPS de producción se haya corrido (a) `php artisan migrate` con las migraciones de julio (incluida la de 14 tablas del integrador del 2026-07-02) ni (b) `php artisan inventario:migrar-chips-mal-guardados --force` (dry-run por defecto; sin correr, los chips históricos siguen invisibles).

## Alcance
1. Escribir `docs/runbook-migraciones.md` en el refactor: pasos exactos, orden, comandos con flags, cómo verificar (`php artisan migrate:status`), plan de rollback, y advertencia de backup previo de BD.
2. Si hay acceso SSH/Dokploy al VPS del refactor: ejecutar `migrate:status`, pegar la salida real en el runbook, ejecutar lo pendiente (migrate primero, chips después) y verificar. Si NO hay acceso: dejar el runbook listo y una checklist de "pendiente de ejecutar por el operador".
3. Verificar en local que ambos comandos corren limpios contra una BD de prueba.

## Criterio de aceptación
Runbook completo y verificado en local; estado real del VPS documentado (ejecutado, o bloqueado-por-acceso con checklist).
