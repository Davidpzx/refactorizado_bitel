# Spec — Traslados (gerente de tienda) + 2 fixes de seguridad/bug

Fecha: 2026-07-03 · Rama: `m4-traslados-fixes`

## 1. Regla "gerente de tienda autoriza traslado directo"

**Gap:** `docs/comparacion/gap_tienda_reportes_2026-07-02.md` (tabla `tienda/`, fila "Traslado de chips"; top-5 gap #4).
Legacy (`procesar_traslado.php` / `procesar_traslado_chips.php`): si el `auth_dni` que autoriza el envío
corresponde a un `agente.es_gerencia = 1`, el traslado se crea directo en `PENDIENTE` (en tránsito),
sin pasar por `PENDIENTE_APROBACION`. Hoy el destino decide el estado **solo** mirando `$esAdmin` (rol de sesión).

**Archivos:** `TrasladoController::store` (equipos), `TrasladoChipsController::store` (chips).

Ambos ya resuelven `$agente` desde `auth_dni` (validación de identidad añadida en cambio reciente,
gap top-5 #3). Se reordena: la resolución de `$estadoTraslado` se mueve a **después** de esa consulta,
y se extiende la condición:

```php
$estadoTraslado = ($esAdmin || (bool) $agente->es_gerencia) ? 'PENDIENTE' : 'PENDIENTE_APROBACION';
```

No se duplica la consulta a `Agente` — se reutiliza la misma que ya valida `estado = ACTIVO`.
Aplica igual a `TrasladoController::store` (modo individual y masivo, usan la misma variable
`$estadoTraslado` capturada en el closure de la transacción) y a `TrasladoChipsController::store`.

`gestionar()`/`confirmar()`/`confirmarLote()` no cambian: ya operan sobre el estado ya fijado.

## 2. `AgenteController::show` sin validación de tienda

**Gap:** cualquier usuario autenticado puede pedir `GET /api/v1/agentes/{id}` y ver la ficha completa
(datos personales RRHH) de un agente de cualquier tienda.

**Criterio (mismo patrón que `ConstanciaController::agente`, commit `9c0b334`):**
admin ve todo; no-admin solo si `$agente->tienda_base === $user->tienda_id`; fail-closed (403) en
cualquier otro caso, incluyendo usuario sin `tienda_id`.

```php
public function show(Agente $agente): JsonResponse
{
    $user = Auth::user();
    abort_if(
        $user->rol !== 'admin' && $agente->tienda_base !== $user->tienda_id,
        403,
        'No tienes permisos sobre este agente.'
    );

    return response()->json($agente);
}
```

Requiere agregar `use Illuminate\Support\Facades\Auth;` (no está importado en el controller).

TDD, 4 casos (siguiendo `ConstanciaReporteAuthTest`): admin ok, propia tienda ok, tienda ajena 403,
usuario sin `tienda_id` 403.

## 3. `ReporteDetallePage.tsx` no muestra ventas APOYO

**Bug:** `VentaConDetalle.tipo_venta` incluye `'APOYO'` (ver `frontend/src/types/reporte.ts:166` y el
accessor `Venta::tipoVenta()` en el modelo, que revierte `OTROS_FLUJO + subtipo=APOYO` a `'APOYO'` al
leer). El filtro de la pantalla solo bucketiza `POSTPAGO`, `PREPAGO`, `EQUIPO`/`ACCESORIO` y
`OTROS_FLUJO` — las ventas `APOYO` no caen en ningún bucket y desaparecen de la UI, aunque sí se
suman en `totalVentas` (que corre sobre `ventas` completo). El PDF (`constancias/reporte.blade.php`,
sección "5. APOYO A OTRAS TIENDAS") sí las muestra.

**Fix:** agregar bucket `apoyo` y una `SeccionVentas` condicional (mismo patrón que "Otros flujo"),
reutilizando `FilaLinea` (ya soporta el badge `↗ Cross {tienda_destino}` vía `venta.cross_selling`).

Verificación: `npx tsc --noEmit` limpio + lectura del blade como referencia de categorías (no hay
runtime de UI disponible en este entorno para captura de pantalla).
