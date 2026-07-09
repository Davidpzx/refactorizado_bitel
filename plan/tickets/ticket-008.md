# TICKET-008 — Links públicos CPE (HMAC) + vista pública + impresión multi-formato

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design (vista pública)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-005.

## Contexto
El legacy genera un **link público firmado con HMAC** (secret en `sys_config`) para que el cliente vea/descargue su comprobante por WhatsApp **sin sesión**: `config/cpe_links.php`, `reportes/ajax_link_cpe.php`, `reportes/cpe_publico.php`. Además imprime comprobantes en 4 formatos: A4 / a5 / 80mm / ticket (`reportes/imprimir_comprobante.php`).

## Alcance
1. Backend: endpoint público `GET /api/v1/cpe/{id}?firma=...` con verificación HMAC (secret en `sys_config`, ya migrada) y expiración opcional; endpoint autenticado para generar/copiar el link (para botón "WhatsApp" en ComprobantesPage).
2. Frontend: página pública `/cpe/:id` (registrar en `App.tsx` como ruta pública) con la identidad `public-premium-*` ya existente (como LoginPage): datos del comprobante, estado SUNAT, botones descargar PDF/XML.
3. Vista de impresión con los 4 formatos del legacy (A4/a5/80mm/ticket) seleccionable por query param, reutilizando el patrón de `TicketImpresionPage`.
4. Tests: firma válida → 200, firma inválida/expirada → 403; link generado solo por usuario autenticado.

## Diseño
Réplica de la intención del legacy elevada con la identidad kyro pública (gradientes índigo/dorado). Iconos con criterio (descarga, WhatsApp, estado SUNAT) — nada genérico.

## Criterio de aceptación
Link abre sin sesión y muestra el comprobante; firma inválida rechazada; los 4 formatos imprimen correctamente en vista previa del navegador.
