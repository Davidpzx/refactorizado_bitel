# TICKET-041 — Paridad visual REAL contra el legacy en producción (auditoría dura + fixes)

- **Modelo asignado:** Sonnet 5 (razonamiento alto) — dividir en bloques si no alcanza (0.3)
- **Skills obligatorias:** headroom, superpowers, **frontend-design**, browser (Playwright temporal)
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (frontend/)
- **Origen:** feedback del usuario (2026-07-09): "la verdad todo el diseño no he visto mejoras... siento que no hay paridad con el legacy". El QA previo (04-qa-visual.md) dio 25 fiel/9 mejorada — el usuario en producción NO lo percibe así. Su veredicto manda: hay que auditar más duro y con AMBOS sistemas de producción lado a lado.

## Ventaja clave
AMBOS sistemas están en producción pública en el mismo VPS:
- Legacy: `https://mundoandroid.kyrocodelabs.cloud` (el diseño de referencia)
- Refactor: `https://app.kyrocodelabs.cloud`
Se pueden comparar EN VIVO con screenshots reales, sin levantar nada en local. (Credenciales: pedir al orquestador si no hay usuario de prueba conocido; en el refactor existe "prueba admin".)

## Alcance
1. Con Playwright temporal, capturar lado a lado las 10 pantallas más usadas del negocio (Dashboard, Nuevo Reporte/cuadre, Historial, Asistencias, Personal, Inventario, Precios, CRM, Planilla, Comisiones) en AMBOS sistemas de producción.
2. Para cada una, lista de diferencias CONCRETAS y visibles (densidad, jerarquía tipográfica, colores de acento por sección, tablas, botones, spacing, iconos) — no veredictos blandos: qué cambiar, en qué archivo.
3. APLICAR los fixes de las diferencias encontradas (esto no es solo informe: es informe + fix), priorizando los que el usuario nota a simple vista.
4. Informe final en `plan/06-paridad-produccion.md` con los pares de capturas antes/después.

## Criterio de aceptación
Pares de capturas legacy-vs-refactor donde la diferencia visual sea mínima o una mejora evidente (nunca una degradación ni algo genérico); `tsc`+`build` limpios; cero cambios de lógica.
