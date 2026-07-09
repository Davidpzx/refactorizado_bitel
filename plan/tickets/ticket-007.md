# TICKET-007 — Notas de crédito, anulación y descarga de comprobantes

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-005.

## Contexto
El legacy soporta: emitir nota de crédito referenciando un comprobante aceptado (`gerencia/emitir_nc.php`), anular boleta (`gerencia/anular_boleta.php`) y descargar PDF/XML/CDR (`gerencia/descargar_comprobante.php`) vía la API externa. Reglas de Codex: validar que el comprobante afectado exista y esté ACEPTADO antes de emitir NC; **no anular solo la venta local si el CPE ya fue aceptado** — la anulación fiscal va por NC/comunicación de baja según tipo.

## Alcance
1. Endpoints API (admin): `comprobantes/{id}/nota-credito` (motivo + tipo NC), `comprobantes/{id}/anular`, `comprobantes/{id}/descargar/{tipo}` (pdf|xml|cdr, proxy/stream desde la API externa o storage).
2. La NC se emite por la MISMA cola del ticket 002/005 (payload snapshot, referencia al comprobante afectado en BD).
3. Estados del comprobante afectado actualizados con auditoría (quién, cuándo, motivo).
4. Tests: NC sobre comprobante aceptado OK; NC sobre pendiente/rechazado → 422; anulación actualiza estado local solo tras confirmación de la API; descarga con permisos.

## Criterio de aceptación
Tests verdes; flujo NC/anulación demostrado contra API fake; imposible crear NC sin vínculo fiscal al comprobante afectado.
