# F2 — Backend WhatsApp: modelo de datos + EvolutionProvider + endpoints (refactorizado_bitel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir toda la capa backend de WhatsApp (migraciones, interfaz `WhatsAppProvider` + implementación `EvolutionProvider`, endpoints REST, webhook) lista para que F3 (frontend) y F5 (adaptador Watchimp) se enchufen sin fricción. **Este plan NO despliega el contenedor de Evolution API en el VPS** — eso es una acción separada sobre infraestructura de producción que se coordina explícitamente con David antes de ejecutarse. Este plan asume que existe (o existirá) una URL base + API key de una instancia de Evolution alcanzable, configurada por `.env`.

**Architecture:** Sigue el patrón ya usado en `app/Services/Facturacion/FacturacionApiClient.php` (cliente HTTP con `Illuminate\Support\Facades\Http`, timeouts explícitos, sin loggear secretos). Los mensajes entrantes llegan por webhook y se guardan en BD propia (espejo local); el envío pasa siempre por la interfaz `WhatsAppProvider`, nunca directo a Evolution desde un controller.

**Tech Stack:** Laravel 12, `Illuminate\Support\Facades\Http` (Guzzle), PDO/Eloquent, PHPUnit/Pest (patrón de tests ya usado en el repo).

## Global Constraints

- Scoping fail-closed por tienda usando `App\Support\TiendaGuard::bloqueaAcceso()` (ya existe, no reinventar) — mismo patrón que `ConstanciaController::reporte()`.
- Roles con acceso: `administrador`, `gerente`, `jefe_tienda` (middleware `role:administrador,gerente,jefe_tienda` donde aplique; `role:administrador` solo para crear/eliminar cuentas).
- La API key de Evolution y su URL base viven SOLO en `.env`/`config/services.php`, nunca en el frontend ni en respuestas JSON.
- `php artisan test` debe pasar limpio después de cada task.
- No modificar `app/Support/TiendaGuard.php`.

---

### Task 1: Migraciones — whatsapp_cuentas, whatsapp_chats, whatsapp_mensajes

**Files:**
- Create: `backend/database/migrations/2026_07_15_000001_create_whatsapp_cuentas_table.php`
- Create: `backend/database/migrations/2026_07_15_000002_create_whatsapp_chats_table.php`
- Create: `backend/database/migrations/2026_07_15_000003_create_whatsapp_mensajes_table.php`
- Create: `backend/app/Models/WhatsAppCuenta.php`
- Create: `backend/app/Models/WhatsAppChat.php`
- Create: `backend/app/Models/WhatsAppMensaje.php`
- Test: `backend/tests/Feature/WhatsAppMigracionesTest.php`

**Interfaces:**
- Produces: modelos Eloquent `WhatsAppCuenta` (`id, nombre, numero, instancia, provider, tienda_id, estado, created_at, updated_at`), `WhatsAppChat` (`id, cuenta_id, jid, nombre_contacto, numero_contacto, crm_cliente_id, ultimo_mensaje_at, no_leidos`), `WhatsAppMensaje` (`id, chat_id, direccion, tipo, contenido, media_url, wa_message_id, enviado_por, timestamp`). Relaciones: `WhatsAppCuenta::chats()` hasMany, `WhatsAppChat::mensajes()` hasMany, `WhatsAppChat::cuenta()` belongsTo, `WhatsAppMensaje::chat()` belongsTo.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/WhatsAppMigracionesTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppMigracionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_cuenta_chat_y_mensaje_encadenados(): void
    {
        $cuenta = WhatsAppCuenta::create([
            'nombre' => 'Tienda Centro',
            'numero' => '+51999999999',
            'instancia' => 'tienda-centro',
            'provider' => 'evolution',
            'tienda_id' => 'T01',
            'estado' => 'qr_pendiente',
        ]);

        $chat = WhatsAppChat::create([
            'cuenta_id' => $cuenta->id,
            'jid' => '51988888888@s.whatsapp.net',
            'nombre_contacto' => 'Juan Pérez',
            'numero_contacto' => '+51988888888',
            'no_leidos' => 0,
        ]);

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'in',
            'tipo' => 'texto',
            'contenido' => 'Hola, quiero un plan',
            'wa_message_id' => 'ABC123',
            'timestamp' => now(),
        ]);

        $this->assertTrue($cuenta->chats->contains($chat));
        $this->assertTrue($chat->mensajes->contains($mensaje));
        $this->assertSame('Tienda Centro', $mensaje->chat->cuenta->nombre);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

```
cd backend && php artisan test --filter=WhatsAppMigracionesTest
```
Esperado: FAIL (tablas/modelos no existen).

- [ ] **Step 3: Crear las migraciones**

`backend/database/migrations/2026_07_15_000001_create_whatsapp_cuentas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('numero', 20);
            $table->string('instancia', 100)->unique();
            $table->string('provider', 20)->default('evolution');
            $table->string('tienda_id', 10)->nullable();
            $table->string('estado', 20)->default('qr_pendiente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_cuentas');
    }
};
```

`backend/database/migrations/2026_07_15_000002_create_whatsapp_chats_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('whatsapp_cuentas')->cascadeOnDelete();
            $table->string('jid', 60);
            $table->string('nombre_contacto', 150)->nullable();
            $table->string('numero_contacto', 20)->nullable();
            $table->unsignedBigInteger('crm_cliente_id')->nullable();
            $table->timestamp('ultimo_mensaje_at')->nullable();
            $table->unsignedInteger('no_leidos')->default(0);
            $table->timestamps();

            $table->unique(['cuenta_id', 'jid']);
            $table->index('numero_contacto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chats');
    }
};
```

`backend/database/migrations/2026_07_15_000003_create_whatsapp_mensajes_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
            $table->enum('direccion', ['in', 'out']);
            $table->string('tipo', 20)->default('texto');
            $table->text('contenido')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('wa_message_id', 100)->nullable();
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->timestamp('timestamp');
            $table->timestamps();

            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
    }
};
```

- [ ] **Step 4: Crear los modelos**

`backend/app/Models/WhatsAppCuenta.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCuenta extends Model
{
    protected $table = 'whatsapp_cuentas';

    protected $fillable = ['nombre', 'numero', 'instancia', 'provider', 'tienda_id', 'estado'];

    public function chats(): HasMany
    {
        return $this->hasMany(WhatsAppChat::class, 'cuenta_id');
    }
}
```

`backend/app/Models/WhatsAppChat.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppChat extends Model
{
    protected $table = 'whatsapp_chats';

    protected $fillable = [
        'cuenta_id', 'jid', 'nombre_contacto', 'numero_contacto',
        'crm_cliente_id', 'ultimo_mensaje_at', 'no_leidos',
    ];

    protected $casts = ['ultimo_mensaje_at' => 'datetime'];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCuenta::class, 'cuenta_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsAppMensaje::class, 'chat_id');
    }
}
```

`backend/app/Models/WhatsAppMensaje.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMensaje extends Model
{
    protected $table = 'whatsapp_mensajes';

    protected $fillable = [
        'chat_id', 'direccion', 'tipo', 'contenido', 'media_url',
        'wa_message_id', 'enviado_por', 'timestamp',
    ];

    protected $casts = ['timestamp' => 'datetime'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChat::class, 'chat_id');
    }
}
```

- [ ] **Step 5: Correr el test y confirmar que pasa**

```
cd backend && php artisan test --filter=WhatsAppMigracionesTest
```
Esperado: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_07_15_00000{1,2,3}_create_whatsapp_*.php backend/app/Models/WhatsApp*.php backend/tests/Feature/WhatsAppMigracionesTest.php
git commit -m "feat(whatsapp): modelo de datos — cuentas, chats, mensajes"
```

---

### Task 2: Interfaz WhatsAppProvider + EvolutionProvider

**Files:**
- Create: `backend/app/Services/WhatsApp/WhatsAppProvider.php`
- Create: `backend/app/Services/WhatsApp/EvolutionProvider.php`
- Create: `backend/app/Services/WhatsApp/WhatsAppProviderFactory.php`
- Modify: `backend/config/services.php`
- Modify: `backend/.env.example`
- Test: `backend/tests/Unit/EvolutionProviderTest.php`

**Interfaces:**
- Produces: interfaz `WhatsAppProvider` con `crearInstancia(string $nombreInstancia): array`, `obtenerQR(string $nombreInstancia): string` (base64 o URL de imagen), `estadoInstancia(string $nombreInstancia): string`, `enviarTexto(string $nombreInstancia, string $jid, string $texto): array`, `enviarMedia(string $nombreInstancia, string $jid, string $mediaUrl, string $tipo, ?string $caption): array`. `WhatsAppProviderFactory::make(string $provider): WhatsAppProvider` resuelve la implementación según el string `provider` de `WhatsAppCuenta`.

- [ ] **Step 1: Escribir el test que falla (mockeando el HTTP client de Evolution)**

`backend/tests/Unit/EvolutionProviderTest.php`:
```php
<?php

namespace Tests\Unit;

use App\Services\WhatsApp\EvolutionProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionProviderTest extends TestCase
{
    public function test_enviar_texto_llama_al_endpoint_correcto_con_api_key(): void
    {
        Http::fake([
            '*/message/sendText/mi-instancia' => Http::response(['key' => ['id' => 'WA123']], 200),
        ]);

        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        $provider = new EvolutionProvider();
        $result = $provider->enviarTexto('mi-instancia', '51988888888@s.whatsapp.net', 'Hola');

        $this->assertSame('WA123', $result['key']['id']);
        Http::assertSent(function ($request) {
            return $request->hasHeader('apikey', 'secreto')
                && str_contains($request->url(), '/message/sendText/mi-instancia');
        });
    }

    public function test_estado_instancia_desconectada_si_evolution_responde_error(): void
    {
        Http::fake([
            '*/instance/connectionState/*' => Http::response([], 500),
        ]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        $provider = new EvolutionProvider();
        $this->assertSame('desconectada', $provider->estadoInstancia('mi-instancia'));
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

```
cd backend && php artisan test --filter=EvolutionProviderTest
```
Esperado: FAIL (clase no existe).

- [ ] **Step 3: Agregar config de Evolution**

En `backend/config/services.php`, agregar dentro del array de retorno:
```php
    'evolution' => [
        'base_url' => env('EVOLUTION_API_BASE_URL'),
        'api_key' => env('EVOLUTION_API_KEY'),
    ],
```

En `backend/.env.example`, agregar:
```
EVOLUTION_API_BASE_URL=
EVOLUTION_API_KEY=
```

- [ ] **Step 4: Crear la interfaz**

`backend/app/Services/WhatsApp/WhatsAppProvider.php`:
```php
<?php

namespace App\Services\WhatsApp;

interface WhatsAppProvider
{
    /** Crea la instancia en el proveedor y devuelve datos crudos (incluye QR si el proveedor lo entrega de una). */
    public function crearInstancia(string $nombreInstancia): array;

    /** Devuelve el QR como string base64 (data URI) para mostrar en el frontend. */
    public function obtenerQR(string $nombreInstancia): string;

    /** 'conectada' | 'desconectada' | 'qr_pendiente' */
    public function estadoInstancia(string $nombreInstancia): string;

    public function enviarTexto(string $nombreInstancia, string $jid, string $texto): array;

    public function enviarMedia(string $nombreInstancia, string $jid, string $mediaUrl, string $tipo, ?string $caption): array;
}
```

- [ ] **Step 5: Implementar EvolutionProvider**

`backend/app/Services/WhatsApp/EvolutionProvider.php`:
```php
<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de Evolution API (https://doc.evolution-api.com). Ningún log de esta
 * clase incluye el api_key ni el cuerpo de mensajes (puede contener datos de clientes).
 */
class EvolutionProvider implements WhatsAppProvider
{
    private function http()
    {
        return Http::baseUrl((string) config('services.evolution.base_url'))
            ->withHeaders(['apikey' => (string) config('services.evolution.api_key')])
            ->timeout(15)
            ->connectTimeout(5);
    }

    public function crearInstancia(string $nombreInstancia): array
    {
        $response = $this->http()->post('/instance/create', [
            'instanceName' => $nombreInstancia,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ]);

        if ($response->failed()) {
            Log::warning('evolution.crear_instancia_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            return [];
        }

        return $response->json() ?? [];
    }

    public function obtenerQR(string $nombreInstancia): string
    {
        $response = $this->http()->get("/instance/connect/{$nombreInstancia}");

        if ($response->failed()) {
            Log::warning('evolution.obtener_qr_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            return '';
        }

        return (string) ($response->json('base64') ?? $response->json('qrcode.base64') ?? '');
    }

    public function estadoInstancia(string $nombreInstancia): string
    {
        $response = $this->http()->get("/instance/connectionState/{$nombreInstancia}");

        if ($response->failed()) {
            return 'desconectada';
        }

        $estado = (string) ($response->json('instance.state') ?? $response->json('state') ?? '');

        return match ($estado) {
            'open' => 'conectada',
            'connecting' => 'qr_pendiente',
            default => 'desconectada',
        };
    }

    public function enviarTexto(string $nombreInstancia, string $jid, string $texto): array
    {
        $response = $this->http()->post("/message/sendText/{$nombreInstancia}", [
            'number' => $jid,
            'text' => $texto,
        ]);

        if ($response->failed()) {
            Log::warning('evolution.enviar_texto_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            throw new \RuntimeException('No se pudo enviar el mensaje de WhatsApp.');
        }

        return $response->json() ?? [];
    }

    public function enviarMedia(string $nombreInstancia, string $jid, string $mediaUrl, string $tipo, ?string $caption): array
    {
        $response = $this->http()->post("/message/sendMedia/{$nombreInstancia}", [
            'number' => $jid,
            'mediatype' => $tipo,
            'media' => $mediaUrl,
            'caption' => $caption,
        ]);

        if ($response->failed()) {
            Log::warning('evolution.enviar_media_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            throw new \RuntimeException('No se pudo enviar el archivo de WhatsApp.');
        }

        return $response->json() ?? [];
    }
}
```

- [ ] **Step 6: Crear el factory**

`backend/app/Services/WhatsApp/WhatsAppProviderFactory.php`:
```php
<?php

namespace App\Services\WhatsApp;

class WhatsAppProviderFactory
{
    public static function make(string $provider): WhatsAppProvider
    {
        return match ($provider) {
            'evolution' => new EvolutionProvider(),
            // 'watchimp' => new WatchimpProvider(), // F5
            default => throw new \InvalidArgumentException("Proveedor de WhatsApp desconocido: {$provider}"),
        };
    }
}
```

- [ ] **Step 7: Correr el test y confirmar que pasa**

```
cd backend && php artisan test --filter=EvolutionProviderTest
```
Esperado: PASS (2 tests).

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/WhatsApp/ backend/config/services.php backend/.env.example backend/tests/Unit/EvolutionProviderTest.php
git commit -m "feat(whatsapp): interfaz WhatsAppProvider + EvolutionProvider"
```

---

### Task 3: Endpoints REST — cuentas, chats, mensajes (con scoping por tienda)

**Files:**
- Create: `backend/app/Http/Controllers/Api/WhatsAppController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/WhatsAppScopingTest.php`

**Interfaces:**
- Produces:
  - `GET v1/whatsapp/cuentas` → lista de cuentas visibles según rol/tienda.
  - `POST v1/whatsapp/cuentas` (solo admin) → crea instancia vía provider, devuelve `{ cuenta, qr }`.
  - `GET v1/whatsapp/cuentas/{id}/qr` (solo admin) → refresca QR/estado.
  - `DELETE v1/whatsapp/cuentas/{id}` (solo admin).
  - `GET v1/whatsapp/chats?cuenta_id=` → chats de esa cuenta (o de todas las visibles si se omite), 403 si la cuenta no es visible para el usuario.
  - `GET v1/whatsapp/chats/{id}/mensajes` → historial paginado.
  - `POST v1/whatsapp/chats/{id}/mensajes` → `{ tipo: 'texto'|'imagen', contenido, media_url? }`, envía vía provider y guarda el mensaje saliente.

- [ ] **Step 1: Escribir los tests que fallan**

`backend/tests/Feature/WhatsAppScopingTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_jefe_tienda_solo_ve_cuentas_de_su_tienda(): void
    {
        WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);

        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/whatsapp/cuentas');

        $response->assertOk();
        $nombres = collect($response->json())->pluck('nombre');
        $this->assertTrue($nombres->contains('A'));
        $this->assertFalse($nombres->contains('B'));
    }

    public function test_admin_ve_todas_las_cuentas(): void
    {
        WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);

        $user = Usuario::factory()->create(['rol' => 'administrador']);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/whatsapp/cuentas');

        $response->assertOk();
        $this->assertCount(2, $response->json());
    }

    public function test_jefe_tienda_recibe_403_al_pedir_chats_de_cuenta_ajena(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'B', 'numero' => '2', 'instancia' => 'b', 'tienda_id' => 'T02', 'estado' => 'conectada']);
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/whatsapp/chats?cuenta_id={$cuenta->id}");

        $response->assertStatus(403);
    }

    public function test_no_admin_recibe_403_al_crear_cuenta(): void
    {
        $user = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/whatsapp/cuentas', [
            'nombre' => 'Nueva', 'numero' => '+51900000000', 'tienda_id' => 'T01',
        ]);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Correr los tests y confirmar que fallan**

```
cd backend && php artisan test --filter=WhatsAppScopingTest
```
Esperado: FAIL (rutas no existen, 404).

Ajustar `Usuario::factory()` a los campos reales (`rol`, `tienda_id`) revisando `backend/database/factories/UsuarioFactory.php` si difieren de lo asumido — mismo cuidado que en el plan F1.

- [ ] **Step 3: Implementar el controller**

`backend/app/Http/Controllers/Api/WhatsAppController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use App\Services\WhatsApp\WhatsAppProviderFactory;
use App\Support\TiendaGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    private function esAdmin(): bool
    {
        return Auth::user()->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);
    }

    public function cuentas(): JsonResponse
    {
        $user = Auth::user();
        $query = WhatsAppCuenta::query()->orderBy('nombre');

        if (!$this->esAdmin()) {
            $tiendaId = trim((string) $user->tienda_id);
            $query->where(function ($q) use ($tiendaId) {
                $q->where('tienda_id', $tiendaId);
                if ($tiendaId === '') {
                    $q->whereRaw('1 = 0'); // fail-closed: sin tienda, no ve nada
                }
            });
        }

        return response()->json($query->get());
    }

    public function crearCuenta(Request $request): JsonResponse
    {
        if (!Auth::user()->tieneRol(Usuario::ROL_ADMINISTRADOR)) {
            return response()->json(['message' => 'Solo administradores pueden conectar cuentas.'], 403);
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'numero' => ['required', 'string', 'max:20'],
            'tienda_id' => ['nullable', 'string', 'max:10'],
        ]);

        $instancia = Str::slug($data['nombre']) . '-' . Str::random(6);

        $cuenta = WhatsAppCuenta::create([
            'nombre' => $data['nombre'],
            'numero' => $data['numero'],
            'instancia' => $instancia,
            'provider' => 'evolution',
            'tienda_id' => $data['tienda_id'] ?? null,
            'estado' => 'qr_pendiente',
        ]);

        $provider = WhatsAppProviderFactory::make($cuenta->provider);
        $provider->crearInstancia($instancia);
        $qr = $provider->obtenerQR($instancia);

        return response()->json(['cuenta' => $cuenta, 'qr' => $qr]);
    }

    public function qr(int $id): JsonResponse
    {
        if (!Auth::user()->tieneRol(Usuario::ROL_ADMINISTRADOR)) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $cuenta = WhatsAppCuenta::findOrFail($id);
        $provider = WhatsAppProviderFactory::make($cuenta->provider);
        $estado = $provider->estadoInstancia($cuenta->instancia);

        if ($estado !== $cuenta->estado) {
            $cuenta->update(['estado' => $estado]);
        }

        $qr = $estado === 'conectada' ? '' : $provider->obtenerQR($cuenta->instancia);

        return response()->json(['estado' => $estado, 'qr' => $qr]);
    }

    public function eliminarCuenta(int $id): JsonResponse
    {
        if (!Auth::user()->tieneRol(Usuario::ROL_ADMINISTRADOR)) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        WhatsAppCuenta::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    private function autorizarCuenta(WhatsAppCuenta $cuenta): void
    {
        $user = Auth::user();
        abort_if(
            TiendaGuard::bloqueaAcceso($this->esAdmin(), $user->tienda_id, $cuenta->tienda_id),
            403,
            'No tienes permisos sobre esta cuenta de WhatsApp.'
        );
    }

    public function chats(Request $request): JsonResponse
    {
        $cuentaId = $request->query('cuenta_id');

        if ($cuentaId) {
            $cuenta = WhatsAppCuenta::findOrFail((int) $cuentaId);
            $this->autorizarCuenta($cuenta);
            $chats = WhatsAppChat::where('cuenta_id', $cuenta->id)
                ->orderByDesc('ultimo_mensaje_at')->get();

            return response()->json($chats);
        }

        // Sin cuenta_id: todas las cuentas visibles para este usuario (vista combinada)
        $cuentasVisiblesIds = json_decode($this->cuentas()->getContent(), true);
        $ids = collect($cuentasVisiblesIds)->pluck('id');

        $chats = WhatsAppChat::whereIn('cuenta_id', $ids)
            ->with('cuenta:id,nombre,tienda_id')
            ->orderByDesc('ultimo_mensaje_at')->get();

        return response()->json($chats);
    }

    public function mensajes(int $chatId): JsonResponse
    {
        $chat = WhatsAppChat::with('cuenta')->findOrFail($chatId);
        $this->autorizarCuenta($chat->cuenta);

        $mensajes = WhatsAppMensaje::where('chat_id', $chatId)
            ->orderByDesc('timestamp')
            ->paginate(50);

        return response()->json($mensajes);
    }

    public function enviarMensaje(Request $request, int $chatId): JsonResponse
    {
        $chat = WhatsAppChat::with('cuenta')->findOrFail($chatId);
        $this->autorizarCuenta($chat->cuenta);

        $data = $request->validate([
            'tipo' => ['required', 'in:texto,imagen,documento'],
            'contenido' => ['nullable', 'string'],
            'media_url' => ['nullable', 'url'],
        ]);

        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        if ($data['tipo'] === 'texto') {
            $resultado = $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, (string) $data['contenido']);
        } else {
            $resultado = $provider->enviarMedia(
                $chat->cuenta->instancia, $chat->jid, (string) $data['media_url'], $data['tipo'], $data['contenido'] ?? null
            );
        }

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => $data['tipo'],
            'contenido' => $data['contenido'] ?? null,
            'media_url' => $data['media_url'] ?? null,
            'wa_message_id' => $resultado['key']['id'] ?? null,
            'enviado_por' => Auth::id(),
            'timestamp' => now(),
        ]);

        $chat->update(['ultimo_mensaje_at' => now()]);

        return response()->json($mensaje);
    }
}
```

Nota: revisar el nombre real del método de verificación de un solo rol en el modelo `Usuario` — el repo usa `tieneAlgunRol()` (visto en `ConstanciaController`); confirmar si existe también `tieneRol()` singular (grep `function tieneRol` en `app/Models/Usuario.php`) antes de usarlo tal cual; si no existe, usar `tieneAlgunRol(Usuario::ROL_ADMINISTRADOR)`.

- [ ] **Step 4: Registrar las rutas**

En `backend/routes/api.php`, agregar el `use App\Http\Controllers\Api\WhatsAppController;` y, dentro del grupo autenticado con middleware de roles `administrador,gerente,jefe_tienda` (seguir el patrón de agrupación ya usado para otras rutas de rol múltiple en el archivo):
```php
Route::prefix('whatsapp')->group(function () {
    Route::get('cuentas', [WhatsAppController::class, 'cuentas']);
    Route::post('cuentas', [WhatsAppController::class, 'crearCuenta']);
    Route::get('cuentas/{id}/qr', [WhatsAppController::class, 'qr']);
    Route::delete('cuentas/{id}', [WhatsAppController::class, 'eliminarCuenta']);
    Route::get('chats', [WhatsAppController::class, 'chats']);
    Route::get('chats/{id}/mensajes', [WhatsAppController::class, 'mensajes']);
    Route::post('chats/{id}/mensajes', [WhatsAppController::class, 'enviarMensaje']);
});
```

- [ ] **Step 5: Correr los tests y confirmar que pasan**

```
cd backend && php artisan test --filter=WhatsAppScopingTest
```
Esperado: PASS (4 tests).

- [ ] **Step 6: Suite completo**

```
cd backend && php artisan test
```
Esperado: todos verdes.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/WhatsAppController.php backend/routes/api.php backend/tests/Feature/WhatsAppScopingTest.php
git commit -m "feat(whatsapp): endpoints REST de cuentas/chats/mensajes con scoping por tienda"
```

---

### Task 4: Webhook de mensajes entrantes

**Files:**
- Create: `backend/app/Http/Controllers/Api/WhatsAppWebhookController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/WhatsAppWebhookTest.php`

**Interfaces:**
- Produces: `POST v1/whatsapp/webhook` (ruta pública, sin `auth:sanctum`, protegida por token secreto en query string `?token=`) → crea/actualiza `WhatsAppChat` y crea `WhatsAppMensaje` con `direccion='in'`.

- [ ] **Step 1: Escribir el test que falla**

`backend/tests/Feature/WhatsAppWebhookTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_crea_chat_y_mensaje_entrante(): void
    {
        config(['services.evolution.webhook_token' => 'secreto-webhook']);
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'mi-instancia', 'estado' => 'conectada']);

        $payload = [
            'instance' => 'mi-instancia',
            'data' => [
                'key' => ['id' => 'WA999', 'remoteJid' => '51988888888@s.whatsapp.net', 'fromMe' => false],
                'pushName' => 'Juan Pérez',
                'message' => ['conversation' => 'Hola, necesito info'],
                'messageTimestamp' => now()->timestamp,
            ],
        ];

        $response = $this->postJson('/api/v1/whatsapp/webhook?token=secreto-webhook', $payload);

        $response->assertOk();
        $this->assertDatabaseHas('whatsapp_chats', ['cuenta_id' => $cuenta->id, 'jid' => '51988888888@s.whatsapp.net']);
        $chat = WhatsAppChat::first();
        $this->assertDatabaseHas('whatsapp_mensajes', ['chat_id' => $chat->id, 'direccion' => 'in', 'contenido' => 'Hola, necesito info']);
    }

    public function test_webhook_rechaza_token_invalido(): void
    {
        config(['services.evolution.webhook_token' => 'secreto-webhook']);

        $response = $this->postJson('/api/v1/whatsapp/webhook?token=incorrecto', ['instance' => 'x']);

        $response->assertStatus(403);
    }
}
```

- [ ] **Step 2: Correr el test y confirmar que falla**

```
cd backend && php artisan test --filter=WhatsAppWebhookTest
```
Esperado: FAIL.

- [ ] **Step 3: Agregar el token de webhook a config**

En `backend/config/services.php`, dentro de `'evolution' => [...]`, agregar:
```php
        'webhook_token' => env('EVOLUTION_WEBHOOK_TOKEN'),
```
En `backend/.env.example` agregar `EVOLUTION_WEBHOOK_TOKEN=`.

- [ ] **Step 4: Implementar el controller**

`backend/app/Http/Controllers/Api/WhatsAppWebhookController.php`:
```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function recibir(Request $request): JsonResponse
    {
        $tokenEsperado = (string) config('services.evolution.webhook_token');
        if ($tokenEsperado === '' || $request->query('token') !== $tokenEsperado) {
            return response()->json(['message' => 'Token inválido.'], 403);
        }

        $instancia = (string) $request->input('instance');
        $cuenta = WhatsAppCuenta::where('instancia', $instancia)->first();
        if (!$cuenta) {
            Log::warning('whatsapp.webhook_instancia_desconocida', ['instancia' => $instancia]);
            return response()->json(['ok' => true]); // 200 para que Evolution no reintente indefinidamente
        }

        $data = $request->input('data', []);
        $key = $data['key'] ?? [];
        if (($key['fromMe'] ?? false) === true) {
            return response()->json(['ok' => true]); // no espejar mensajes que ya salieron por enviarMensaje()
        }

        $jid = (string) ($key['remoteJid'] ?? '');
        if ($jid === '') {
            return response()->json(['ok' => true]);
        }

        $chat = WhatsAppChat::firstOrCreate(
            ['cuenta_id' => $cuenta->id, 'jid' => $jid],
            ['nombre_contacto' => $data['pushName'] ?? null, 'numero_contacto' => explode('@', $jid)[0] ?? null]
        );

        $contenido = $data['message']['conversation']
            ?? $data['message']['extendedTextMessage']['text']
            ?? null;

        $mensaje = WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'in',
            'tipo' => 'texto',
            'contenido' => $contenido,
            'wa_message_id' => $key['id'] ?? null,
            'timestamp' => isset($data['messageTimestamp'])
                ? \Carbon\Carbon::createFromTimestamp((int) $data['messageTimestamp'])
                : now(),
        ]);

        $chat->update(['ultimo_mensaje_at' => $mensaje->timestamp, 'no_leidos' => $chat->no_leidos + 1]);

        return response()->json(['ok' => true]);
    }
}
```

Nota: este webhook cubre solo mensajes de texto (`conversation`/`extendedTextMessage`). Media entrante (descarga de imagen a storage propio) queda para un task de F3 cuando se defina el flujo de storage — dejar esto documentado, no fabricar el manejo de media sin definirlo.

- [ ] **Step 5: Registrar la ruta pública (fuera del grupo `auth:sanctum`)**

En `backend/routes/api.php`, agregar `use App\Http\Controllers\Api\WhatsAppWebhookController;` y, junto a otras rutas públicas del archivo (buscar dónde vive el grupo sin middleware de auth, ej. cerca de rutas de `postulaciones` o `cpe`):
```php
Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'recibir']);
```

- [ ] **Step 6: Correr los tests y confirmar que pasan**

```
cd backend && php artisan test --filter=WhatsAppWebhookTest
```
Esperado: PASS (2 tests).

- [ ] **Step 7: Suite completo final**

```
cd backend && php artisan test
```
Esperado: todos verdes.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/Api/WhatsAppWebhookController.php backend/routes/api.php backend/config/services.php backend/.env.example backend/tests/Feature/WhatsAppWebhookTest.php
git commit -m "feat(whatsapp): webhook de mensajes entrantes desde Evolution"
```

---

## Fuera de alcance de este plan (queda para F3/F5)

- Descarga y almacenamiento de imágenes/media entrantes.
- UI frontend (F3, plan separado).
- Despliegue real del contenedor Evolution API en el VPS — requiere confirmación explícita de David antes de tocar infraestructura de producción.
- `WatchimpProvider` (F5).
