# TICKET-030 — Monitor de Fraude de Dispositivos (UI faltante) + flujo Ingreso Stock 2 pasos

- **Modelo asignado:** Opus 4.8 (gap de seguridad/auditoría + decisión de flujo)
- **Skills obligatorias:** headroom, superpowers, frontend-design
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si no alcanza, pedir división ANTES (los 2 puntos son separables).
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Origen:** QA visual ticket-026 (04-qa-visual-B.md hallazgo más grave, 04-qa-visual-D1.md)

## Alcance
1. **Monitor de Fraude de Dispositivos — gap de seguridad SIN ticket previo:** el legacy muestra en `panel_asistencias.php` una tabla viva de fraude de dispositivos; el backend del refactor YA escribe `log_fraude_dispositivo` pero NINGUNA UI lo muestra. Crear: endpoint index (admin) sobre esa tabla + panel/tabla en la página de Asistencias (Gestión) replicando la semántica del legacy (qué columnas, qué acciones — leer el PHP legacy). Tests del endpoint.
2. **Ingreso Stock:** el refactor colapsó el flujo legacy de 2 pasos ("tienda ingresa stock SIN precio → gerencia fija el precio después" — así funciona revisar_stock/precios pendientes) en un solo paso dentro del modal de Ver Inventario. Restaurar la semántica de 2 pasos SIN romper lo existente: el ingreso no debe exigir precio, y lo ingresado sin precio debe aparecer en Precios pendientes (RevisarStockPage, que ya existe del ticket-023). Verificar contra el legacy cómo marca "sin precio".

## Criterio de aceptación
Panel de fraude visible para admin con datos del log real (seed de prueba en QaDemoSeeder si hace falta); ingreso sin precio fluye hacia Precios pendientes end-to-end; tests verdes (suite completa); `tsc`+`build` limpios.
