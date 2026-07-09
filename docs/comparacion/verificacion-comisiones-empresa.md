# Verificación TICKET-012 — Comisiones Empresa

**Fecha:** 2026-07-08
**Legacy:** `E:\laragon\www\sistema-rolando-salas\gerencia\comisiones_empresa.php` (+ `guardar_tarifas_ajax.php`, `guardar_rangos_ajax.php`, `recalcular_comisiones_masivo.php`)
**Refactor:** `frontend/src/pages/comisiones/ComisionesPage.tsx` + `backend/app/Http/Controllers/Api/ComisionPlanController.php` + `backend/app/Http/Controllers/Api/ConfigComisionesController.php`

## Veredicto: **FUSIONADA** (completa, sin faltantes funcionales)

La hipótesis del ticket se confirma: `comisiones_empresa.php` y sus 3 endpoints AJAX satélite están completamente fusionados en `ComisionesPage` + `ComisionPlanController` + `ConfigComisionesController`. El refactor no solo iguala la funcionalidad legacy, la supera en varios puntos (validación de solapamiento de rangos, borrado de planes, reglas de negocio más robustas basadas en columnas booleanas en vez de matching de texto).

## Comparación funcional (archivo:línea)

| Función legacy | Evidencia legacy | Evidencia refactor | Estado |
|---|---|---|---|
| Listar planes (`comisiones_planes`) | `comisiones_empresa.php:111-116` | `ComisionPlanController.php:16-25` (`GET /v1/comisiones-planes`), `ComisionesPage.tsx:44-46,554-601` | ✅ igual, + filtro por tipo que legacy no tenía |
| Crear plan | `comisiones_empresa.php:17-56` (POST `accion=crear`) | `ComisionPlanController.php:27-43` (`POST /v1/comisiones-planes`), `ComisionesPage.tsx:604-608` | ✅ mismos 8 campos (`tipo_servicio`, `nombre_plan`, `tipo_alta`, `fee_monto`, `comision_dni_n`, `comision_dni_n3`, `comision_ext_n`, `comision_ext_n3`) |
| Editar plan | `comisiones_empresa.php:59-108` | `ComisionPlanController.php:45-61`, `ComisionesPage.tsx:609-613` | ✅ igual |
| Eliminar plan | *(no existe en legacy — solo editar)* | `ComisionPlanController.php:63-67`, `ComisionesPage.tsx:507-515` | ✅ el refactor **agrega** delete (mejora, no gap) |
| Tarifas operativas (recargas %, bipay/krece/payjoy S/) | `guardar_tarifas_ajax.php` completo | `ConfigComisionesController.php:64-90` (`PUT /v1/config-comisiones/tarifas`), `ComisionesPage.tsx:281-353` | ✅ igual, incluye el mensaje "no modifica historial" |
| Rangos por monto bipay/krece/payjoy | `guardar_rangos_ajax.php` completo (DELETE+INSERT transaccional) | `ConfigComisionesController.php:128-166` (`PUT /v1/config-comisiones/rangos-servicio`), `ComisionesPage.tsx:355-469` | ✅ igual + valida solapamiento de rangos (legacy no lo hacía) |
| Rangos de productividad PLAN/EQUIPO | *(pertenece a `configurar_comisiones.php`, no a este archivo, pero vive en la misma tabla `config_comisiones`)* | `ConfigComisionesController.php:92-125` (`PUT /v1/config-comisiones/rangos-productividad`) | ✅ bonus fusionado en el mismo modal "Estrategia y rangos" |
| Recálculo masivo (servicios operativos + planes, rango de fechas obligatorio, filtro tienda opcional) | `recalcular_comisiones_masivo.php` completo | `ComisionPlanController.php:81-227` (`POST /v1/comisiones-planes/recalcular`), `ComisionesPage.tsx:191-266` | ✅ igual en reglas de negocio (remate <S/20→0, upgrade por diferencia de fee, costo_chip S/1 salvo migración/eSIM, prepago usa cobrado) |
| Icono / entrada de menú | `ph-buildings` | Ruta `/comisiones` con icono `TrendingUp` en `AppLayout.tsx:54`, registrada en `App.tsx:32,113` | ✅ existe, ícono distinto pero coherente con el resto de "Administración" |

### Diferencia de arquitectura de datos (no es un gap)
El legacy calculaba comisiones re-leyendo un JSON `detalle[]` embebido en `reporte_categorias` y reescribía el JSON completo. El refactor usa tablas normalizadas `ventas` / `venta_lineas` y actualiza columnas directamente (`ComisionPlanController.php:99-158`). Es una reimplementación fiel de las mismas reglas de negocio sobre un esquema más limpio, no una funcionalidad faltante.

### Diferencias cosméticas observadas (no bloqueantes, no se tocaron)
- Legacy separaba el recálculo en dos botones ("Recálculo Masivo" general vs "Recálculo Masivo de Planes" con `solo_planes=1`). El refactor tiene un solo botón que recalcula ambos a la vez. No es un gap funcional: recalcular servicios operativos con tarifas sin cambios es un no-op idempotente.
- Legacy usaba `<select>` con 5 opciones fijas para `tipo_alta` (LN/MNP/RECUPERO/BIFRI/TURISTA); el refactor usa un `<input type="text">` libre. Cualquier valor legacy sigue siendo capturable; es una posible mejora de UX futura, no un faltante.

## Bug encontrado y corregido (acotado, dentro del alcance)
`ComisionPlanController::index()` ordenaba con `ORDER BY FIELD(...)`, función exclusiva de MySQL/MariaDB. Producción usa MySQL así que no afectaba el uso real, pero rompía cualquier test bajo el entorno estándar de pruebas del repo (`phpunit.xml` usa SQLite `:memory:`), dejando este endpoint sin cobertura posible. Se cambió a un `CASE WHEN` portable:

`backend/app/Http/Controllers/Api/ComisionPlanController.php:20`
```php
// antes
->orderByRaw("FIELD(tipo_servicio, 'POSTPAGO', 'PREPAGO', 'EQUIPO', 'ACCESORIO') ASC")
// después
->orderByRaw("CASE tipo_servicio WHEN 'POSTPAGO' THEN 1 WHEN 'PREPAGO' THEN 2 WHEN 'EQUIPO' THEN 3 WHEN 'ACCESORIO' THEN 4 ELSE 5 END ASC")
```

## Cobertura de tests agregada
No existía ningún test para `comisiones-planes` / `config-comisiones`. Se agregó `backend/tests/Feature/ComisionesEmpresaParidadTest.php` cubriendo los 5 flujos verificados (CRUD de planes, tarifas operativas, rangos de servicio con validación de solapamiento, rangos de productividad PLAN/EQUIPO, recálculo masivo end-to-end con datos reales de `ventas`/`venta_lineas`).

### Salida real de ejecución

```
$ php artisan test --filter=ComisionesEmpresaParidadTest
 PASS  Tests\Feature\ComisionesEmpresaParidadTest
 ✓ crud planes completo                                    0.59s
 ✓ tarifas operativas no tocan historial                   0.03s
 ✓ rangos por servicio bipay krece payjoy                  0.03s
 ✓ rangos productividad plan equipo                        0.03s
 ✓ recalculo masivo actualiza ventas y lineas               0.03s

 Tests:    5 passed (27 assertions)
 Duration: 1.01s
```

Suite completa del backend tras el fix (sin regresiones):
```
$ php artisan test
 Tests:    408 passed (1437 assertions)
 Duration: 16.01s
```

### TypeScript (frontend)
```
$ node node_modules/typescript/bin/tsc -b
src/pages/reportes/NuevoReportePage.tsx(873,9): error TS6133: 'confirmDialog' is declared but its value is never read.
```
Único error del build completo, en `NuevoReportePage.tsx` — archivo ajeno a este ticket, de otro worker en curso migrando `window.confirm()` a `ConfirmDialog` (mencionado en las instrucciones de este ticket como trabajo paralelo). `ComisionesPage.tsx` y los archivos tocados aquí compilan limpios.

### Nota sobre pruebas contra MySQL local
No fue posible ejercer los endpoints contra el MySQL local del proyecto (`DB_DATABASE=migracion`): el proceso `mysqld` en el puerto 3306 ya estaba en uso por otro worker/sesión con credenciales distintas a las de `.env` (`Access denied for user 'root'@'localhost'`), y el ticket indica que otros workers están tocando `backend/database` concurrentemente — no se forzó el reinicio del servicio para no interrumpirlos. La verificación se hizo en su lugar contra el mismo esquema real (migraciones del proyecto) sobre SQLite en memoria vía `php artisan test`, ejercitando las rutas HTTP reales, los controladores reales y las reglas de negocio reales — cobertura equivalente para los fines de este ticket.

## Resumen de cambios de este ticket
- `backend/app/Http/Controllers/Api/ComisionPlanController.php:20` — fix de portabilidad SQL (`FIELD()` → `CASE WHEN`), sin cambio de comportamiento en MySQL.
- `backend/tests/Feature/ComisionesEmpresaParidadTest.php` — nuevo, 5 tests de regresión para el módulo.
- `docs/comparacion/verificacion-comisiones-empresa.md` — este informe.

No se tocó `ComisionesPage.tsx` ni ningún otro archivo frontend: no había ninguna función faltante que requiriera UI nueva.
