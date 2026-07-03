# Traslados: validar identidad del agente emisor al crear

**Fecha:** 2026-07-03
**Gap:** P0 #7 de `docs/comparacion/GAP_ANALYSIS_MAESTRO_2026-07-02.md` — "Traslados (equipos y chips) no validan identidad del agente emisor al crear, solo al confirmar recepción."

## Problema

`TrasladoController::store` y `TrasladoChipsController::store` reciben `auth_dni` del request y lo guardan tal cual en `enviado_dni` sin verificarlo contra ningún agente real — cualquier texto pasa. El campo `enviado_por_id` queda siempre `null`. En cambio, `confirmar()` en ambos controllers sí valida: busca un `Agente` activo por DNI (case/espacio-insensitive) y rechaza con 403 si no existe. Es un hueco de auditoría — el "DNI autorizante" al enviar un traslado no se verifica, solo al recibirlo.

## Diseño

Replicar en `store()` (ambos controllers) exactamente el mismo bloque de validación que ya existe en `confirmar()`:

```php
if (!$authDni) {
    return response()->json(['success' => false, 'message' => 'Tu DNI es requerido.'], 422);
}

$agente = Agente::whereRaw('UPPER(TRIM(dni)) = UPPER(TRIM(?))', [$authDni])
    ->where('estado', 'ACTIVO')
    ->first();

if (!$agente) {
    return response()->json(['success' => false, 'message' => 'DNI no corresponde a un agente activo.'], 403);
}

$enviadoPorId = $agente->id;
```

Reemplaza las líneas actuales `$enviadoPorId = null; $authDni = trim($request->input('auth_dni', '')) ?: null;` — ahora `auth_dni` es obligatorio y verificado, igual que en `confirmar()`. Aplica tanto si el creador es admin como si es tienda (mismo comportamiento que `confirmar()`, que no distingue rol para este chequeo).

**Sin cambios** en la lógica de negocio de traslados (stock, transacciones, modo masivo/individual) — solo se agrega la verificación de identidad antes de proceder.

## Testing

- Feature test: `POST /traslados` sin `auth_dni` → 422.
- Feature test: `POST /traslados` con `auth_dni` que no corresponde a ningún agente activo → 403, no se crea el traslado.
- Feature test: `POST /traslados` con `auth_dni` válido → éxito, `enviado_por_id` se guarda con el id del agente.
- Mismos 3 casos para `POST /traslados-chips`.

## Fuera de alcance

- No se toca `confirmar()`, `confirmarLote()`, ni `gestionar()` — ya validan correctamente o no requieren esta validación (aprobar/rechazar es una acción de admin autenticado por sesión, no por DNI físico).
