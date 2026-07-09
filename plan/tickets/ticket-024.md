# TICKET-024 — Asistencias: presentar las rutas como pestañas (percepción legacy)

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repo:** refactor `C:\xampp\htdocs\refactorizado_bitel` (`AsistenciasPage`, `ControlAsistenciasPage`, `HistorialLiquidacionPage`, `RevisarFotosPage`)

## Contexto
El legacy concentra la gestión de asistencias en `panel_asistencias.php` con **pestañas internas**; el refactor la separó en 4 rutas (`/asistencias`, `/asistencias/control`, `/asistencias/liquidacion`, `/revisar-fotos`) — decisión documentada en AppLayout y aceptable, PERO la percepción debe calcar el legacy: navegación como tabs en la cabecera de las 4 páginas. El componente `PageTabs` ya existe en el proyecto.

## Alcance
1. Añadir una fila de `PageTabs` común en la cabecera de las 4 páginas (Gestión / Control mensual / Liquidación / Revisar fotos), tab activa con el tratamiento dorado del legacy, contadores vivos donde existan (fotos pendientes).
2. Las rutas se mantienen (deep-linking intacto); las tabs solo navegan entre ellas.
3. Iconos semánticos por tab (CalendarCheck, Grid3x3/Table, Calculator/Receipt, Camera).

## Criterio de aceptación
Navegar entre las 4 vistas se siente como cambiar de pestaña (sin saltos de layout); tab activa clara con acento dorado; URLs directas siguen funcionando.
