# TICKET-012 — Verificar y cerrar: Comisiones Empresa

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers, frontend-design (si hay que construir UI)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si al verificar resulta que falta la página completa y no alcanza el presupuesto, reportar la subdivisión propuesta ANTES de construir.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`

## Contexto
El legacy tiene `gerencia/comisiones_empresa.php`: CRUD de **planes de comisión** (`comisiones_planes`) y tarifas operativas — pantalla propia en el menú con icono `ph-buildings`. El inventario de diseño no encontró ruta ni página en `App.tsx` (posible gap), pero el inventario del refactorizado dice que `ComisionesPage` ya tiene "tarifas operativas + rangos" y que existe `ComisionPlanController` con CRUD + recalcular. Hipótesis: se fusionó en ComisionesPage.

## Alcance
1. **Verificar**: comparar funcionalidad de `comisiones_empresa.php` (leer el PHP legacy) contra lo que ComisionesPage + `ComisionPlanController` ya cubren. Producir lista concreta de lo que falta (si algo).
2. Si está fusionada y completa: documentar la equivalencia en `docs/comparacion/` y cerrar.
3. Si falta funcionalidad: completarla dentro de ComisionesPage (sección "Planes de empresa" con icono `Building2`) o página propia si el volumen lo justifica — replicando el flujo legacy (CRUD planes, tarifas, recálculo masivo retroactivo con confirmación).
4. Confirmaciones destructivas con ConfirmDialog kyro; iconografía con criterio.

## Criterio de aceptación
Informe de verificación escrito (qué había, qué faltaba, qué se hizo); toda función de `comisiones_empresa.php` disponible en el refactor y probada contra backend local.
