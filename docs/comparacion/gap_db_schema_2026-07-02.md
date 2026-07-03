# Gap de esquema de BD — sis-bipay (fuente) vs migracion (destino)

Fecha: 2026-07-02. Ambas BD en el mismo contenedor MySQL del VPS
(`briselmaquerabitel-dbbitelbris-btkuij`), usuario `db_user_bitel`.

- sis-bipay: 52 tablas
- migracion: 56 tablas

## Tablas en sis-bipay que NO existen en migracion (16)

Requieren migración Laravel nueva (salvo las marcadas como posible rename):

| Tabla legacy | Feature asociado | ¿Rename en Laravel? |
|---|---|---|
| clientes_estado | Pilar 1: Altas/Churn (estado de clientes por ciclo) | No — falta |
| lineas_morosidad | Pilar 1: Morosidad on-demand | No — falta |
| solicitudes_extraccion | Pilar 1: cola de extracción on-demand | No — falta |
| integrador_credenciales | Integrador Bipay (credenciales por tienda + token agente) | No — falta (módulo final) |
| bitel_movimientos_diarios | Movimientos diarios Bitel (heatmap, cuadre) | No — falta |
| bitel_operaciones_detalle | Detalle de operaciones Bitel | No — falta |
| bitel_apoyos | Apoyos entre cajeros (CRM/postpago) | No — falta |
| bitel_historico_queue | Cola de histórico Bitel | No — falta |
| cashout_operaciones | Cashout Bipay | No — falta |
| tesoreria_clasificacion | Clasificación de tesorería (cuadre) | No — falta |
| excepciones_jornada | Excepciones de jornada (asistencia) | No — falta |
| log_ediciones_asistencia | Auditoría de ediciones de asistencia | No — falta |
| log_resolucion_atribucion | Auditoría de resolución de atribución | No — falta |
| sys_config | Config del sistema (clave/valor) | Posible — verificar vs config Laravel |
| crm_clientes | CRM clientes | PROBABLE rename → `clientes` (migracion) — verificar columnas |
| crm_interacciones | CRM interacciones | PROBABLE rename → `interacciones_crm` (migracion) — verificar |

## Tablas solo en migracion (Laravel/infra o renombradas) — informativo

cache, cache_locks, failed_jobs, job_batches, jobs, migrations,
password_reset_tokens, personal_access_tokens, sessions, users (infra Laravel);
clientes, interacciones_crm, leads (CRM Laravel);
comprobantes, ventas, venta_items, venta_equipos, venta_lineas,
venta_chip_movimientos, reporte_comisiones_operativas (modelo de ventas Laravel).

## Pendiente de verificar a nivel columnas
- crm_clientes vs clientes / crm_interacciones vs interacciones_crm (¿misma info?).
- sys_config vs mecanismo de config del refactor.
- Tablas compartidas por nombre: confirmar que no falten columnas nuevas de sis_bipay
  (ej. reportes, reporte_categorias, inventario_tiendas pudieron ganar columnas nuevas).
