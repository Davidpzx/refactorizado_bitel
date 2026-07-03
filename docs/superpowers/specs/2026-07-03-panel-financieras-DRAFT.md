# DRAFT - Gap P0 #5: Panel Financieras sin recálculo de ganancia, auditoría ni lock

Fecha: 2026-07-03  
Estado: DRAFT pendiente de decisión del usuario

## Resumen ejecutivo

El legacy confirmaba desembolsos en una transacción, bloqueaba la fila con `FOR UPDATE`, rechazaba doble confirmación, recalculaba la ganancia final con `precio_total - costo_al_registrar`, liberaba comisión y guardaba quién/cuándo confirmó. El refactor solo actualiza `ventas.comision_estado` y `ventas.comision_generada` con un `UPDATE` plano; no bloquea, no recalcula `venta_equipos.ganancia_snap` y no tiene columnas de auditoría de desembolso.

## Hallazgos legacy

- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:13-22` exige admin, método POST e ID `reporte_categoria_id`.
- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:24-35` abre transacción y selecciona la fila de `reporte_categorias` con `FOR UPDATE`.
- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:39-46` aborta si no existe o si `comision_estado` ya no es `PENDIENTE`; esto protege contra doble confirmación.
- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:48-54` decodifica el JSON y recalcula `ganancia_final = precio_total - costo_al_registrar` cuando el costo es mayor a cero; si no, conserva la ganancia previa.
- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:55-79` calcula la comisión liberada del agente con fallback `EQUIPO_ESTANDAR` de `config_comisiones`, default S/ 5.00.
- `E:\laragon\www\sis_bipay\gerencia\confirmar_desembolso.php:81-94` actualiza `comision_estado='APROBADA'`, `desembolso_confirmado_en=NOW()`, `desembolso_confirmado_por=?` y el JSON `detalle` con `comision_agente` y `ganancia`.
- La tabla legacy tenía las columnas de auditoría en el registro de venta/reporting: `E:\laragon\www\sis_bipay\DB.sql:1443-1453` define `reporte_categorias.financiera`, `comision_estado`, `desembolso_confirmado_en`, `desembolso_confirmado_por`, `monto`, `cantidad`, `detalle`.
- El panel legacy mostraba esa auditoría: `E:\laragon\www\sis_bipay\gerencia\panel_financieras.php:49-65` selecciona `desembolso_confirmado_en` y `confirmado_por_nombre`; `E:\laragon\www\sis_bipay\gerencia\panel_financieras.php:279-302` muestra fecha y usuario confirmado.
- El panel legacy calculaba la ganancia visible con la misma fórmula base: `E:\laragon\www\sis_bipay\gerencia\panel_financieras.php:223-229` usa `precio_total`, `costo_al_registrar` y `precio_total - costo`.

## Estado del refactor

- `backend/app/Http/Controllers/Api/PanelFinancierasController.php:31-50` lista desembolsos desde el esquema normalizado `ventas` + `venta_equipos`, usando `ve.tipo_pago='CUOTAS'`.
- `backend/app/Http/Controllers/Api/PanelFinancierasController.php:131-134` busca la venta pendiente con un `first()` sin transacción ni lock.
- `backend/app/Http/Controllers/Api/PanelFinancierasController.php:136-144` calcula la comisión del agente con `EQUIPO_ESTANDAR`, default S/ 5.00.
- `backend/app/Http/Controllers/Api/PanelFinancierasController.php:146-149` hace un `UPDATE ventas` plano a `APROBADA` y `comision_generada`.
- `backend/app/Http/Controllers/Api/PanelFinancierasController.php:157-177` existe `revertirDesembolso()`, también sin transacción/lock y sin restaurar auditoría ni ganancia.
- `backend/database/migrations/2026_06_07_200000_create_core_tables.php:70-89` define `ventas` con `monto_total`, `efectivo_inicial`, `comision_generada`, `comision_estado`, pero sin `desembolso_confirmado_por` ni `desembolso_confirmado_en`.
- `backend/database/migrations/2026_06_07_200000_create_core_tables.php:93-109` define `venta_equipos` con `financiera`, `precio_venta`, `costo_snap`, `ganancia_snap` y `por_cobrar_financiera`.
- `backend/database/migrations/2026_06_14_000001_add_chips_descontados_to_ventas.php:15-20` solo agrega `chips_descontados` a `ventas`.
- El modelo `Venta` permite llenar `comision_estado` y `comision_generada`, pero no auditoría de desembolso: `backend/app/Models/Venta.php:17-22`.
- El modelo `VentaEquipo` ya tiene `costo_snap` y `ganancia_snap`: `backend/app/Models/VentaEquipo.php:14-25`.
- Ya existe lógica de actualización puntual de costo/ganancia en `venta_equipos`: `backend/app/Http/Controllers/Api/ReporteController.php:1285-1295` calcula `ganancia = precioVenta - precioCosto` y actualiza `precio_venta`, `costo_snap`, `ganancia_snap`.

## Columnas/migración necesarias

Mínimo para paridad de auditoría:

- `ventas.desembolso_confirmado_por` nullable integer/unsignedBigInteger, sin FK estricta si se mantiene la política actual de evitar drift de tipos.
- `ventas.desembolso_confirmado_en` nullable timestamp/datetime.
- Índice sugerido: `ventas(comision_estado, desembolso_confirmado_en)` o al menos índice sobre `desembolso_confirmado_en` si el panel filtra confirmados por mes.

No parece necesario agregar una columna nueva de ganancia si la fuente canónica para equipos sigue siendo `venta_equipos.ganancia_snap`. La corrección de paridad sería recalcular y persistir `venta_equipos.ganancia_snap = venta_equipos.precio_venta - venta_equipos.costo_snap` al confirmar, cuando `costo_snap > 0`.

Columnas opcionales si se quiere auditoría específica del recálculo:

- `venta_equipos.ganancia_recalculada_en` nullable timestamp.
- `venta_equipos.ganancia_recalculada_por` nullable integer/unsignedBigInteger.

Estas opcionales no existían como columnas en legacy; el legacy guardaba auditoría de confirmación en `reporte_categorias` y mutaba el JSON `detalle`.

## Opciones de diseño

### Opción A - Paridad mínima sobre esquema normalizado

En `confirmarDesembolso`, envolver en `DB::transaction`, leer `ventas` con `lockForUpdate`, unir/cargar `venta_equipos`, exigir `comision_estado='PENDIENTE'`, recalcular `venta_equipos.ganancia_snap` si `costo_snap > 0`, actualizar `ventas` a `APROBADA`, liberar comisión y guardar `desembolso_confirmado_por/en`.

Trade-offs:
- A favor: replica el comportamiento operativo del legacy con cambios acotados.
- A favor: corrige doble POST y deja trazabilidad de auditoría.
- A favor: usa `venta_equipos.ganancia_snap`, que ya es la fuente normalizada de ganancia.
- En contra: si el costo sigue en cero, conserva la ganancia previa/nula; el panel debe seguir mostrando "pendiente de costo".

### Opción B - Confirmación estricta: bloquear si falta costo

Igual que la opción A, pero si `venta_equipos.costo_snap <= 0` o `ganancia_snap` es nula, no permite confirmar el desembolso hasta fijar costo.

Trade-offs:
- A favor: evita confirmar desembolsos con ganancia incompleta o subestimada.
- A favor: fuerza integridad financiera antes de liberar comisión.
- En contra: cambia la operación frente al legacy, que permitía confirmar y conservaba la ganancia previa si no había costo.
- En contra: puede trabar el panel si hay históricos con costos faltantes.

## Preguntas abiertas

1. ¿La ganancia debe recalcularse con `venta_equipos.precio_venta - venta_equipos.costo_snap` siempre, o solo cuando `costo_snap > 0` como el legacy?
2. ¿Se debe permitir confirmar desembolso si el costo aún falta?
3. ¿La auditoría de confirmación debe vivir en `ventas` porque el estado de comisión vive ahí, o en `venta_equipos` porque la financiera/ganancia vive ahí?
4. ¿`revertirDesembolso()` debe limpiar `desembolso_confirmado_por/en` y revertir `ganancia_snap`, o solo regresar comisión a `PENDIENTE`?
