# Matriz de Autorización — API v1

Fuente de verdad: `routes/api.php`. Middleware de rol: `role:` → `App\Http\Middleware\EnsureRole`.

## Roles del sistema

| Rol        | Origen                                   | Nivel                          |
|------------|------------------------------------------|--------------------------------|
| `admin`    | `usuarios.rol = 'admin'`                 | Gerencia / acceso total        |
| `tienda`   | `usuarios.rol = 'tienda'`                | Jefe de tienda                 |
| `vendedor` | `usuarios.rol = 'vendedor'` (default BD) | Vendedor — **alias de `tienda`** en `EnsureRole` |
| `agente`   | `usuarios.rol = 'agente'`                | Agente — sin acceso operativo  |
| `gerencia` | respuesta PIN de `Agente.es_gerencia`    | (no emite token Sanctum propio)|

> `EnsureRole` expande `tienda` a `['tienda','vendedor']`: `role:admin,tienda`
> permite admin + tienda + vendedor, y **niega** `agente`.
> Los middleware **componen** (intersección): una ruta en un grupo `role:admin,tienda`
> con `role:admin` propio queda efectivamente **admin-only**.

## Matriz por dominio (SEC-03)

| Dominio                                   | Roles mínimos     | Notas |
|-------------------------------------------|-------------------|-------|
| Clientes (`clientes`)                     | admin, tienda     | |
| Ventas (`ventas`)                         | admin, tienda     | |
| Inventario — lecturas/altas               | admin, tienda     | costos, kardex, matriz, stock, index/store/show, precio-agente |
| Inventario — mutaciones fuertes           | admin             | update, destroy, ajustar-stock-real, restaurar, recalcular-ganancias, precios-matriz |
| Chips (`chips`, historial, cambiar-código)| admin, tienda     | `ajustar-stock-real` → admin |
| Traslados de equipos (`traslados`)        | admin, tienda     | scoping origen/destino en controller |
| Traslados de chips (`traslados-chips`, `inventario-chips`) | admin, tienda | scoping origen/destino en controller |
| Constancias PDF (`constancias/*`)         | admin, tienda     | |
| Tickets (`tickets`)                       | admin, tienda     | scoping por tienda en controller |
| Bipay — saldo / transacciones            | admin, tienda     | |
| Bipay — cajero (estado/actualizar/cierre) | admin, tienda     | `contextoCajero()` exige cuenta vinculada |
| Bipay — recarga/transferir/ajustar/cuentas/locks/export | admin | |
| CRM (`crm/*`, `leads`, `crm/temperatura`) | admin, tienda     | ver hallazgo de scoping abajo |
| Cliente Activo CRM (`clientes-crm`)       | admin, tienda     | |
| RENIEC/SUNAT (`dni/{dni}`, `ruc/{ruc}`)   | admin, tienda     | + throttle 30/min (SEC-04) |
| Dashboard/Historial/Estadísticas          | ver `routes/api.php` | admin o admin,tienda según ruta |
| Administración (usuarios, tiendas, config, facturación, planilla, postpago, auditoría, cuadre, financieras, diagnóstico, heatmap, asistencias-panel) | admin | |

## Excepciones — accesible a cualquier autenticado (por diseño)

- `agentes/select`, `tiendas/select` — dropdowns para selects de UI.
- `agentes/{agente}` (show) — valida `tienda_base` internamente.
- `dashboard/kpis`, `control-center`, `comprobantes-cola/emitir-ahora`,
  `comprobantes-cola/{id}/link` — el cajero autenticado los necesita en el acto.

## Público (sin `auth:sanctum`, autorización propia)

- `v1/health`, `v1/auth/*`, `v1/attendance/*`, `v1/postulaciones*`,
  `v1/autorizar-dispositivo`, `v1/integrador/*` (token de tienda / API key),
  `v1/cpe/*` (firma HMAC).
