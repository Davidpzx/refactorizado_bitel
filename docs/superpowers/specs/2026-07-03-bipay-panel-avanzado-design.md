# Spec — Panel Bipay avanzado: cuentas huérfanas, export transacciones, locks activos

Fecha: 2026-07-03
Estado: DISEÑO FINAL

Fuente del gap: `docs/comparacion/gap_gerencia_financiero_2026-07-02.md`, sección 12 "Panel Bipay", filas:
"Cuentas Huérfanas", "Vincular Cuenta Huérfana → convertir a MADRE", "Locks Activos (tiendas operando)" y
"Historial de Transacciones — filtros + export Excel". Legacy leído: `E:\laragon\www\sis_bipay\gerencia\panel_bipay.php`
(1789 líneas — bloque de acciones POST líneas 1-215, lectura de estado líneas 217-340, export Excel
líneas 341-368, render huérfanas líneas 622-694, render locks activos líneas 707-723).

Controller destino: `app/Http/Controllers/Api/BipayController.php` (895 líneas, ya existente — no se crea
un controller nuevo, se extiende). Página destino: `frontend/src/pages/bipay/PanelBipayPage.tsx`.

## Gap 1 — Cuentas Huérfanas

### Semántica legacy

Una cuenta "huérfana" es un registro `tipo='HIJO'` con `cuenta_madre_id` vacío (auto-registrada por
sincronización, nunca vinculada a una MADRE). El legacy las calcula filtrando en PHP el mismo array que ya
carga para la sección de cuentas (`$hijos_huerfanos = array_filter($hijos, fn($h) => empty($h['cuenta_madre_id']))`),
no con una query aparte. La acción `vincular_huerfana` (líneas 168-188) recibe `cuenta_id` + `razon_social`
(+ `alias` opcional, si vacío usa `razon_social`) y hace `UPDATE cuentas_bipay SET razon_social=?, alias=?,
tipo='MADRE' WHERE id=?` — sin verificar que la cuenta siga siendo huérfana en ese momento (se confía en que
el botón solo aparece sobre huérfanas reales en la UI).

### Backend

`BipayController::saldo()` — agregar `cuenta_madre_id` al `SELECT` (guardado con
`Schema::hasColumn('cuentas_bipay', 'cuenta_madre_id')`, mismo patrón defensivo que ya usan
`crearCuenta`/`editarCuenta`). Sin este campo el frontend no puede distinguir huérfanas de HIJOs vinculados.
No se toca la agrupación visual Madre→Hijo (gap separado, no asignado a esta tarea).

Nuevo método `vincularHuerfana(Request $request, int $id): JsonResponse`:

- `role:admin` (gating de mutación, igual que `crearCuenta`/`editarCuenta`/`eliminarCuenta`).
- Requiere `Schema::hasTable('cuentas_bipay')` y `Schema::hasColumn('cuentas_bipay', 'razon_social')` — si
  falta, `422` con mensaje explicativo (mismo patrón `tablasFaltantes`/columnas guardadas del resto del
  controller).
- Valida `razon_social` (`required|string|max:150`) y `alias` (`nullable|string|max:100`).
- 404 si la cuenta no existe.
- Update: `razon_social`, `alias` (el enviado, o `razon_social` si vino vacío), `tipo='MADRE'`. Sin chequeo
  de que siga siendo huérfana (paridad literal con el legacy — decisión consciente, no un descuido).

Ruta: `POST bipay/cuentas/{id}/vincular-huerfana` → `role:admin`, junto a las demás rutas de mutación de
cuentas en `routes/api.php`.

### Frontend

En la tab "Cuentas" de `PanelBipayPage.tsx`, debajo de la tabla de cuentas registradas: sección "Cuentas
Huérfanas (Pendientes de Vincular)" que solo se renderiza si hay al menos una (`tipo==='HIJO' &&
!cuenta_madre_id`). Cada tarjeta muestra alias, N° cuenta, saldo bipay/anypay, y un botón "Vincular" que abre
un formulario inline (o modal simple) pidiendo Razón Social (obligatorio) y Alias (opcional) y llama al
nuevo endpoint; on success invalida `['bipay-saldo']`.

## Gap 2 — Locks Activos (tiendas operando)

### Semántica legacy vs. destino — decisión de mapeo

El legacy usa una tabla `bipay_locks` (`cuenta_bipay_id`, `tienda_codigo`, `expira_en`) dedicada a
bloqueos de concurrencia por tienda. **Esa tabla no existe en el destino.** El refactor ya implementó un
concepto equivalente con otro nombre: `bipay_cooldowns` (`cuenta_bipay_id`, `tienda_codigo`,
`cooldown_hasta`) — usado por `estadoCajero()`/`actualizarCajero()` para impedir que una tienda vuelva a
declarar su tramo antes de tiempo, exactamente la misma semántica de "lock activo mientras la tienda está en
su ventana de operación". El propio gap doc lo confirma: *"el concepto interno `bipay_cooldowns` existe
pero sin vista dedicada"*. Por tanto **no se crea una tabla `bipay_locks` nueva**; se construye la vista de
"Locks Activos" sobre `bipay_cooldowns`, reusando los helpers privados ya existentes `ahoraCajero()` y
`segundosRestantes()` (evita duplicar lógica de zona horaria).

### Backend

Nuevo método `locksActivos(Request $request): JsonResponse`:

- `role:admin`.
- Si `bipay_cooldowns` no existe: `['locks' => []]`.
- Query: `bipay_cooldowns` join `cuentas_bipay` (alias) join `tiendas` (nombre), sin filtro `WHERE` por fecha
  en SQL (evita comparar timestamps con TZ distinta entre app y motor de BD, mismo criterio que el resto del
  controller que siempre filtra "activo" en PHP vía `segundosRestantes`); se filtra en PHP con
  `segundosRestantes($cooldown_hasta, $ahora) > 0` y se ordena por `cooldown_segs` ascendente (el que
  expira primero, primero) — igual orden que el legacy (`ORDER BY expira_en ASC`).
- Cada fila: `cuenta_bipay_id`, `tienda_codigo`, `tienda_nombre`, `cuenta_alias`, `expira_en` (el
  `cooldown_hasta` crudo, para mostrar hora), `cooldown_segs`.

Ruta: `GET bipay/locks-activos` → `role:admin`.

### Frontend

Nueva tab "Locks Activos" en `PanelBipayPage.tsx` (ícono `Lock`, solo visible — no requiere permiso especial
de UI porque el backend ya gatea admin). Grid de tarjetas: tienda, cuenta, "Expira en mm:ss" con
cuenta regresiva simple (recalculada client-side a partir de `cooldown_segs` + tiempo transcurrido desde el
fetch, sin polling agresivo — se refresca al cambiar de tab). Si no hay locks activos: mensaje "Sin locks
activos — todas las tiendas pueden declarar."

## Gap 3 — Historial de Transacciones: filtro Tipo + export Excel

### Estado actual

`BipayController::transacciones()` ya soporta `fecha_desde`/`fecha_hasta`/`cuenta_id`, pero:
(a) el frontend nunca envía `cuenta_id` (el estado `txFilters.cuenta_id` existe pero no hay `<select>` que lo
edite), y (b) no existe filtro por `tipo_operacion` en backend ni frontend. El export a Excel no existe en
absoluto (a diferencia del legacy `?export=excel`, líneas 341-368).

### Backend

Refactor mínimo: extraer la construcción de la consulta filtrada de `transacciones()` a un método privado
`consultaTransacciones(Request $request): array` que devuelve `[Builder $query, string $desde, string
$hasta]`, reutilizado por el endpoint JSON y por el export (evita duplicar los 4 filtros). Se agrega el
join a `usuarios` (`tb.creado_por = u.id`) para exponer `operador` (nombre), que hoy no se devuelve pero
existe en el legacy y lo necesita el export.

Nuevo filtro `tipo_operacion`, validado contra el enum real del destino — **no** el del legacy
(`RECARGA,TRANSFERENCIA,AJUSTE_MANUAL,DECLARACION_DIA,CIERRE_DIA`), porque `BipayController::ajustar()` ya
persiste el tipo como `'AJUSTE'` (no `'AJUSTE_MANUAL'`) desde que existe en el destino. El enum destino es:
`RECARGA, TRANSFERENCIA, AJUSTE, DECLARACION_DIA, CIERRE_DIA`. Un valor fuera de esa lista se ignora
(mismo criterio permisivo que el legacy, que solo aplica el filtro si `in_array` matchea).

Nuevo método `exportarTransacciones(Request $request): StreamedResponse`:

- `role:admin`.
- Mismos filtros que `transacciones()` (sin paginar — exporta el rango completo filtrado, igual que el
  legacy).
- Una hoja "Historial Bipay", columnas: `Fecha | Tipo | Origen | Destino | Monto | Ref / Notas | Operador`
  (idénticas al legacy, mismo orden).
- **Detalle especial `AJUSTE`** (equivalente al `AJUSTE_MANUAL` legacy, líneas 1152-1160): antepone a la
  observación `"Ant: S/ {antes} → S/ {despues}"`. Ojo con la semántica real de los campos en el destino
  (**distinta** de la del legacy, hay que derivarla correctamente y no copiar la fórmula legacy a ciegas):
  en `BipayController::ajustar()`, `saldo_origen_pre`/`saldo_anypay_pre` se graban con el saldo **previo**
  al ajuste (se leen del row antes del `UPDATE`) y `monto` es la diferencia (`nuevo_total - saldo_actual
  previo`). Por tanto `antes = saldo_origen_pre + saldo_anypay_pre` y `despues = antes + monto` — al revés
  de la fórmula legacy (que trataba esos campos como el valor posterior). Se usa la fórmula correcta para el
  destino.
- Nombre de archivo: `bipay_historial_{desde}_a_{hasta}.xlsx`. Mismo patrón `estiloCabecera()` /
  `response()->streamDownload()` que `EstadisticasController::exportar()`.

Rutas:
```php
Route::get('bipay/transacciones', [BipayController::class, 'transacciones']);
Route::get('bipay/transacciones/exportar', [BipayController::class, 'exportarTransacciones'])->middleware('role:admin');
```

### Frontend

Tab "Transacciones" de `PanelBipayPage.tsx`:
- Nuevo `<select>` "Cuenta" (opciones desde `saldoData.cuentas`) que sí alimenta `txFilters.cuenta_id`.
- Nuevo `<select>` "Tipo" con las 5 opciones del enum destino.
- Botón "Exportar Excel" (mismo patrón `fetch` + blob + `<a download>` que `TicketsPage.tsx::exportarExcel()`),
  usando los filtros ya aplicados (`txApplied`), solo visible si el usuario es admin (backend ya gatea, pero
  se oculta para no mostrar un botón que siempre devolvería 403 a un usuario tienda).

## Permisos — resumen

Las 3 rutas nuevas (`vincular-huerfana`, `locks-activos`, `transacciones/exportar`) son `role:admin`,
replicando que `panel_bipay.php` completo es una pantalla de `gerencia/` (acceso admin en el legacy). Esto
es un subconjunto estricto de lo que ya exponía `saldo()`/`transacciones()` sin gate (de solo-lectura,
sin cambios), por lo que no hay regresión de permisos existente, solo se agregan gates nuevos a
funcionalidad nueva.

## Tests (`backend/tests/Feature/`)

Nuevo archivo `BipayPanelAvanzadoTest.php` (crea su propio schema mínimo de `cuentas_bipay` /
`transacciones_bipay` / `bipay_cooldowns` / `tiendas`, mismo patrón que `BipayAdminTest`/`BipayCajeroTest`):

1. `vincular_huerfana` — admin vincula una cuenta HIJO sin madre → `tipo` pasa a `MADRE`,
   `razon_social`/`alias` actualizados.
2. `vincular_huerfana` — rol no-admin → 403.
3. `vincular_huerfana` — cuenta inexistente → 404.
4. `locksActivos` — cooldown vigente aparece, cooldown vencido no aparece, ordenado por expiración.
5. `locksActivos` — rol no-admin → 403.
6. `exportarTransacciones` — filtra por rango+cuenta+tipo, devuelve xlsx (`content-type`,
   `IOFactory::load` cuenta filas de la hoja), incluye detalle "Ant → Después" en fila `AJUSTE`.
7. `exportarTransacciones` — rol no-admin → 403.
8. `saldo()` incluye `cuenta_madre_id` cuando la columna existe.

Fallas preexistentes que no son de este cambio (no tocar): `SecurityParityTest`,
`PhaseCOperationalParityTest`, `ReporteStoreParityTest`.
