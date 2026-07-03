# Traslados: validar identidad al crear — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `TrasladoController::store` y `TrasladoChipsController::store` deben validar `auth_dni` contra un `Agente` activo real antes de crear el traslado, igual que ya hace `confirmar()` en ambos controllers — hoy cualquier texto pasa como DNI autorizante al enviar.

**Architecture:** Copiar el bloque de validación ya probado de `confirmar()` a `store()`, en ambos controllers. Sin cambios de esquema, sin cambios de frontend (el frontend ya envía `auth_dni` en ambos flujos de creación — verificado en `frontend/src/pages/traslados/TrasladosPage.tsx`).

**Tech Stack:** Laravel 12, PHPUnit + `RefreshDatabase` + sqlite in-memory.

## Global Constraints

- `auth_dni` pasa a ser obligatorio en `store()` de ambos controllers (antes era opcional/no verificado) — 422 si falta, 403 si no corresponde a un `Agente` con `estado = 'ACTIVO'`.
- Aplica igual para admin y para tienda (sin distinción de rol en este chequeo, igual que `confirmar()`).
- No tocar `confirmar()`, `confirmarLote()`, ni `gestionar()`.
- No tocar la lógica de negocio de stock/transacciones existente en `store()` — solo anteponer la validación de identidad.

---

### Task 1: TrasladoController::store — validar identidad

**Files:**
- Modify: `backend/app/Http/Controllers/Api/TrasladoController.php` (líneas 62-68, dentro de `store()`)
- Test: `backend/tests/Feature/TrasladoIdentidadEmisorTest.php` (nuevo)

**Interfaces:**
- Consumes: `App\Models\Agente` (ya usado en `confirmar()` del mismo archivo).
- Produces: `POST /api/v1/traslados` ahora exige `auth_dni` válido; `enviado_por_id` se persiste con el id del agente en vez de quedar siempre `null`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrasladoIdentidadEmisorTest extends TestCase
{
    use RefreshDatabase;

    private function crearProductoDisponible(string $tienda = 'T01'): int
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => $tienda, 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        return (int) InventarioTienda::create([
            'tienda_id' => $tienda, 'tipo' => 'ACCESORIO', 'producto_nombre' => 'Cargador',
            'cantidad' => 5, 'estado' => 'DISPONIBLE', 'fecha_registro' => now(),
        ])->id;
    }

    public function test_crear_traslado_sin_auth_dni_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02',
            ])
            ->assertStatus(422);
    }

    public function test_crear_traslado_con_dni_no_valido_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '99999999',
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('traslados_stock', ['tienda_destino' => 'T02']);
    }

    public function test_crear_traslado_con_dni_valido_persiste_enviado_por_id(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agente = Agente::create([
            'dni' => '87654321', 'nombres' => 'Agente Emisor', 'estado' => 'ACTIVO',
            'tienda_base' => 'T01', 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
        $id = $this->crearProductoDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados', [
                'producto_id' => $id, 'cantidad' => 1, 'tienda_destino' => 'T02', 'auth_dni' => '87654321',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('traslados_stock', [
            'tienda_destino' => 'T02', 'enviado_por_id' => $agente->id, 'enviado_dni' => '87654321',
        ]);
    }
}
```

- [ ] **Step 2: Correr el test para confirmar que falla**

Run: `cd backend && php artisan test --filter=TrasladoIdentidadEmisorTest`
Expected: FAIL — hoy `store()` no valida `auth_dni` en absoluto, así que las 3 aserciones (422, 403, `enviado_por_id` persistido) fallan.

- [ ] **Step 3: Implementar la validación en store()**

En `backend/app/Http/Controllers/Api/TrasladoController.php`, dentro de `store()`, reemplazar:

```php
        $enviadoPorId   = null;
        $authDni        = trim($request->input('auth_dni', '')) ?: null;
```

por:

```php
        $authDni = trim($request->input('auth_dni', ''));

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

(Nota: esto va ANTES de la validación de `tiendaDestino` que sigue en el método — el orden entre estos dos checks no importa para el resultado, ambos son 4xx, pero mantenerlo cerca de donde estaban las variables originales evita mover código innecesario.)

- [ ] **Step 4: Correr el test para confirmar que pasa**

Run: `cd backend && php artisan test --filter=TrasladoIdentidadEmisorTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Correr la suite de traslados completa para descartar colisiones**

Run: `cd backend && php artisan test --filter=Traslado`
Expected: todos los tests existentes de traslados (si los hay) siguen pasando — si algún test existente creaba traslados sin `auth_dni`, va a fallar aquí y hay que decidir si ese test necesita actualizarse (agregar un DNI válido) como parte de esta tarea, ya que ahora es un campo requerido por diseño.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/TrasladoController.php backend/tests/Feature/TrasladoIdentidadEmisorTest.php
git commit -m "fix(traslados): validar identidad del agente emisor al crear (P0 #7)"
```

---

### Task 2: TrasladoChipsController::store — validar identidad

**Files:**
- Modify: `backend/app/Http/Controllers/Api/TrasladoChipsController.php` (líneas 71-73, dentro de `store()`)
- Test: `backend/tests/Feature/TrasladoChipsIdentidadEmisorTest.php` (nuevo)

**Interfaces:**
- Consumes: `App\Models\Agente` (mismo patrón que Task 1).
- Produces: `POST /api/v1/traslados-chips` ahora exige `auth_dni` válido; `enviado_por_id` se persiste correctamente.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\InventarioChip;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TrasladoChipsIdentidadEmisorTest extends TestCase
{
    use RefreshDatabase;

    private function crearChipDisponible(): int
    {
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T01', 'nombre' => 'Origen']);
        DB::table('tiendas')->insertOrIgnore(['codigo' => 'T02', 'nombre' => 'Destino']);

        // inventario_chips.tienda_id es un entero (no el codigo string) -- ver
        // migracion 2026_06_11_000001_create_traslados_tables.php. No filtra por
        // el, TrasladoChipsController::store solo compara tienda_origen (string).
        return (int) InventarioChip::create([
            'tienda_id' => 1, 'tienda_origen' => 'T01', 'stock_actual' => 10,
        ])->id;
    }

    public function test_crear_traslado_chips_sin_auth_dni_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
            ])
            ->assertStatus(422);
    }

    public function test_crear_traslado_chips_con_dni_no_valido_falla(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '99999999',
            ])
            ->assertStatus(403);
    }

    public function test_crear_traslado_chips_con_dni_valido_persiste_enviado_por_id(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $agente = Agente::create([
            'dni' => '11223344', 'nombres' => 'Agente Chips', 'estado' => 'ACTIVO',
            'tienda_base' => 'T01', 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
        $chipId = $this->crearChipDisponible();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/traslados-chips', [
                'chip_id' => $chipId, 'tienda_origen' => 'T01', 'tienda_destino' => 'T02', 'cantidad' => 2,
                'auth_dni' => '11223344',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('traslados_chips', [
            'tienda_destino' => 'T02', 'enviado_por_id' => $agente->id, 'enviado_dni' => '11223344',
        ]);
    }
}
```

- [ ] **Step 2: Correr el test para confirmar que falla**

Run: `cd backend && php artisan test --filter=TrasladoChipsIdentidadEmisorTest`
Expected: FAIL — mismo motivo que Task 1.

- [ ] **Step 3: Implementar la validación en store()**

En `backend/app/Http/Controllers/Api/TrasladoChipsController.php`, reemplazar:

```php
        $estadoTraslado = $esAdmin ? 'PENDIENTE' : 'PENDIENTE_APROBACION';
        $enviadoPorId   = null;
        $authDni        = trim($request->input('auth_dni', '')) ?: null;
```

por:

```php
        $estadoTraslado = $esAdmin ? 'PENDIENTE' : 'PENDIENTE_APROBACION';
        $authDni        = trim($request->input('auth_dni', ''));

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

- [ ] **Step 4: Correr el test para confirmar que pasa**

Run: `cd backend && php artisan test --filter=TrasladoChipsIdentidadEmisorTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Correr la suite completa de traslados-chips**

Run: `cd backend && php artisan test --filter=TrasladoChips`
Expected: sin colisiones; igual que Task 1, si algún test previo creaba traslados de chips sin DNI, actualizarlo como parte de esta tarea.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/TrasladoChipsController.php backend/tests/Feature/TrasladoChipsIdentidadEmisorTest.php
git commit -m "fix(traslados-chips): validar identidad del agente emisor al crear (P0 #7)"
```

---

## Self-Review Notes

- **Spec coverage:** ambas tareas cubren el spec completo (equipos + chips), incluyendo los 3 casos de test especificados (falta DNI, DNI inválido, DNI válido con persistencia de `enviado_por_id`).
- **Placeholder scan:** sin TBD/TODO, código completo en cada step.
- **Type consistency:** ambos controllers usan el mismo patrón exacto de `Agente::whereRaw(...)`, ya usado y probado en sus respectivos métodos `confirmar()`.
- **Frontend:** confirmado que no requiere cambios — ambos flujos de creación en `frontend/src/pages/traslados/TrasladosPage.tsx` ya envían `auth_dni` obligatorio con lookup de nombre por DNI.
