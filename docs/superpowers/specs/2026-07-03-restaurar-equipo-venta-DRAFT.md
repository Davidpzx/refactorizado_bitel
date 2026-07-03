# DRAFT - Gap P0 #3: `restaurar_equipo_manual` no anula la venta asociada

Fecha: 2026-07-03  
Estado: DRAFT pendiente de decisión del usuario

## Resumen ejecutivo

El legacy no solo devolvía el equipo vendido a stock: dentro de una transacción también eliminaba la fila de venta del reporte diario (`reporte_categorias`) que coincidía con el IMEI. El refactor Laravel devuelve el equipo a `DISPONIBLE` y escribe historial, pero no toca `ventas` ni `venta_equipos`; por eso la venta sigue contando en reportes y comisiones.

## Hallazgos legacy

- `E:\laragon\www\sis_bipay\api\restaurar_equipo_manual.php:24-39` valida que el equipo exista, esté en estado `VENDIDO` y abre transacción.
- `E:\laragon\www\sis_bipay\api\restaurar_equipo_manual.php:42-51` actualiza `inventario_tiendas`: `estado='DISPONIBLE'`, `fecha_venta=NULL`, `vendido_por_id=NULL`, `reporte_venta_id=NULL`.
- `E:\laragon\www\sis_bipay\api\restaurar_equipo_manual.php:59-83` elimina la venta del reporte: `DELETE FROM reporte_categorias WHERE tipo='equipos_accesorios'` y match por `reporte_id` + `JSON_EXTRACT(detalle, '$.identificador') = IMEI`; si no hay `reporte_venta_id`, hace fallback por IMEI en todos los reportes.
- `E:\laragon\www\sis_bipay\api\restaurar_equipo_manual.php:85-98` registra auditoría en `historial_inventario` con acción `RESCATE_MANUAL`, incluyendo en la observación que se eliminó del reporte cuando aplica.
- `E:\laragon\www\sis_bipay\api\restaurar_equipo_manual.php:103-110` confirma la transacción y responde indicando que el equipo fue eliminado del reporte si hubo filas afectadas.

Conclusión legacy: la reversión era física para la venta/reporting. No marcaba una venta como anulada ni generaba contra-asiento; borraba la fila `reporte_categorias` asociada al equipo.

## Estado del refactor

- `backend/app/Http/Controllers/Api/InventarioController.php:708-724` valida rol admin, existencia del equipo y estado `VENDIDO`.
- `backend/app/Http/Controllers/Api/InventarioController.php:727-731` solo actualiza el equipo a `DISPONIBLE` y limpia `fecha_venta`/`vendido_por_id`.
- `backend/app/Http/Controllers/Api/InventarioController.php:733-742` inserta `RESCATE_MANUAL` en `historial_inventario`.
- `backend/app/Http/Controllers/Api/InventarioController.php:744-747` responde éxito sin tocar `ventas`, `venta_equipos`, reportes ni comisiones.
- El registro normalizado de venta de equipo se crea en `backend/app/Http/Controllers/Api/ReporteController.php:500-516` (`ventas`) y `backend/app/Http/Controllers/Api/ReporteController.php:518-532` (`venta_equipos`, incluyendo `inventario_tienda_id`, IMEI, precio, costo y `ganancia_snap`).
- Existe un helper de reversión por reporte completo: `backend/app/Http/Controllers/Api/ReporteController.php:695-754` repone chips/equipos y borra `venta_equipos`, `venta_lineas` y `ventas`, pero es privado y opera sobre todas las ventas de un reporte, no sobre un solo equipo.
- El modelo ya tolera estado `ANULADA`: `backend/app/Http/Controllers/Api/VentaController.php:43-48` y `backend/app/Http/Controllers/Api/VentaController.php:88-92` validan `comision_estado` con `ANULADA`; `backend/app/Http/Controllers/Api/PlanillaController.php:319-324`, `backend/app/Http/Controllers/Api/PlanillaController.php:352-358` y `backend/app/Http/Controllers/Api/PlanillaController.php:502-507` excluyen `ANULADA` de comisiones.

## Opciones de diseño

### Opción A - Paridad estricta: eliminar la venta normalizada

Al restaurar, buscar `venta_equipos` por `inventario_tienda_id` o `imei_serial_snap`, bloquear la venta, devolver stock y borrar `venta_equipos` + `ventas` si esa venta solo contiene ese equipo.

Trade-offs:
- A favor: reproduce el legacy: la venta desaparece de reportes/comisiones.
- A favor: evita adaptar reportes que no filtran `ANULADA`.
- En contra: pierde historial financiero normalizado; la única auditoría queda en `historial_inventario`.
- Riesgo: si en el futuro una `venta` puede tener más de un item asociado, borrar la cabecera completa puede eliminar más de lo deseado.

### Opción B - Anulación lógica de la venta

Al restaurar, marcar `ventas.comision_estado='ANULADA'`, poner `comision_generada=0` y conservar `venta_equipos` como evidencia; devolver stock y registrar historial.

Trade-offs:
- A favor: conserva trazabilidad, útil para auditoría y análisis de errores.
- A favor: planilla ya excluye `ANULADA` en varios cálculos.
- En contra: hay que auditar todos los reportes/KPIs para que excluyan o muestren anuladas explícitamente; por ejemplo dashboards que suman `venta_equipos.ganancia_snap` pueden seguir contando si no filtran.
- En contra: no es paridad estricta con el legacy.

### Opción C - Anulación lógica con ajuste explícito de importes

Marcar `ventas.comision_estado='ANULADA'`, poner `comision_generada=0`, `monto_total=0`, `efectivo_inicial=0` y opcionalmente `venta_equipos.ganancia_snap=0`, manteniendo snapshots del equipo.

Trade-offs:
- A favor: reduce el riesgo de que reportes agregados sigan contando dinero si olvidan filtrar por estado.
- A favor: conserva trazabilidad mínima.
- En contra: destruye parcialmente el valor histórico original de la venta; para auditoría harían falta columnas nuevas tipo `monto_original` o tabla de eventos.
- En contra: mezcla semánticas: `ANULADA` debería bastar, pero se duplicaría con montos en cero.

## Preguntas abiertas

1. ¿Se prefiere paridad exacta con legacy, borrando la venta, o trazabilidad nueva mediante `ANULADA`?
2. Si se adopta `ANULADA`, ¿deben los KPIs/reportes mostrar una sección de ventas anuladas o simplemente excluirlas?
3. ¿La restauración manual debe permitirse si la venta ya fue incluida en una planilla cerrada/pagada, o debe bloquearse y exigir un flujo financiero aparte?
4. ¿Hace falta agregar auditoría en `ventas` (`anulada_por`, `anulada_en`, `motivo_anulacion`) o basta con `historial_inventario`?
