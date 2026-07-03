# Rol tienda puede registrar stock nuevo

**Fecha:** 2026-07-03
**Gap:** P0 #1 de `docs/comparacion/GAP_ANALYSIS_MAESTRO_2026-07-02.md` — "Rol `tienda` no puede registrar stock nuevo (`POST /inventario` es `role:admin`)". Es la tarea diaria del agente de tienda en legacy; ahora mismo bloqueada.

## Problema

`POST /api/v1/inventario` (`InventarioController::store`) solo acepta `role:admin` (`routes/api.php:153`). En legacy, tienda podía registrar stock con una confirmación de identidad (DNI). Hoy tienda no puede hacer esta tarea diaria en absoluto.

## Diseño

**Backend:**
- Quitar `role:admin` de la ruta `POST /inventario` (línea 153) — dejarla abierta a cualquier usuario autenticado (`auth:sanctum`, ya aplicado al grupo).
- Dentro de `InventarioController::store`, replicar el patrón YA EXISTENTE en el mismo controller (`fijarPrecioAgente`, líneas 231-263) en vez del de traslados — es más consistente seguir el patrón local: campo `dni_autoriza` (no `auth_dni`), regex de 8 dígitos, verificación contra `agentes` activo, y blindaje de propiedad de tienda:
  ```php
  if ($user->rol !== 'admin') {
      $validated['dni_autoriza'] ??= null;
      // (agregar 'dni_autoriza' => ['required', 'regex:/^\d{8}$/'] a las reglas de validate()
      //  solo cuando el usuario no es admin -- ver Task en el plan para el detalle exacto)

      $agente = DB::table('agentes')->where('dni', $validated['dni_autoriza'])->where('estado', 'ACTIVO')->first();
      if (!$agente) {
          return response()->json(['message' => 'DNI no corresponde a un agente activo.'], 422);
      }
      if ((string) $validated['tienda_id'] !== (string) $user->tienda_id) {
          return response()->json(['message' => 'No puedes registrar stock para otra tienda.'], 403);
      }
  }
  ```
- Admin sigue sin necesitar `dni_autoriza` (mismo comportamiento que `fijarPrecioAgente`, que directamente rechaza a admin — pero aquí admin SÍ debe poder seguir creando stock sin DNI, a diferencia de `fijarPrecioAgente` que es tienda-only; ver plan para el detalle exacto de la condición).

**Frontend:** el formulario `InventarioForm.tsx` (usado por ambos roles, ya visible para tienda en el nav/UI aunque hoy el backend lo rechaza) no envía ningún campo de DNI — hay que agregarlo, reusando el patrón visual/zod ya usado para `dni_autoriza` en el diálogo "Fijar precio de venta" de `InventarioPage.tsx` (líneas ~640-650), condicionado a que el usuario no sea admin.

## Testing

- Feature test: tienda sin `auth_dni` → 422.
- Feature test: tienda con `auth_dni` inválido → 403.
- Feature test: tienda con `auth_dni` válido, registrando stock para su propia tienda → 201.
- Feature test: tienda intentando registrar stock para OTRA tienda → 403.
- Feature test: admin sigue pudiendo registrar stock sin `auth_dni` (comportamiento actual preservado).

## Fuera de alcance

- No se toca el bug de chips yendo a la tabla equivocada (P0 #2, siguiente en la cola) — aunque está en el mismo controller/método, es un problema distinto.
- No se recupera `AuthController::verifyPin` (decisión del usuario: DNI-only por ahora).
