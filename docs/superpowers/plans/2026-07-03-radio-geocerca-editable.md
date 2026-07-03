# Radio de geocerca editable por tienda — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hacer que `radio_permitido` de cada tienda sea editable desde el admin (hoy la columna existe en BD y `AsistenciaController` la lee, pero nada la escribe), y de paso reactivar `direccion`/`telefono` en `TiendaController`/`TiendaForm`, que quedaron comentados esperando una migración que ya se corrió en producción (`2026_06_20_000001_add_direccion_telefono_to_tiendas`, confirmada `Ran` batch 13 vía SSH el 2026-07-03).

**Architecture:** Cambios aditivos en un controller Laravel existente (`TiendaController`) y un componente React existente (`TiendaForm` dentro de `TiendasPage.tsx`). Sin migraciones nuevas — ambas columnas ya existen en BD. Sin cambios de permisos — el recurso ya es `role:admin` únicamente.

**Tech Stack:** Laravel 12 (PHPUnit, `RefreshDatabase`, sqlite in-memory para tests), React + TypeScript + React Query + axios.

## Global Constraints

- `radio_permitido`: validación `numeric|min:1`, sin tope máximo (decisión de negocio 2026-07-03).
- Solo `role:admin` edita tiendas (sin cambios respecto al comportamiento actual).
- No tocar la lógica de cálculo de distancia GPS en `AsistenciaController` — ya lee `radio_permitido`/`lat_centro`/`lng_centro` correctamente, solo falta el camino de escritura.
- Seguir el patrón existente de sanitización/validación en `frontend/src/lib/validacionesTienda.ts` en vez de crear uno nuevo.

---

### Task 1: Backend — `radio_permitido` escribible en TiendaController

**Files:**
- Modify: `backend/app/Models/Tienda.php` (línea 15, `$fillable`)
- Modify: `backend/app/Http/Controllers/Api/TiendaController.php` (líneas 43-51 `store`, 63-71 `update`)
- Test: `backend/tests/Feature/TiendaRadioGeocercaTest.php` (nuevo)

**Interfaces:**
- Consumes: nada nuevo — `Tienda` model existente, `Usuario::factory()->admin()` (ya existe en el proyecto, usado en `BipayAdminTest.php`).
- Produces: `Tienda::$fillable` acepta `radio_permitido`; `PUT/POST /api/v1/tiendas` acepta y valida `radio_permitido`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiendaRadioGeocercaTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_actualizar_radio_permitido(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = DB::table('tiendas')->insertGetId([
            'codigo' => 'T99', 'nombre' => 'Tienda Test', 'radio_permitido' => 60,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/tiendas/{$id}", ['radio_permitido' => 120])
            ->assertOk()
            ->assertJsonPath('radio_permitido', 120);

        $this->assertDatabaseHas('tiendas', ['id' => $id, 'radio_permitido' => 120]);
    }

    public function test_radio_permitido_no_puede_ser_cero_ni_negativo(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = DB::table('tiendas')->insertGetId([
            'codigo' => 'T98', 'nombre' => 'Tienda Test 2', 'radio_permitido' => 60,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/tiendas/{$id}", ['radio_permitido' => 0])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/tiendas/{$id}", ['radio_permitido' => -5])
            ->assertStatus(422);

        $this->assertDatabaseHas('tiendas', ['id' => $id, 'radio_permitido' => 60]);
    }

    public function test_crear_tienda_con_radio_permitido(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tiendas', ['codigo' => 'T97', 'nombre' => 'Tienda Nueva', 'radio_permitido' => 80])
            ->assertCreated()
            ->assertJsonPath('radio_permitido', 80);

        $this->assertDatabaseHas('tiendas', ['codigo' => 'T97', 'radio_permitido' => 80]);
    }
}
```

- [ ] **Step 2: Correr el test para confirmar que falla**

Run: `cd backend && php artisan test --filter=TiendaRadioGeocercaTest`
Expected: FAIL — `radio_permitido` no se persiste (mass assignment silently dropped, JSON no trae la key o trae el valor viejo) y/o no hay validación 422 para valores inválidos.

- [ ] **Step 3: Implementar — agregar a fillable**

En `backend/app/Models/Tienda.php`, reemplazar:

```php
    protected $fillable = [
        'codigo', 'nombre', 'direccion', 'telefono',
        'activo', 'cuenta_bipay_id', 'latitud', 'longitud',
    ];
```

por:

```php
    protected $fillable = [
        'codigo', 'nombre', 'direccion', 'telefono',
        'activo', 'cuenta_bipay_id', 'latitud', 'longitud', 'radio_permitido',
    ];
```

(Nota: `direccion`/`telefono` ya estaban en `$fillable` pese a estar comentados en el controller — el Model nunca fue el bloqueador, solo la validación del controller. Ver Task 2.)

- [ ] **Step 4: Implementar — validación en store()**

En `backend/app/Http/Controllers/Api/TiendaController.php`, en `store()` agregar la línea de validación (dentro del array de `$request->validate([...])`, después de `'longitud'`):

```php
            'radio_permitido' => ['sometimes', 'numeric', 'min:1'],
```

- [ ] **Step 5: Implementar — validación en update()**

Mismo cambio en `update()`: agregar `'radio_permitido' => ['sometimes', 'numeric', 'min:1'],` al array de validación.

- [ ] **Step 6: Correr el test para confirmar que pasa**

Run: `cd backend && php artisan test --filter=TiendaRadioGeocercaTest`
Expected: PASS (3 tests, 0 failures)

- [ ] **Step 7: Commit**

```bash
git add backend/app/Models/Tienda.php backend/app/Http/Controllers/Api/TiendaController.php backend/tests/Feature/TiendaRadioGeocercaTest.php
git commit -m "feat(tiendas): permitir editar radio_permitido de geocerca (P0 #8)"
```

---

### Task 2: Backend — reactivar `direccion`/`telefono` en TiendaController

**Files:**
- Modify: `backend/app/Http/Controllers/Api/TiendaController.php` (líneas 40-51 `store`, 62-71 `update`)
- Test: `backend/tests/Feature/TiendaDireccionTelefonoTest.php` (nuevo)

**Interfaces:**
- Consumes: nada nuevo.
- Produces: `PUT/POST /api/v1/tiendas` acepta y persiste `direccion`/`telefono`.

- [ ] **Step 1: Escribir el test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiendaDireccionTelefonoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_guardar_direccion_y_telefono(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tiendas', [
                'codigo' => 'T96', 'nombre' => 'Tienda Con Datos',
                'direccion' => 'Av. Siempre Viva 123', 'telefono' => '+51 987654321',
            ])
            ->assertCreated()
            ->assertJsonPath('direccion', 'Av. Siempre Viva 123')
            ->assertJsonPath('telefono', '+51 987654321');

        $this->assertDatabaseHas('tiendas', [
            'codigo' => 'T96', 'direccion' => 'Av. Siempre Viva 123', 'telefono' => '+51 987654321',
        ]);
    }
}
```

- [ ] **Step 2: Correr el test para confirmar que falla**

Run: `cd backend && php artisan test --filter=TiendaDireccionTelefonoTest`
Expected: FAIL — `direccion`/`telefono` no viajan porque están comentados fuera del array de validación (`$request->validate()` descarta cualquier key no listada).

- [ ] **Step 3: Implementar — descomentar validación en store()**

En `store()`, reemplazar:

```php
            'nombre'    => ['required', 'string', 'max:100'],
            // 'direccion' => ['nullable', 'string', 'max:200'],
            // 'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
```

por:

```php
            'nombre'    => ['required', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:200'],
            'telefono'  => ['nullable', 'string', 'max:20'],
            'activo'    => ['boolean'],
```

Y eliminar el comentario `// TEMPORAL: 'direccion' y 'telefono' no existen aún...` de las líneas 40-42 (ya no aplica).

- [ ] **Step 4: Implementar — descomentar validación en update()**

Mismo cambio en `update()` (líneas 66-67) y eliminar el comentario TEMPORAL de la línea 62.

- [ ] **Step 5: Correr el test para confirmar que pasa**

Run: `cd backend && php artisan test --filter=TiendaDireccionTelefonoTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Api/TiendaController.php backend/tests/Feature/TiendaDireccionTelefonoTest.php
git commit -m "fix(tiendas): reactivar direccion/telefono ahora que la migracion ya corrio"
```

---

### Task 3: Frontend — campo Radio de geocerca en TiendaForm

**Files:**
- Modify: `frontend/src/pages/admin/TiendasPage.tsx` (interface `Tienda` línea 21-30, `form` state línea 61-69, sección "Ubicación y estado" línea 160-207)
- Modify: `frontend/src/lib/validacionesTienda.ts` (agregar validación de radio)

**Interfaces:**
- Consumes: patrón existente de `validarCoordenada` en `validacionesTienda.ts`.
- Produces: campo visible y funcional en el formulario de tienda.

- [ ] **Step 1: Agregar validación de radio en validacionesTienda.ts**

En `frontend/src/lib/validacionesTienda.ts`, agregar a `ErroresTienda` (línea 66-73):

```ts
export interface ErroresTienda {
  codigo?: string
  nombre?: string
  direccion?: string
  telefono?: string
  latitud?: string
  longitud?: string
  radioPermitido?: string
}
```

y a `FormularioTienda` (línea 75-82):

```ts
export interface FormularioTienda {
  codigo: string
  nombre: string
  direccion: string
  telefono: string
  latitud: string
  longitud: string
  radioPermitido: string
}
```

y una función de validación nueva, después de `validarCoordenada` (línea 91):

```ts
/** Valida el radio de geocerca (metros). Vacío es válido (usa el default del backend, 60m). */
function validarRadioPermitido(valor: string): string | undefined {
  if (!valor.trim()) return undefined
  const num = Number(valor)
  if (Number.isNaN(num)) return 'Debe ser un número.'
  if (num < 1) return 'Debe ser mayor a 0.'
  return undefined
}
```

y agregar la llamada dentro de `validarTienda`, después del bloque de `errorLongitud` (línea 132-133):

```ts
  const errorRadio = validarRadioPermitido(form.radioPermitido)
  if (errorRadio) errores.radioPermitido = errorRadio
```

- [ ] **Step 2: Actualizar la interfaz `Tienda` y el estado del formulario en TiendasPage.tsx**

En `frontend/src/pages/admin/TiendasPage.tsx`, la interfaz `Tienda` (línea 21-30), agregar:

```ts
interface Tienda {
  id: number
  codigo: string
  nombre: string
  direccion: string | null
  telefono: string | null
  activo: boolean
  latitud: number | null
  longitud: number | null
  radio_permitido: number | null
}
```

En el estado inicial de `TiendaForm` (línea 61-69), agregar:

```ts
  const [form, setForm] = useState({
    codigo:         tienda?.codigo    ?? '',
    nombre:         tienda?.nombre    ?? '',
    direccion:      tienda?.direccion ?? '',
    telefono:       tienda?.telefono  ?? '',
    activo:         tienda?.activo    ?? true,
    latitud:        tienda?.latitud  != null ? String(tienda.latitud)  : '',
    longitud:       tienda?.longitud != null ? String(tienda.longitud) : '',
    radioPermitido: tienda?.radio_permitido != null ? String(tienda.radio_permitido) : '',
  })
```

- [ ] **Step 3: Incluir `radio_permitido` en el payload de envío**

En `save` mutation (línea 78-87), cambiar `mutationFn` a:

```ts
    mutationFn: (payload: typeof form) => {
      const cuerpo = {
        ...payload,
        latitud:        payload.latitud        ? Number(payload.latitud)        : null,
        longitud:       payload.longitud        ? Number(payload.longitud)      : null,
        radio_permitido: payload.radioPermitido ? Number(payload.radioPermitido) : null,
      }
      return tienda
        ? api.put(`/v1/tiendas/${tienda.id}`, cuerpo).then(r => r.data)
        : api.post('/v1/tiendas', cuerpo).then(r => r.data)
    },
```

- [ ] **Step 4: Mapear errores de backend para `radioPermitido`**

En `onError` (línea 93-105), agregar `radioPermitido: camposBackend.radio_permitido?.[0],` al objeto pasado a `setErrores`.

- [ ] **Step 5: Agregar el input visible en el JSX**

En la sección "Ubicación y estado" (línea 177-206), agregar el campo antes del checkbox `activo` (después del bloque de `longitud`, línea 201):

```jsx
          <div>
            <label htmlFor="tienda-radio" className="mb-1 block text-xs text-kyro-muted">Radio de geocerca (metros)</label>
            <Input
              id="tienda-radio"
              type="number"
              min={1}
              step="1"
              value={form.radioPermitido}
              onChange={e => { setForm(f => ({ ...f, radioPermitido: e.target.value })); setErrores(er => ({ ...er, radioPermitido: undefined })) }}
              placeholder="60"
            />
            {errores.radioPermitido && <p className="mt-1 text-[11px] text-kyro-danger">{errores.radioPermitido}</p>}
          </div>
```

- [ ] **Step 6: Verificar manualmente en el navegador**

Correr el frontend (`npm run dev` en `frontend/`), abrir la página de Tiendas como admin, editar una tienda existente, confirmar que el campo "Radio de geocerca (metros)" aparece, acepta un valor, y al guardar no da error. Confirmar en la pestaña Network que el `PUT` incluye `radio_permitido` en el body.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/admin/TiendasPage.tsx frontend/src/lib/validacionesTienda.ts
git commit -m "feat(tiendas): campo de radio de geocerca en el formulario admin"
```

---

### Task 4: Frontend — reactivar campos Dirección/Teléfono en TiendaForm

**Files:**
- Modify: `frontend/src/pages/admin/TiendasPage.tsx` (líneas 160-176, sección "Ubicación y estado")

**Interfaces:**
- Consumes: `sanitizarDireccion`, `sanitizarTelefono`, `LIMITES_TIENDA.direccion`, `LIMITES_TIENDA.telefono` (ya existen en `validacionesTienda.ts`, no usados hoy en este archivo).
- Produces: campos visibles y funcionales — el estado, sanitización y envío ya existían, solo faltaba el JSX.

- [ ] **Step 1: Importar los sanitizadores que faltan**

En el import de `validacionesTienda` (línea 13-19), agregar `sanitizarDireccion` y `sanitizarTelefono`:

```ts
import {
  sanitizarCodigo,
  sanitizarNombre,
  sanitizarDireccion,
  sanitizarTelefono,
  validarTienda,
  LIMITES_TIENDA,
  type ErroresTienda,
} from '../../lib/validacionesTienda'
```

- [ ] **Step 2: Quitar el comentario TEMPORAL y actualizar el texto de ayuda**

Reemplazar el bloque de líneas 163-176:

```jsx
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Ubicación y estado</h3>
            <p className="text-xs text-kyro-muted">
              Dirección y teléfono próximamente — aún no están disponibles en la base de datos.
              Latitud/longitud son opcionales: también se pueden capturar luego con el botón GPS del listado.
            </p>
          </div>
        </div>
        {/*
          TEMPORAL: dirección y teléfono ocultos hasta correr la migración
          2026_06_20_000001_add_direccion_telefono_to_tiendas (la tabla real todavía no tiene
          esas columnas). Backend ya las ignora en TiendaController; esto evita la confusión
          de que el usuario llene un campo que no se va a guardar. Reactivar junto con el backend.
        */}
```

por:

```jsx
          <div>
            <h3 className="text-sm font-semibold text-kyro-text">Ubicación y estado</h3>
            <p className="text-xs text-kyro-muted">
              Dirección y teléfono son opcionales. Latitud/longitud también se pueden capturar luego con el botón GPS del listado.
            </p>
          </div>
        </div>
```

- [ ] **Step 3: Agregar los dos campos en el JSX**

Al inicio del `<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">` (línea 177 original), antes del campo Latitud, agregar:

```jsx
          <div>
            <label htmlFor="tienda-direccion" className="mb-1 block text-xs text-kyro-muted">Dirección</label>
            <Input
              id="tienda-direccion"
              value={form.direccion}
              onChange={e => { setForm(f => ({ ...f, direccion: sanitizarDireccion(e.target.value) })); setErrores(er => ({ ...er, direccion: undefined })) }}
              maxLength={LIMITES_TIENDA.direccion}
              placeholder="Av. Siempre Viva 123"
            />
            {errores.direccion && <p className="mt-1 text-[11px] text-kyro-danger">{errores.direccion}</p>}
          </div>
          <div>
            <label htmlFor="tienda-telefono" className="mb-1 block text-xs text-kyro-muted">Teléfono</label>
            <Input
              id="tienda-telefono"
              value={form.telefono}
              onChange={e => { setForm(f => ({ ...f, telefono: sanitizarTelefono(e.target.value) })); setErrores(er => ({ ...er, telefono: undefined })) }}
              maxLength={LIMITES_TIENDA.telefono}
              placeholder="+51 987654321"
            />
            {errores.telefono && <p className="mt-1 text-[11px] text-kyro-danger">{errores.telefono}</p>}
          </div>
```

- [ ] **Step 4: Verificar manualmente en el navegador**

Con el frontend corriendo, editar una tienda, confirmar que "Dirección" y "Teléfono" aparecen, aceptan texto, se sanitizan mientras se escribe (probar pegar símbolos no permitidos), y que al guardar el `PUT`/`POST` en Network incluye ambos campos con el valor correcto.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/admin/TiendasPage.tsx
git commit -m "fix(tiendas): reactivar campos direccion y telefono en el formulario"
```

---

## Self-Review Notes

- **Spec coverage:** Task 1+3 cubren el spec original (`2026-07-03-radio-geocerca-editable-design.md`) completo: backend + frontend + tests. Task 2+4 son un hallazgo adicional (mismo archivo, mismo bloqueador ya resuelto) — documentado como fuera del spec original pero dentro del objetivo de paridad 100%.
- **Placeholder scan:** sin TBD/TODO; todos los steps tienen código completo.
- **Type consistency:** `radioPermitido` (frontend state/camelCase) mapea a `radio_permitido` (backend/DB snake_case) consistentemente en Task 3 Steps 2-5.
