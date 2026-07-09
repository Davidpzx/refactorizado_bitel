# TICKET-005 — Cliente API de facturación (2 pasos) + drenador de cola con backoff

- **Modelo asignado:** Opus 4.8
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-001, TICKET-002.

## Contexto
El legacy emite CPE contra una API Laravel externa en **2 pasos** (POST crear → POST `{id}/send-sunat`), endpoints `/api/v1/boletas`, `/invoices`, `/notas-credito`, token Bearer por config. La emisión real la hace un cron que drena `comprobantes_cola` con backoff exponencial y soporta `--dry-run/--id/--limit`. Referencias legacy: `config/facturacion_client.php`, `cron/procesar_cola_comprobantes.php`, `reportes/ajax_emitir_ahora.php` (emisión síncrona con la misma semántica de cola — nunca se pierde la fila).

## Alcance
1. `app/Services/Facturacion/FacturacionApiClient.php`: port del cliente 2 pasos; config inyectada desde `FacturacionConfig` (ticket 001), NUNCA leída de `.env` global; manejo de errores retryables vs no-retryables; logs sin secretos.
2. `app/Console/Commands/ProcesarColaComprobantes.php`: drena la cola (ticket 002) con backoff exponencial, `--dry-run`, `--id=`, `--limit=`; registrado en `routes/console.php` con `everyMinute()->withoutOverlapping()`, timezone `America/Lima`.
3. Endpoint "emitir ahora" (síncrono) que reutiliza exactamente el mismo camino de cola (encola + procesa esa fila), no un camino paralelo.
4. Adaptar/retirar el job `EnviarComprobanteSunat` y `GreenterService` del flujo activo según DECISIÓN-001 (dejarlos desactivados tras bandera, no borrarlos en este ticket).
5. Tests Feature con HTTP fake: emisión feliz, error retryable (reintento con backoff), error no-retryable (estado ERROR final), dry-run no muta.

## Criterio de aceptación
Comando drena la cola en local contra API fake; tests verdes; ninguna emisión ocurre dentro del request web salvo vía la semántica "emitir ahora" descrita; logs no contienen tokens ni claves.
