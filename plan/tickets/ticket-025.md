# TICKET-025 — Matriz operativa de cron/scheduler/workers

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas` (referencia de crons)
- **Depende de:** TICKET-003 (runbook base); coordina con TICKET-005 (comando de cola SUNAT).

## Contexto
Riesgo señalado por el informe de arquitectura: jobs no ejecutados si falta `schedule:run` o worker; reprocesos duplicados sin idempotencia. Crons del legacy que deben tener equivalente programado: cola comprobantes (cada minuto), auditoría Bipay nocturna, salida automática ~23:00, auto-retorno de permisos 00:00, limpiar fotos >7 días. El refactor ya tiene 5 comandos console + scheduler — falta auditar cobertura, garantías y documentación operativa.

## Alcance
1. Auditar `routes/console.php` + `app/Console/Commands`: mapear cada cron legacy → comando refactor; detectar faltantes o sin schedule.
2. Garantías por comando: `withoutOverlapping()`, timezone `America/Lima`, log de inicio/fin/errores, idempotencia (locks/claves únicas donde haya sync/importación).
3. Escribir `docs/runbook-operacion.md`: cómo corre `schedule:run` en el deploy (Dokploy/supervisor/cron del host), workers de cola si aplican, qué mirar cuando algo no corre, y la matriz completa cron→comando→frecuencia→garantías.
4. Verificar en local: `php artisan schedule:list` coherente con la matriz; correr cada comando en dry-run/entorno de prueba.

## Criterio de aceptación
Matriz completa sin crons legacy huérfanos; todos los comandos con overlapping+timezone+logs verificados; runbook listo para el operador.
