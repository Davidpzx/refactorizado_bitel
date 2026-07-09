# TICKET-002 — Migración cola de comprobantes con snapshot fiscal

- **Modelo asignado:** Opus 4.8
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-001.

## Contexto
En el legacy, el request web **solo encola** (regla de oro: nunca se pierde una fila) en `comprobantes_cola`: payload fiscal JSON **congelado** (snapshot — no se recalcula en reintentos), estado (pendiente/error/emitido), `intentos`/`max_intentos` con backoff exponencial, `api_doc_id` devuelto por la API, referencias a archivos PDF/XML/CDR. Referencia: `config/facturacion_cola.php` y `cron/procesar_cola_comprobantes.php` del legacy. El refactor tiene tabla `comprobantes` + job `EnviarComprobanteSunat`, pero sin semántica de cola completa.

## Alcance
1. Migración idempotente para la cola (o extensión de `comprobantes` si el análisis lo justifica — documentar la decisión en el propio PR): payload fiscal snapshot JSON, estados claros (`PENDIENTE`, `ENVIADO`, `ACEPTADO`, `RECHAZADO`, `ANULADO`, `ERROR`), intentos/max_intentos, `proximo_intento_at`, `api_doc_id`, rutas de archivos, tipo de comprobante, serie/correlativo, tienda emisora.
2. Modelo Eloquent con relaciones a venta/reporte y a `FacturacionConfig`.
3. Correlativo por serie+emisor generado **dentro de transacción con lock** (riesgo señalado por Codex: doble número en concurrencia).
4. Tests: encolado idempotente, snapshot inmutable entre reintentos, correlativo sin duplicados bajo concurrencia (test con transacciones).

## Qué NO hacer
No recalcular payload fiscal en cada retry; no mezclar tickets internos con CPE oficial sin tipo explícito; no llamar a SUNAT desde el request.

## Criterio de aceptación
Migración limpia; tests verdes incluyendo el de correlativo concurrente; una venta encolada dos veces no duplica fila.
