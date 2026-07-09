# TICKET-013 — Verificar y cerrar gaps Tier 3/4 pendientes de re-verificación

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada: la VERIFICACIÓN de los 5 ítems es obligatoria; los cierres que resulten grandes se reportan como sub-tareas propuestas, no se construyen a medias.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Referencia:** `docs/comparacion/GAPS_PENDIENTES_v2.md` (los IDs vienen de ahí).

## Contexto
El inventario del refactorizado (§6) dejó 5 gaps con **estado desconocido** (pueden haberse cerrado en fases B/C/D de 2026-06-15 — verificar antes de construir):
- **T3.2** Ajuste maestro de inventario a conteo físico (`gerencia/admin_ajuste_inventario.php`)
- **T3.5** GET token de emergencia activo de un agente (`api/verificar_token_activo.php`)
- **T3.6** Recálculo masivo de comisiones operativas (`gerencia/recalcular_comisiones_masivo.php`)
- **T3.7** Multi-IMEI / `series_info` JSON en chips (`inventario_chips.series_info`)
- **T4.1** Exports: ¿Excel real o CSV? (legacy exporta Excel en gerencia/estadísticas/asistencias/CRM)

## Alcance
1. Para cada ítem: localizar el equivalente en el refactor (rutas api.php, controllers, páginas), probarlo si existe, y marcar CERRADO o ABIERTO con evidencia (archivo:línea).
2. Cerrar en este mismo ticket los que sean chicos (estimación ≤ media jornada c/u): típicamente T3.5 (un endpoint GET) y T4.1 (formato de export).
3. Para los que resulten grandes (posiblemente T3.2, T3.6, T3.7): escribir ticket de cierre propio en `plan/tickets/` siguiendo el formato de esta cola (modelo, skills, regla 0.3, criterio) y dejarlo listo para asignar.
4. Actualizar `docs/comparacion/GAPS_PENDIENTES_v2.md` con el estado real verificado.

## Criterio de aceptación
Los 5 ítems con veredicto y evidencia; los chicos cerrados con tests; los grandes con ticket nuevo bien formado; doc de gaps actualizado.
