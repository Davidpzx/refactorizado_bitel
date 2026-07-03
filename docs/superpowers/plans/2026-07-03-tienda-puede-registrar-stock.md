# Rol tienda puede registrar stock — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que un usuario con rol `tienda` registre stock nuevo vía `POST /api/v1/inventario`, verificando su identidad (DNI contra un agente activo) y restringiéndolo a su propia tienda — hoy la ruta es `role:admin` únicamente.

**Architecture:** Backend: quitar el gate `role:admin` de la ruta, replicar el patrón de identidad ya existente en el mismo controller (`fijarPrecioAgente`: `dni_autoriza` + regex 8 dígitos + verificación contra `agentes` activo) más un chequeo de propiedad de tienda. Frontend: agregar el campo DNI al formulario existente (condicionado a rol no-admin) y restringir el selector de tienda a la propia tienda del usuario tienda.

**Tech Stack:** Laravel 12 + PHPUnit/sqlite (backend), React + react-hook-form + zod (frontend).

## Global Constraints

- Solo DNI, sin PIN (decisión del usuario 2026-07-03) — no recuperar `AuthController::verifyPin`.
- Seguir el patrón `dni_autoriza` (regex `/^\d{8}$/`) ya usado en `InventarioController::fijarPrecioAgente`, NO el patrón `auth_dni` de `TrasladoController` (son controllers distintos; consistencia local sobre consistencia cross-file).
- Admin sigue registrando stock sin DNI y sin restricción de tienda (comportamiento actual preservado).
- Tienda solo puede registrar stock para su propia tienda (`tienda_id` del request debe igualar `$user->tienda_id`).

---

### Task 1: Backend — permitir tienda + validar identidad y propiedad

**Files:**
- Modify: `backend/routes/api.php` (línea 153)
- Modify: `backend/app/Http/Controllers/Api/InventarioController.php` (método `store`, líneas 69-138)
- Test: `backend/tests/Feature/InventarioTiendaRegistraStockTest.php` (nuevo)

**Interfaces:**
- Consumes: nada nuevo (usa `DB::table('agentes')`, ya importado en el archivo vía `Illuminate\Support\Facades\DB`).
- Produces: `POST /api/v1/inventario` acepta rol `tienda` con `dni_autoriza` válido y `tienda_id` propio; sigue aceptando admin sin esos requisitos.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventarioTiendaRegistraStockTest extends TestCase
{
    use RefreshDatabase;

    private function itemBase(): array
    {
        return [
            'tienda_id' => 'T01', 'producto_nombre' => 'Cargador USB-C', 'tipo' => 'ACCESORIO',
            'precio_costo' => 10, 'precio_minimo' => 15, 'precio_normal' => 20,
            'cantidad' => 5, 'estado' => 'DISPONIBLE',
        ];
    }

    public function test_tienda_sin_dni_autoriza_falla(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', $this->itemBase())
            ->assertStatus(422);
    }

    public function test_tienda_con_dni_no_valido_falla(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'dni_autoriza' => '99999999'])
            ->assertStatus(422);
    }

    public function test_tienda_no_puede_registrar_para_otra_tienda(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'tienda_id' => 'T02', 'dni_autoriza' => '87654321'])
            ->assertStatus(403);
    }

    public function test_tienda_con_dni_valido_y_propia_tienda_registra_stock(): void
    {
        $tienda = Usuario::factory()->vendedor('T01')->create();
        DB::table('agentes')->insert([
            'dni' => '87654321', 'nombres' => 'Agente Uno', 'estado' => 'ACTIVO', 'tienda_base' => 'T01',
            'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00', 'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);

        $this->actingAs($tienda, 'sanctum')
            ->postJson('/api/v1/inventario', [...$this->itemBase(), 'dni_autoriza' => '87654321'])
            ->assertCreated();

        $this->assertDatabaseHas('inventario_tiendas', ['tienda_id' => 'T01', 'producto_nombre' => 'Cargador USB-C']);
    }

    public function test_admin_sigue_registrando_stock_sin_dni(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/inventario', $this->itemBase())
            ->assertCreated();
    }
}
```

- [ ] **Step 2: Correr el test para confirmar que falla**

Run: `cd backend && php artisan test --filter=InventarioTiendaRegistraStockTest`
Expected: FAIL en los 4 primeros (hoy la ruta es `role:admin`, cualquier request de un usuario tienda devuelve 403 antes de llegar al controller); el 5to (admin) ya pasa hoy.

Nota: si `Usuario::factory()->vendedor('T01')` no existe como state del factory, revisar `backend/database/factories/UsuarioFactory.php` — debe existir un state equivalente (usado en otros tests de este mismo directorio, ej. `BipayCajeroTest.php`) para crear un usuario con `rol = 'tienda'` y `tienda_id` dado. Si el nombre del método difiere, usar el que realmente exista en el factory.

- [ ] **Step 3: Quitar el gate role:admin de la ruta**

En `backend/routes/api.php`, línea 153, reemplazar:

```php
    Route::post('inventario', [InventarioController::class, 'store'])->middleware('role:admin');
```

por:

```php
    Route::post('inventario', [InventarioController::class, 'store']);
```

(La ruta sigue protegida por `auth:sanctum` a nivel de grupo — solo se quita la restricción adicional de rol.)

- [ ] **Step 4: Implementar la validación en store()**

En `backend/app/Http/Controllers/Api/InventarioController.php`, dentro de `store()`, agregar `$user = $request->user();` como primera línea del método, agregar la regla `'dni_autoriza' => ['nullable', 'regex:/^\d{8}$/'],` al array de `$request->validate([...])` (después de `'comision_especial'`), agregar el mensaje `'dni_autoriza.regex' => 'DNI inválido (debe tener 8 dígitos).'` al array de mensajes, e inmediatamente después del `$request->validate(...)` (antes de la línea `$imeis = collect(...)`) agregar:

```php
        if ($user->rol !== 'admin') {
            if ((string) $validated['tienda_id'] !== (string) $user->tienda_id) {
                return response()->json(['message' => 'No puedes registrar stock para otra tienda.'], 403);
            }

            if (empty($validated['dni_autoriza'])) {
                return response()->json(['message' => 'Tu DNI es requerido.'], 422);
            }

            $agente = DB::table('agentes')
                ->where('dni', $validated['dni_autoriza'])
                ->where('estado', 'ACTIVO')
                ->first();

            if (!$agente) {
                return response()->json(['message' => 'DNI no corresponde a un agente activo.'], 422);
            }
        }

        unset($validated['dni_autoriza']);
```

(`unset` es obligatorio: `dni_autoriza` no es un campo de la tabla `inventario_tiendas` ni está en `InventarioTienda::$fillable`, y `$validated` se usa más abajo con `...$validated` / directamente en `InventarioTienda::create($validated)` en ambas ramas del método — si no se quita, Eloquent lo ignoraría silenciosamente por no estar en fillable, pero es más limpio no dejarlo viajar.)

- [ ] **Step 5: Correr el test para confirmar que pasa**

Run: `cd backend && php artisan test --filter=InventarioTiendaRegistraStockTest`
Expected: PASS (5 tests)

- [ ] **Step 6: Correr la suite de Inventario completa para descartar colisiones**

Run: `cd backend && php artisan test --filter=Inventario`
Expected: sin nuevas fallas. Si algún test existente crea inventario como usuario tienda sin pasar por `role:admin` (poco probable dado que antes era imposible), no debería verse afectado; si algo falla, investigar antes de continuar.

- [ ] **Step 7: Commit**

```bash
git add backend/routes/api.php backend/app/Http/Controllers/Api/InventarioController.php backend/tests/Feature/InventarioTiendaRegistraStockTest.php
git commit -m "fix(inventario): permitir a tienda registrar stock con verificacion de identidad (P0 #1)"
```

---

### Task 2: Frontend — campo DNI + restricción de tienda en el formulario

**Files:**
- Modify: `frontend/src/pages/inventario/InventarioForm.tsx`

**Interfaces:**
- Consumes: `useAuth()` (hook existente, `frontend/src/hooks/useAuth.ts`, expone `{ usuario }` con `usuario.rol`/`usuario.tienda_id` — ya usado así en `frontend/src/pages/traslados/TrasladosPage.tsx:620`).
- Produces: formulario envía `dni_autoriza` cuando el usuario no es admin; el selector de tienda queda fijo a la propia tienda para un usuario tienda.

- [ ] **Step 1: Agregar dni_autoriza al schema y a los defaults**

En `frontend/src/pages/inventario/InventarioForm.tsx`, agregar al `schema` (línea 16-28), después de `comision_especial`:

```ts
  dni_autoriza:      z.string().optional(),
```

(Validación de formato se hace en el backend; en frontend solo se exige no-vacío condicionalmente vía chequeo manual antes de enviar, ver Step 3 — mantener el schema simple evita duplicar la regex en dos lugares.)

- [ ] **Step 2: Importar useAuth y leer el usuario actual**

Agregar el import:

```ts
import { useAuth } from '../../hooks/useAuth'
```

Dentro de `InventarioForm`, después de la línea `const esEdicion = Boolean(item?.id)`, agregar:

```ts
  const { usuario }  = useAuth()
  const esAdmin       = usuario?.rol === 'admin'
```

- [ ] **Step 3: Validar dni_autoriza manualmente antes de enviar (no-admin) y quitarlo del payload si es admin**

Reemplazar `onSubmit`:

```ts
  const onSubmit = (data: FormData) => {
    if (!esAdmin && !(data.dni_autoriza ?? '').trim()) {
      return
    }

    const payload = {
      ...data,
      imei_serial: data.imei_serial || null,
      imei_seriales: !esEdicion && data.tipo === 'EQUIPO'
        ? (data.imei_seriales_text ?? '').split(/\r?\n|,/).map(v => v.trim()).filter(Boolean)
        : undefined,
    }
    delete (payload as Partial<FormData>).imei_seriales_text
    if (esAdmin) delete (payload as Partial<FormData>).dni_autoriza

    if (esEdicion && item) {
      actualizar.mutate({ id: item.id, data: payload }, { onSuccess })
    } else {
      crear.mutate(payload, { onSuccess })
    }
  }
```

(El `return` silencioso sin mensaje de error es intencional aquí SOLO porque el campo se renderiza como `required` nativo de HTML en el Step 5 — el navegador ya bloquea el submit y muestra su propio mensaje antes de llegar a `onSubmit` en el caso normal; este chequeo es una segunda defensa, no la UX principal.)

- [ ] **Step 4: Restringir el selector de tienda para rol tienda**

Reemplazar el bloque del selector de tienda (líneas 92-101):

```tsx
        <div>
          <Label htmlFor="tienda_id">Tienda *</Label>
          <Select id="tienda_id" {...register('tienda_id')} className="mt-1" disabled={!esAdmin}>
            <option value="">Selecciona una tienda</option>
            {(esAdmin ? TIENDAS : TIENDAS.filter(t => t === usuario?.tienda_id)).map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </Select>
          {errors.tienda_id && <p className="text-red-500 text-xs mt-1">{errors.tienda_id.message}</p>}
        </div>
```

Y ajustar los `defaultValues` para no-edición (líneas 58-65) agregando `tienda_id: usuario?.tienda_id ?? ''` para que un usuario tienda vea su propia tienda pre-seleccionada:

```ts
      : {
          tipo: 'EQUIPO',
          estado: 'DISPONIBLE',
          cantidad: 1,
          precio_costo: 0,
          precio_minimo: 0,
          precio_normal: 0,
          tienda_id: usuario?.tienda_id ?? '',
        },
```

- [ ] **Step 5: Agregar el campo DNI al JSX (condicionado a no-admin)**

Antes del bloque `{mutError && (...)}` (línea 223), agregar:

```tsx
      {!esAdmin && (
        <div>
          <Label htmlFor="dni_autoriza">Tu DNI *</Label>
          <Input
            id="dni_autoriza"
            {...register('dni_autoriza')}
            placeholder="12345678"
            maxLength={8}
            required
            className="mt-1"
          />
        </div>
      )}
```

- [ ] **Step 6: Verificar tipos**

Run: `cd frontend && npx tsc --noEmit`
Expected: exit 0, sin errores nuevos.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/inventario/InventarioForm.tsx
git commit -m "feat(inventario): campo DNI y restriccion de tienda para rol tienda (P0 #1)"
```

## Self-Review Notes

- **Spec coverage:** cubre el spec completo (backend: identidad + propiedad de tienda; frontend: campo DNI + restricción de selector).
- **Placeholder scan:** sin TBD, código completo en cada step.
- **Type consistency:** `dni_autoriza` es el mismo nombre de campo en schema zod, payload, y validación backend — sin mezclar con `auth_dni` de traslados.
- **Verificación manual pendiente:** ningún subagente de esta cadena tuvo herramienta de navegador en las tareas anteriores (items 1 y 2 de este módulo) — probablemente tampoco aquí. Si es el caso, dejarlo anotado en el reporte en vez de fingir que se verificó, igual que se hizo antes.
