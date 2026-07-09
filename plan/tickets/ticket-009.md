# TICKET-009 — `ConfiguracionFacturacionPage`: wizard para gerente no técnico

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**, agentbrowser (comparar contra legacy en vivo si está corriendo)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Depende de:** TICKET-006.

## Contexto
El legacy rediseñó `gerencia/configuracion_facturacion.php` para un **gerente no técnico** (commit `c21a531`): wizard simplificado por tienda — datos del emisor, series, modo beta/producción, subir certificado + credenciales SOL para activar producción, sync de logo. En el refactor NO existe ruta ni página (gap confirmado por el inventario de diseño §3).

## Alcance
1. Nueva página admin `/configuracion/facturacion` registrada en `App.tsx` (lazy + AdminRoute) con entrada de menú en la sección CONFIGURACIÓN del sidebar — icono con criterio: `Receipt` (legacy usa `ph-receipt`).
2. Wizard por tienda: estado actual (beta/producción, config heredada de la global o propia), formulario de series/RUC/IGV, paso "activar producción" con upload de certificado (PFX o PEM, el backend convierte) + credenciales SOL, feedback claro de éxito/error **en lenguaje no técnico**.
3. Service `services/facturacion.api.ts` + tipos TS; hooks React Query por dominio (convención del repo).
4. Confirmaciones con el ConfirmDialog kyro (ticket 016) — NUNCA `confirm()` nativo.

## Diseño
Replicar la lógica de uso del wizard legacy elevada con la identidad kyro (cards `#18181b`, hairline índigo→dorado del Dialog, botón principal dorado `variant="gold"`). Cero iconos genéricos: cada paso con icono semántico (edificio, certificado/candado, series, producción). Estados con badges glass.

## Criterio de aceptación
Un admin puede: ver config por tienda, editarla, subir certificado y activar producción end-to-end contra el backend del ticket 006 (API fake en dev); la página pasa revisión visual contra la intención del legacy (captura si existe, o el archivo PHP como referencia de estructura).
