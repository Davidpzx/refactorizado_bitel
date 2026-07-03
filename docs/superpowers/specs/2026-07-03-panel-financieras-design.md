# Spec — Gap P0 #5: Panel Financieras — recálculo de ganancia, auditoría y lock

Fecha: 2026-07-03
Estado: DISEÑO FINAL (decisiones tomadas por el usuario, sin opciones abiertas)

Ver hallazgos completos del legacy en `docs/superpowers/specs/2026-07-03-panel-financieras-DRAFT.md`.

## Decisión: Opción A — paridad legacy

Si el costo del equipo aún no está fijado (`venta_equipos.costo_snap <= 0`), se **permite** confirmar el
desembolso conservando la ganancia previa (`ganancia_snap` sin tocar), tal como operaba el legacy
(`confirmar_desembolso.php:48-54`). La ganancia se recalcula **solo** cuando `costo_snap > 0`:

```
venta_equipos.ganancia_snap = venta_equipos.precio_venta - venta_equipos.costo_snap
```

Se descarta la Opción B (bloquear confirmación si falta costo) porque cambiaría el comportamiento
operativo frente al legacy y podría trabar el panel con históricos sin costo.

## Migración

Nueva migración `backend/database/migrations/2026_07_03_000001_add_desembolso_auditoria_to_ventas.php`,
siguiendo el patrón de guards `hasColumn`/`hasTable` usado en `2026_06_14_000001_fix_legacy_schema_drift.php`
y `2026_06_14_000001_add_chips_descontados_to_ventas.php`:

- `ventas.desembolso_confirmado_por` — `unsignedBigInteger nullable`. Sin FK estricta (política del repo
  de evitar drift de tipos frente a `usuarios`/`agentes`).
- `ventas.desembolso_confirmado_en` — `timestamp nullable`.
- Índice sobre `desembolso_confirmado_en`.

La auditoría vive en `ventas` (no en `venta_equipos`) porque `comision_estado` —el campo que gatilla el
flujo de confirmación/reversión— ya vive en `ventas`.

## Backend — `PanelFinancierasController`

### `confirmarDesembolso(int $id)`

1. `DB::transaction(...)`.
2. `DB::table('ventas')->where('id', $id)->lockForUpdate()->first()` dentro de la transacción.
3. Si no existe → 404 (igual que hoy).
4. Si `comision_estado !== 'PENDIENTE'` → **422** "Ya fue confirmado o no está pendiente." (antes era 404;
   se cambia a 422 para distinguir "no existe" de "doble confirmación", y porque el spec de tests lo exige
   explícitamente). Sin cambios en BD.
5. Cargar `venta_equipos` de esa venta (`where('venta_id', $id)->lockForUpdate()->first()`).
6. Si existe el `venta_equipo` y `costo_snap > 0`: recalcular y persistir
   `ganancia_snap = precio_venta - costo_snap` en `venta_equipos`.
   Si `costo_snap <= 0` (o no hay `venta_equipo`): no tocar `ganancia_snap`.
7. Calcular `comisionEquipo` igual que hoy (fallback `EQUIPO_ESTANDAR` de `config_comisiones`, default S/5).
8. `UPDATE ventas`: `comision_estado='APROBADA'`, `comision_generada=$comisionEquipo`,
   `desembolso_confirmado_por=Auth::id()`, `desembolso_confirmado_en=now()`.
9. Commit implícito al salir del closure de `DB::transaction`.

### `revertirDesembolso(int $id)`

1. `DB::transaction(...)`.
2. Lock de la fila `ventas` con `lockForUpdate()`, exigir `comision_estado='APROBADA'` (si no, 404 como hoy).
3. `UPDATE ventas`: `comision_estado='PENDIENTE'`, `comision_generada=0`,
   `desembolso_confirmado_por=null`, `desembolso_confirmado_en=null`.
4. **No** se toca `venta_equipos.ganancia_snap` — el recálculo de ganancia es una corrección de dato, no
   un estado que deba revertirse junto con la comisión.

### Concurrencia en tests

`sqlite` (usado en tests) no ejerce carreras reales con `lockForUpdate`. La protección real depende del
lock+transacción en MySQL de producción. El test de concurrencia cubre el guard de estado con dos llamadas
HTTP secuenciales a `confirmarDesembolso` sobre la misma venta: la segunda debe responder 422 y no debe
haber mutado `comision_generada` ni la auditoría de la primera confirmación.

## Frontend — `PanelFinancierasPage.tsx`

El panel legacy mostraba fecha y usuario de quien confirmó (`panel_financieras.php:279-302`). Se agrega:

- Campos nuevos en `FinancieraItem`: `desembolso_confirmado_por_nombre?: string | null`,
  `desembolso_confirmado_en?: string | null` (el backend en `index()` debe hacer join a `usuarios` para el
  nombre del confirmador y devolver estos dos campos).
- En la tabla, cuando `comision_estado === 'APROBADA'`, mostrar debajo del badge de estado (o en una celda
  contigua) "Confirmado por {nombre} el {fecha}" en texto pequeño (`text-xs text-kyro-subtle`), igual patrón
  visual que la celda de antigüedad.
- `npx tsc --noEmit` debe quedar limpio tras el cambio.

## Tests (`backend/tests/Feature/PanelFinancierasDesembolsoTest.php`)

1. Confirmar con `costo_snap > 0` → `ganancia_snap` recalculada, venta `APROBADA`,
   `desembolso_confirmado_por`/`_en` seteados.
2. Confirmar con `costo_snap = 0` → conserva `ganancia_snap` previa, venta igual `APROBADA` con auditoría.
3. Doble confirmación (dos llamadas HTTP secuenciales a la misma venta) → segunda responde 422, sin
   cambios adicionales en BD tras la primera.
4. Revertir → vuelve a `PENDIENTE`, limpia `desembolso_confirmado_por`/`_en`, **no** modifica
   `ganancia_snap`.
