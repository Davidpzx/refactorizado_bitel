# TICKET-020 — Réplica fiel de la pantalla de cuadre (Nuevo/Editar Reporte)

- **Modelo asignado:** **Opus 4.8** (pantalla más crítica y rica del sistema)
- **Skills obligatorias:** headroom, superpowers, **frontend-design**, **agentbrowser** (levantar legacy en Laragon y comparar en vivo)
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Este ticket es GRANDE: si al dimensionar ves riesgo, pide dividirlo en (a) secciones 1–5 del formulario y (b) panel CUADRE FINAL, ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas` (`reportes/nuevo_reporte.php`, `editar_reporte.php`)
- **Referencia visual obligatoria:** captura `C:\xampp\htdocs\refactor_principal\legacy\...004*.png` + legacy corriendo en Laragon.

## Contexto
Es la pantalla que los agentes usan todos los días y la más rica del legacy (captura 004): **5 secciones numeradas con encabezado de color propio** (1 azul, 2 verde, 3 ámbar, 4 gris, 5 púrpura) para postpago/prepago/equipos/salidas/otros; toggles Ext/Mig/Upg/eSIM; **panel lateral CUADRE FINAL con header dorado**, "EFECTIVO ESPERADO" en cyan gigante, par de botones "LO ENTREGUÉ / EN TIENDA" con resplandor neón verde/ámbar al seleccionar, banner TOTAL SISTEMA cyan. La funcionalidad ya existe completa en el refactor (`NuevoReportePage`/`EditarReportePage` + borrador + modo Dios); lo que falta es **fidelidad visual**, y hay un `window.confirm` al cerrar caja (usar ConfirmDialog del ticket 016).

## Alcance
1. Comparar en vivo (agentbrowser) el refactor contra el legacy corriendo y la captura 004, campo por campo.
2. Reestilizar SIN tocar la lógica: encabezados de sección numerados con su color exacto, panel CUADRE FINAL (header dorado, EFECTIVO ESPERADO cyan, radio buttons con glow), banner TOTAL SISTEMA, orden y agrupación de campos igual al legacy.
3. Sustituir el `window.confirm` de cierre de caja por ConfirmDialog kyro con intención dorada.
4. Responsive: el legacy es desktop-first; mantener usable en tablet (los agentes usan PCs de tienda).

## Criterio de aceptación
Comparación lado a lado (capturas en el PR) donde cada sección del legacy tiene su gemela en el refactor con el mismo color/jerarquía; los ~tests de reportes existentes siguen verdes (no se tocó lógica); flujo completo de cuadre probado en vivo.
