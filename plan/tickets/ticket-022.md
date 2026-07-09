# TICKET-022 — Financieras: KPIs con hairline + badges Krece/PayJoy + colores semánticos

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, **frontend-design**
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`PanelFinancierasPage`) · legacy `E:\laragon\www\sistema-rolando-salas` (`gerencia/panel_financieras.php`)
- **Referencia visual:** captura `C:\xampp\htdocs\refactor_principal\legacy\...021*.png`

## Contexto
El panel de financieras del legacy (captura 021): **3 KPI con hairline superior** (amarillo = comisiones pendientes, verde = confirmadas, índigo = total facturado), tabla con financiera como **badge índigo** (Krece/PayJoy), **precios en amarillo**, saldos negativos en rojo, estado `PENDIENTE` como badge-glass ámbar y acción "Confirmar" como botón glass verde. La funcionalidad del refactor está completa (confirmar/revertir desembolso con lock + auditoría + preview) — solo es fidelidad visual. El `window.confirm` lo cubre el ticket 016.

## Alcance
1. KPIs superiores con hairline de color (reutilizar la variante `topAccent` del ticket 021).
2. Tabla: badges índigo para financiera, precios amarillos, saldos negativos rojos, estados badge-glass, Confirmar verde glass — patrón `kyro-table-head` con acentos neón del legacy.
3. Iconos semánticos en KPIs y acciones (Handshake ya se usa — correcto).

## Criterio de aceptación
Comparación lado a lado contra la captura 021 en el PR; los 3 KPI con su hairline correcto; tabla con el código de color completo del legacy.
