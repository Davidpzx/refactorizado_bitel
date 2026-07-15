# F6 — Panel de contenido del bot: promos + equipos (refactorizado_bitel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pestaña "Contenido del bot" (solo admin) en el CRM para gestionar una promoción vigente (foto+texto) y fotos por modelo de equipo; la regla "Equipos" del bot pasa a jalar stock real de `InventarioTienda` con esas fotos.

**Architecture:** Dos tablas nuevas (`whatsapp_bot_promocion`, `whatsapp_bot_fotos_producto`) + `ImagenProductoService` (redimensiona a JPEG base64, sin quitar fondo — a diferencia de `LogoProcessorService`). El job `ResponderBotWhatsApp` (F5) se extiende para resolver contenido dinámico según `usa_promocion_dinamica`/`tipo='equipos'` en vez de solo `respuesta` fija. Ver spec: `docs/superpowers/specs/2026-07-15-whatsapp-f6-contenido-bot-design.md`.

**Tech Stack:** Laravel 11 (GD para imágenes, PHPUnit), React 19 + TS + TanStack Query.

## Global Constraints

- `php artisan test --filter=WhatsApp` limpio tras cada task de backend; `npx tsc -b` limpio tras cada task de frontend.
- Fotos: máx 2MB, solo `image/png|jpeg|webp`, redimensionadas a máx 800px de lado mayor, JPEG calidad 80, data URI en BD.
- Panel "Contenido del bot" es **exclusivo de admin** (a diferencia del resto de `whatsapp/*` donde gerente/jefe_tienda también entran).
- `enviarMedia()` del provider **lanza excepción** en fallo (`RuntimeException`) — el job debe capturarla por cada foto individual del catálogo de equipos para no perder el resto del envío.
- Una ejecución del job = una "respuesta" para el límite de F5 (1/chat/minuto), sin importar cuántos mensajes internos genere.

---

### Task 1: Migraciones, modelos y actualización del seeder

**Files:**
- Create: `backend/database/migrations/2026_07_15_000006_create_whatsapp_bot_promocion_table.php`
- Create: `backend/database/migrations/2026_07_15_000007_create_whatsapp_bot_fotos_producto_table.php`
- Create: `backend/database/migrations/2026_07_15_000008_add_promocion_dinamica_a_whatsapp_bot_reglas.php`
- Create: `backend/app/Models/WhatsAppBotPromocion.php`
- Create: `backend/app/Models/WhatsAppBotFotoProducto.php`
- Modify: `backend/database/seeders/WhatsAppBotReglasSeeder.php`
- Modify: `backend/app/Http/Controllers/Api/WhatsAppController.php` (`validarBotRegla` acepta `tipo=equipos`)
- Test: `backend/tests/Feature/WhatsAppBotContenidoMigracionesTest.php`

**Interfaces:**
- Produces: modelos `WhatsAppBotPromocion` (`$fillable = [texto, foto_base64]`, singleton en `id=1`), `WhatsAppBotFotoProducto` (`$fillable = [producto_nombre, foto_base64]`, `producto_nombre` único); `WhatsAppBotRegla.usa_promocion_dinamica: bool`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature;

use App\Models\WhatsAppBotFotoProducto;
use App\Models\WhatsAppBotPromocion;
use App\Models\WhatsAppBotRegla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotContenidoMigracionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_promocion_y_foto_de_producto(): void
    {
        $promo = WhatsAppBotPromocion::create(['id' => 1, 'texto' => 'Promo de prueba', 'foto_base64' => null]);
        $foto = WhatsAppBotFotoProducto::create(['producto_nombre' => 'iPhone 13 128GB', 'foto_base64' => 'data:image/jpeg;base64,abc']);
        $regla = WhatsAppBotRegla::create(['nombre' => 'Planes', 'tipo' => 'texto', 'usa_promocion_dinamica' => true, 'respuesta' => 'x']);

        $this->assertSame('Promo de prueba', $promo->fresh()->texto);
        $this->assertSame('iPhone 13 128GB', $foto->fresh()->producto_nombre);
        $this->assertTrue($regla->fresh()->usa_promocion_dinamica);
    }

    public function test_producto_nombre_es_unico(): void
    {
        WhatsAppBotFotoProducto::create(['producto_nombre' => 'X', 'foto_base64' => 'a']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        WhatsAppBotFotoProducto::create(['producto_nombre' => 'X', 'foto_base64' => 'b']);
    }
}
```

```
cd backend && php artisan test --filter=WhatsAppBotContenidoMigracionesTest
```
Esperado: FAIL (tablas/columna no existen).

- [ ] **Step 2: Migraciones**

`2026_07_15_000006_create_whatsapp_bot_promocion_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_promocion', function (Blueprint $table) {
            $table->id();
            $table->text('texto');
            $table->longText('foto_base64')->nullable();
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_promocion');
    }
};
```

`2026_07_15_000007_create_whatsapp_bot_fotos_producto_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_fotos_producto', function (Blueprint $table) {
            $table->id();
            $table->string('producto_nombre', 150)->unique();
            $table->longText('foto_base64');
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_fotos_producto');
    }
};
```

`2026_07_15_000008_add_promocion_dinamica_a_whatsapp_bot_reglas.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->boolean('usa_promocion_dinamica')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bot_reglas', fn (Blueprint $t) => $t->dropColumn('usa_promocion_dinamica'));
    }
};
```

- [ ] **Step 3: Modelos**

`backend/app/Models/WhatsAppBotPromocion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotPromocion extends Model
{
    protected $table = 'whatsapp_bot_promocion';

    protected $fillable = ['texto', 'foto_base64'];
}
```

`backend/app/Models/WhatsAppBotFotoProducto.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotFotoProducto extends Model
{
    protected $table = 'whatsapp_bot_fotos_producto';

    protected $fillable = ['producto_nombre', 'foto_base64'];
}
```

Agregar `'usa_promocion_dinamica'` al `$fillable` de `WhatsAppBotRegla` y el cast `'usa_promocion_dinamica' => 'boolean'`.

- [ ] **Step 4: Actualizar el seeder**

En `WhatsAppBotReglasSeeder::run()`, cambiar la creación de `$planes` y `$equipos`:

```php
        $planes = WhatsAppBotRegla::create([
            'nombre' => 'Planes', 'tipo' => 'texto', 'prioridad' => 10,
            'usa_promocion_dinamica' => true,
            'palabras_clave' => ['plan', 'planes', 'promocion', 'precio', 'cuanto'],
            'respuesta' => "Estos son nuestros planes vigentes 📱:\n\n• Plan S/29.90 — 20GB + llamadas ilimitadas\n• Plan S/39.90 — 40GB + llamadas ilimitadas\n• Plan S/49.90 — GB ilimitados",
        ]);
        $equipos = WhatsAppBotRegla::create([
            'nombre' => 'Equipos', 'tipo' => 'equipos', 'prioridad' => 10,
            'palabras_clave' => ['equipo', 'equipos', 'celular', 'telefono', 'stock'],
            'respuesta' => 'Por ahora no tenemos equipos en stock, un asesor te confirma disponibilidad.',
        ]);
```

- [ ] **Step 5: Ampliar la validación de `tipo` en el controller**

En `WhatsAppController::validarBotRegla()`, cambiar `'tipo' => ['required', 'in:texto,menu']` por `'tipo' => ['required', 'in:texto,menu,equipos']`, y agregar `'usa_promocion_dinamica' => ['sometimes', 'boolean']` al array de reglas.

- [ ] **Step 6: Correr migraciones + test y commit**

```bash
cd backend && php artisan migrate && php artisan test --filter=WhatsAppBotContenidoMigracionesTest
git add backend/database/migrations/2026_07_15_000006_create_whatsapp_bot_promocion_table.php backend/database/migrations/2026_07_15_000007_create_whatsapp_bot_fotos_producto_table.php backend/database/migrations/2026_07_15_000008_add_promocion_dinamica_a_whatsapp_bot_reglas.php backend/app/Models/WhatsAppBotPromocion.php backend/app/Models/WhatsAppBotFotoProducto.php backend/app/Models/WhatsAppBotRegla.php backend/database/seeders/WhatsAppBotReglasSeeder.php backend/app/Http/Controllers/Api/WhatsAppController.php backend/tests/Feature/WhatsAppBotContenidoMigracionesTest.php
git commit -m "feat(whatsapp): modelo de datos de contenido del bot (promocion, fotos de producto)"
```

---

### Task 2: `ImagenProductoService`

**Files:**
- Create: `backend/app/Services/WhatsApp/ImagenProductoService.php`
- Test: `backend/tests/Unit/ImagenProductoServiceTest.php`

**Interfaces:**
- Produces: `ImagenProductoService::procesar(string $rutaArchivo): ?string` — data URI JPEG (máx 800px lado mayor, calidad 80) o `null` si no es imagen válida.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Unit;

use App\Services\WhatsApp\ImagenProductoService;
use Tests\TestCase;

class ImagenProductoServiceTest extends TestCase
{
    public function test_procesa_una_imagen_valida(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $img = imagecreatetruecolor(1000, 500);
        imagepng($img, $ruta);
        imagedestroy($img);

        $resultado = (new ImagenProductoService())->procesar($ruta);

        $this->assertNotNull($resultado);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $resultado);
        unlink($ruta);
    }

    public function test_devuelve_null_si_no_es_imagen(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'notimg') . '.txt';
        file_put_contents($ruta, 'no soy una imagen');

        $resultado = (new ImagenProductoService())->procesar($ruta);

        $this->assertNull($resultado);
        unlink($ruta);
    }
}
```

```
cd backend && php artisan test --filter=ImagenProductoServiceTest
```
Esperado: FAIL (clase no existe).

- [ ] **Step 2: Implementar**

```php
<?php

namespace App\Services\WhatsApp;

class ImagenProductoService
{
    private const MAX_LADO = 800;

    /** Redimensiona a max 800px de lado mayor y devuelve un data URI JPEG. Null si no es una imagen valida. */
    public function procesar(string $rutaArchivo): ?string
    {
        if (! is_file($rutaArchivo)) {
            return null;
        }

        $info = @getimagesize($rutaArchivo);
        if ($info === false) {
            return null;
        }

        $src = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($rutaArchivo),
            IMAGETYPE_PNG => @imagecreatefrompng($rutaArchivo),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($rutaArchivo) : false,
            default => false,
        };
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $escala = min(1.0, self::MAX_LADO / max($w, $h));
        $nw = max(1, (int) round($w * $escala));
        $nh = max(1, (int) round($h * $escala));

        $img = imagecreatetruecolor($nw, $nh);
        $blanco = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $nw, $nh, $blanco);
        imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        ob_start();
        imagejpeg($img, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        if ($jpeg === false || $jpeg === '') {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
```

- [ ] **Step 3: Correr (PASS) y commit**

```bash
cd backend && php artisan test --filter=ImagenProductoServiceTest
git add backend/app/Services/WhatsApp/ImagenProductoService.php backend/tests/Unit/ImagenProductoServiceTest.php
git commit -m "feat(whatsapp): servicio de procesamiento de fotos de producto"
```

---

### Task 3: Controller + rutas de administración de contenido

**Files:**
- Create: `backend/app/Http/Controllers/Api/WhatsAppContenidoController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/WhatsAppContenidoAdminTest.php`

**Interfaces:**
- Produces: `GET/POST /v1/whatsapp/promocion`; `GET/POST /v1/whatsapp/fotos-producto`; `DELETE /v1/whatsapp/fotos-producto/{id}`; `GET /v1/whatsapp/inventario/nombres?q=`. Todos exclusivos de `role:administrador`.

- [ ] **Step 1: Tests que fallan**

```php
<?php

namespace Tests\Feature;

use App\Models\InventarioTienda;
use App\Models\Usuario;
use App\Models\WhatsAppBotFotoProducto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class WhatsAppContenidoAdminTest extends TestCase
{
    use RefreshDatabase;

    private function fotoFake(): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'img') . '.png';
        $img = imagecreatetruecolor(100, 100);
        imagepng($img, $ruta);
        imagedestroy($img);

        return new UploadedFile($ruta, 'foto.png', 'image/png', null, true);
    }

    public function test_no_admin_recibe_403(): void
    {
        $jefe = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $this->actingAs($jefe, 'sanctum')->getJson('/api/v1/whatsapp/promocion')->assertStatus(403);
        $this->actingAs($jefe, 'sanctum')->getJson('/api/v1/whatsapp/fotos-producto')->assertStatus(403);
    }

    public function test_admin_guarda_y_lee_la_promocion(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/whatsapp/promocion', [
            'texto' => 'Promo de prueba',
        ])->assertOk();

        $get = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/whatsapp/promocion');
        $get->assertOk();
        $this->assertSame('Promo de prueba', $get->json('data.texto'));
    }

    public function test_admin_sube_foto_de_producto_valida_con_upsert(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $this->actingAs($admin, 'sanctum')->post('/api/v1/whatsapp/fotos-producto', [
            'producto_nombre' => 'iPhone 13 128GB',
            'foto' => $this->fotoFake(),
        ])->assertOk();

        $this->assertSame(1, WhatsAppBotFotoProducto::where('producto_nombre', 'iPhone 13 128GB')->count());

        // Segunda subida con el mismo nombre reemplaza, no duplica.
        $this->actingAs($admin, 'sanctum')->post('/api/v1/whatsapp/fotos-producto', [
            'producto_nombre' => 'iPhone 13 128GB',
            'foto' => $this->fotoFake(),
        ])->assertOk();

        $this->assertSame(1, WhatsAppBotFotoProducto::where('producto_nombre', 'iPhone 13 128GB')->count());
    }

    public function test_buscar_nombres_de_inventario(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'administrador']);
        InventarioTienda::create(['tienda_id' => 'T01', 'producto_nombre' => 'iPhone 13 128GB', 'tipo' => 'EQUIPO', 'estado' => 'DISPONIBLE', 'cantidad' => 2]);

        $r = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/whatsapp/inventario/nombres?q=iphone');

        $r->assertOk();
        $this->assertContains('iPhone 13 128GB', $r->json());
    }
}
```

```
cd backend && php artisan test --filter=WhatsAppContenidoAdminTest
```
Esperado: FAIL (rutas no existen).

- [ ] **Step 2: Controller**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventarioTienda;
use App\Models\Usuario;
use App\Models\WhatsAppBotFotoProducto;
use App\Models\WhatsAppBotPromocion;
use App\Services\WhatsApp\ImagenProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppContenidoController extends Controller
{
    private function esAdministrador(): bool
    {
        $user = Auth::user();

        return $user instanceof Usuario && $user->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR);
    }

    public function promocion(): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json(['ok' => true, 'data' => WhatsAppBotPromocion::find(1)]);
    }

    public function guardarPromocion(Request $request, ImagenProductoService $procesador): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $data = $request->validate([
            'texto' => ['required', 'string'],
            'foto' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $valores = ['texto' => $data['texto']];
        if ($request->hasFile('foto')) {
            $dataUri = $procesador->procesar($request->file('foto')->getRealPath());
            if ($dataUri === null) {
                return response()->json(['message' => 'No se pudo procesar la imagen.'], 422);
            }
            $valores['foto_base64'] = $dataUri;
        }

        WhatsAppBotPromocion::updateOrCreate(['id' => 1], $valores);

        return response()->json(['ok' => true]);
    }

    public function fotosProducto(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        return response()->json(['ok' => true, 'data' => WhatsAppBotFotoProducto::orderBy('producto_nombre')->get()]);
    }

    public function guardarFotoProducto(Request $request, ImagenProductoService $procesador): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $data = $request->validate([
            'producto_nombre' => ['required', 'string', 'max:150'],
            'foto' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $dataUri = $procesador->procesar($request->file('foto')->getRealPath());
        if ($dataUri === null) {
            return response()->json(['message' => 'No se pudo procesar la imagen.'], 422);
        }

        WhatsAppBotFotoProducto::updateOrCreate(
            ['producto_nombre' => $data['producto_nombre']],
            ['foto_base64' => $dataUri]
        );

        return response()->json(['ok' => true]);
    }

    public function eliminarFotoProducto(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        WhatsAppBotFotoProducto::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function buscarNombresInventario(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json([]);
        }

        $nombres = InventarioTienda::where('producto_nombre', 'like', "%{$q}%")
            ->distinct()->limit(10)->pluck('producto_nombre');

        return response()->json($nombres);
    }
}
```

- [ ] **Step 3: Rutas** (dentro del grupo `whatsapp` existente en `routes/api.php`)

```php
        Route::get('promocion', [WhatsAppContenidoController::class, 'promocion']);
        Route::post('promocion', [WhatsAppContenidoController::class, 'guardarPromocion']);
        Route::get('fotos-producto', [WhatsAppContenidoController::class, 'fotosProducto']);
        Route::post('fotos-producto', [WhatsAppContenidoController::class, 'guardarFotoProducto']);
        Route::delete('fotos-producto/{id}', [WhatsAppContenidoController::class, 'eliminarFotoProducto']);
        Route::get('inventario/nombres', [WhatsAppContenidoController::class, 'buscarNombresInventario']);
```

Agregar `use App\Http\Controllers\Api\WhatsAppContenidoController;` al inicio de `routes/api.php`.

- [ ] **Step 4: Correr tests (PASS), suite completa y commit**

```bash
cd backend && php artisan test --filter=WhatsAppContenidoAdminTest && php artisan test --filter=WhatsApp
git add backend/app/Http/Controllers/Api/WhatsAppContenidoController.php backend/routes/api.php backend/tests/Feature/WhatsAppContenidoAdminTest.php
git commit -m "feat(whatsapp): endpoints admin de promocion y fotos de producto"
```

---

### Task 4: Job — resolver contenido dinámico (promoción + catálogo de equipos)

**Files:**
- Modify: `backend/app/Jobs/ResponderBotWhatsApp.php`
- Test: `backend/tests/Feature/ResponderBotWhatsAppContenidoTest.php`

**Interfaces:**
- Consumes: `WhatsAppBotPromocion`, `WhatsAppBotFotoProducto`, `InventarioTienda` (scopes `porTienda`/`porTipo`/`porEstado` ya existentes), `EvolutionProvider::enviarMedia()`.
- Produces: el job envía fotos + texto cuando `regla->usa_promocion_dinamica` o `regla->tipo === 'equipos'`.

- [ ] **Step 1: Tests que fallan**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ResponderBotWhatsApp;
use App\Models\InventarioTienda;
use App\Models\WhatsAppBotFotoProducto;
use App\Models\WhatsAppBotPromocion;
use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResponderBotWhatsAppContenidoTest extends TestCase
{
    use RefreshDatabase;

    private function cuentaYChat(?string $tiendaId = 'T01'): array
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'inst', 'tienda_id' => $tiendaId, 'estado' => 'conectada', 'bot_activo' => true]);
        $chat = WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => '51999@s.whatsapp.net', 'no_leidos' => 0]);

        return [$cuenta, $chat];
    }

    public function test_regla_con_promocion_dinamica_envia_foto_y_texto_de_la_promocion(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        [$cuenta, $chat] = $this->cuentaYChat();
        WhatsAppBotPromocion::create(['id' => 1, 'texto' => 'Promo activa', 'foto_base64' => 'data:image/jpeg;base64,abc']);
        $regla = WhatsAppBotRegla::create(['nombre' => 'Planes', 'tipo' => 'texto', 'usa_promocion_dinamica' => true, 'respuesta' => 'fallback']);

        (new ResponderBotWhatsApp($chat->id, $regla->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendMedia/inst') && $req['caption'] === 'Promo activa');
        $this->assertSame(1, WhatsAppMensaje::where('chat_id', $chat->id)->where('direccion', 'out')->count());
    }

    public function test_regla_con_promocion_dinamica_sin_promo_configurada_usa_fallback(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        [$cuenta, $chat] = $this->cuentaYChat();
        $regla = WhatsAppBotRegla::create(['nombre' => 'Planes', 'tipo' => 'texto', 'usa_promocion_dinamica' => true, 'respuesta' => 'fallback fijo']);

        (new ResponderBotWhatsApp($chat->id, $regla->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst') && $req['text'] === 'fallback fijo');
    }

    public function test_regla_equipos_envia_foto_del_modelo_con_stock_en_su_tienda(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        [$cuenta, $chat] = $this->cuentaYChat('T01');
        InventarioTienda::create(['tienda_id' => 'T01', 'producto_nombre' => 'iPhone 13', 'tipo' => 'EQUIPO', 'estado' => 'DISPONIBLE', 'cantidad' => 3, 'precio_normal' => 1500]);
        InventarioTienda::create(['tienda_id' => 'T02', 'producto_nombre' => 'Samsung A54', 'tipo' => 'EQUIPO', 'estado' => 'DISPONIBLE', 'cantidad' => 5, 'precio_normal' => 900]);
        WhatsAppBotFotoProducto::create(['producto_nombre' => 'iPhone 13', 'foto_base64' => 'data:image/jpeg;base64,abc']);
        $regla = WhatsAppBotRegla::create(['nombre' => 'Equipos', 'tipo' => 'equipos', 'respuesta' => 'sin stock']);

        (new ResponderBotWhatsApp($chat->id, $regla->id))->handle();

        // Solo debe aparecer el de T01 (su propia tienda), no el de T02.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendMedia/inst') && str_contains((string) ($req['caption'] ?? ''), 'iPhone 13'));
        Http::assertNotSent(fn ($req) => str_contains((string) ($req['caption'] ?? ''), 'Samsung'));
    }

    public function test_regla_equipos_sin_stock_usa_fallback(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        [$cuenta, $chat] = $this->cuentaYChat('T01');
        $regla = WhatsAppBotRegla::create(['nombre' => 'Equipos', 'tipo' => 'equipos', 'respuesta' => 'sin stock disponible']);

        (new ResponderBotWhatsApp($chat->id, $regla->id))->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/message/sendText/inst') && $req['text'] === 'sin stock disponible');
    }
}
```

```
cd backend && php artisan test --filter=ResponderBotWhatsAppContenidoTest
```
Esperado: FAIL (el job todavía no soporta promoción dinámica ni `tipo=equipos`).

- [ ] **Step 2: Reemplazar la resolución de contenido en el job**

En `backend/app/Jobs/ResponderBotWhatsApp.php`, reemplazar el bloque completo que va desde `// Resolver contenido.` hasta el `WhatsAppMensaje::create([...])` final (justo antes del cierre de `handle()`) por:

```php
        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        if ($this->esAsesor) {
            $largo = mb_strlen(BotResponder::TEXTO_ASESOR);
            $delayMs = max(3000, min(8000, $largo * 60));
            $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
            usleep($delayMs * 1000);
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, BotResponder::TEXTO_ASESOR);
            $this->registrarMensaje($chat, BotResponder::TEXTO_ASESOR);
            return;
        }

        $regla = WhatsAppBotRegla::where('activa', true)->find($this->reglaId);
        if (!$regla) {
            $this->descartar('regla_inexistente');
            return;
        }

        $largoBase = mb_strlen((string) ($regla->respuesta ?? ''));
        $delayMs = max(3000, min(8000, $largoBase * 60));
        $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
        usleep($delayMs * 1000);

        if ($regla->usa_promocion_dinamica) {
            $this->enviarPromocionDinamica($chat, $provider, (string) $regla->respuesta);
            return;
        }

        if ($regla->tipo === 'equipos') {
            $this->enviarCatalogoEquipos($chat, $provider, (string) $regla->respuesta);
            return;
        }

        $tipo = $regla->tipo;
        $contenido = (string) ($regla->respuesta ?? '');
        $menuTitulo = (string) ($regla->menu_titulo ?? '');
        $opciones = $regla->opciones ?? [];

        $textoRegistrado = $contenido;
        if ($tipo === 'menu') {
            $resultado = $provider->enviarLista($chat->cuenta->instancia, $chat->jid, $menuTitulo, $opciones);
            $lineas = [$menuTitulo];
            foreach (array_values($opciones) as $i => $op) {
                $lineas[] = ($i + 1) . '. ' . ($op['texto'] ?? '');
            }
            if ($resultado === []) {
                $lineas[] = '';
                $lineas[] = 'Responde con el número de la opción.';
                $textoRegistrado = implode("\n", $lineas);
                $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoRegistrado);
            } else {
                $textoRegistrado = implode("\n", $lineas);
            }
        } else {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $contenido);
        }

        $this->registrarMensaje($chat, $textoRegistrado);
    }

    private function enviarPromocionDinamica(WhatsAppChat $chat, $provider, string $textoFallback): void
    {
        $promo = WhatsAppBotPromocion::find(1);
        if (!$promo || trim((string) $promo->texto) === '') {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoFallback);
            $this->registrarMensaje($chat, $textoFallback);
            return;
        }

        if ($promo->foto_base64) {
            $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $promo->foto_base64);
            try {
                $provider->enviarMedia($chat->cuenta->instancia, $chat->jid, $base64, 'image', $promo->texto);
            } catch (\RuntimeException $e) {
                $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $promo->texto);
            }
        } else {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $promo->texto);
        }
        $this->registrarMensaje($chat, $promo->texto);
    }

    private function enviarCatalogoEquipos(WhatsAppChat $chat, $provider, string $textoFallback): void
    {
        $tiendaId = $chat->cuenta->tienda_id;

        $modelos = InventarioTienda::query()
            ->where('tipo', 'EQUIPO')->where('estado', 'DISPONIBLE')->where('cantidad', '>', 0)
            ->when($tiendaId !== null, fn ($q) => $q->where('tienda_id', $tiendaId))
            ->selectRaw('producto_nombre, SUM(cantidad) as stock, MAX(precio_normal) as precio')
            ->groupBy('producto_nombre')->orderByDesc('stock')->limit(5)->get();

        if ($modelos->isEmpty()) {
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $textoFallback);
            $this->registrarMensaje($chat, $textoFallback);
            return;
        }

        $sinFoto = [];
        foreach ($modelos as $m) {
            $caption = $m->producto_nombre.' — S/'.number_format((float) $m->precio, 2);
            $foto = WhatsAppBotFotoProducto::where('producto_nombre', $m->producto_nombre)->value('foto_base64');

            if ($foto) {
                $base64 = preg_replace('/^data:image\/\w+;base64,/', '', $foto);
                try {
                    $provider->enviarMedia($chat->cuenta->instancia, $chat->jid, $base64, 'image', $caption);
                    $this->registrarMensaje($chat, $caption);
                    usleep(rand(1000, 2000) * 1000);
                } catch (\RuntimeException $e) {
                    $sinFoto[] = $caption;
                }
            } else {
                $sinFoto[] = $caption;
            }
        }

        if (!empty($sinFoto)) {
            $texto = "Otros modelos disponibles:\n".implode("\n", $sinFoto);
            $provider->enviarTexto($chat->cuenta->instancia, $chat->jid, $texto);
            $this->registrarMensaje($chat, $texto);
        }
    }

    private function registrarMensaje(WhatsAppChat $chat, string $texto): void
    {
        WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => 'texto',
            'contenido' => $texto,
            'enviado_por' => null,
            'timestamp' => now(),
        ]);
        $chat->update(['ultimo_mensaje_at' => now()]);
    }
```

Agregar los `use` necesarios al inicio del archivo: `App\Models\InventarioTienda`, `App\Models\WhatsAppBotFotoProducto`, `App\Models\WhatsAppBotPromocion`. Verificar que el límite de 1/minuto y 20/hora, y las re-verificaciones de `bot_activo`/`bot_silenciado_hasta`/`humano_respondio`, siguen intactas **antes** de este bloque reemplazado (no se tocan, solo cambia lo que pasa después de superarlas).

- [ ] **Step 3: Correr tests (PASS), suite completa y commit**

```bash
cd backend && php artisan test --filter=ResponderBotWhatsAppContenidoTest && php artisan test --filter=WhatsApp
git add backend/app/Jobs/ResponderBotWhatsApp.php backend/tests/Feature/ResponderBotWhatsAppContenidoTest.php
git commit -m "feat(whatsapp): job envia promocion dinamica y catalogo de equipos con fotos"
```

---

### Task 5: Frontend — tipos, API, hooks

**Files:**
- Modify: `frontend/src/types/whatsapp.ts`
- Modify: `frontend/src/services/whatsapp.api.ts`
- Modify: `frontend/src/hooks/useWhatsApp.ts`

**Interfaces:**
- Produces: tipos `WhatsAppPromocion`, `WhatsAppFotoProducto`; `whatsappApi.contenido.{promocion, guardarPromocion, fotosProducto, guardarFotoProducto, eliminarFotoProducto, buscarInventario}`; hooks `usePromocion`, `useGuardarPromocion`, `useFotosProducto`, `useGuardarFotoProducto`, `useEliminarFotoProducto`, `useBuscarProductosInventario`.

- [ ] **Step 1: Tipos**

En `types/whatsapp.ts`:

```ts
export interface WhatsAppPromocion {
  id: number
  texto: string
  foto_base64: string | null
}

export interface WhatsAppFotoProducto {
  id: number
  producto_nombre: string
  foto_base64: string
}
```

- [ ] **Step 2: API**

En `services/whatsapp.api.ts`, agregar al objeto `whatsappApi`:

```ts
  contenido: {
    promocion: (): Promise<WhatsAppPromocion | null> =>
      api.get('/v1/whatsapp/promocion').then(r => r.data.data),

    guardarPromocion: (texto: string, foto?: File): Promise<{ ok: boolean }> => {
      const fd = new FormData()
      fd.append('texto', texto)
      if (foto) fd.append('foto', foto)
      return api.post('/v1/whatsapp/promocion', fd).then(r => r.data)
    },

    fotosProducto: (): Promise<WhatsAppFotoProducto[]> =>
      api.get('/v1/whatsapp/fotos-producto').then(r => r.data.data),

    guardarFotoProducto: (productoNombre: string, foto: File): Promise<{ ok: boolean }> => {
      const fd = new FormData()
      fd.append('producto_nombre', productoNombre)
      fd.append('foto', foto)
      return api.post('/v1/whatsapp/fotos-producto', fd).then(r => r.data)
    },

    eliminarFotoProducto: (id: number): Promise<void> =>
      api.delete(`/v1/whatsapp/fotos-producto/${id}`).then(r => r.data),

    buscarInventario: (q: string): Promise<string[]> =>
      api.get('/v1/whatsapp/inventario/nombres', { params: { q } }).then(r => r.data),
  },
```

Importar `WhatsAppFotoProducto, WhatsAppPromocion` en el archivo.

- [ ] **Step 3: Hooks**

En `hooks/useWhatsApp.ts`:

```ts
export function usePromocion() {
  return useQuery({ queryKey: ['whatsapp-promocion'], queryFn: () => whatsappApi.contenido.promocion() })
}

export function useGuardarPromocion() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ texto, foto }: { texto: string; foto?: File }) => whatsappApi.contenido.guardarPromocion(texto, foto),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-promocion'] }),
  })
}

export function useFotosProducto() {
  return useQuery({ queryKey: ['whatsapp-fotos-producto'], queryFn: () => whatsappApi.contenido.fotosProducto() })
}

export function useGuardarFotoProducto() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ productoNombre, foto }: { productoNombre: string; foto: File }) => whatsappApi.contenido.guardarFotoProducto(productoNombre, foto),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-fotos-producto'] }),
  })
}

export function useEliminarFotoProducto() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => whatsappApi.contenido.eliminarFotoProducto(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-fotos-producto'] }),
  })
}

export function useBuscarProductosInventario(q: string) {
  return useQuery({
    queryKey: ['inventario-nombres', q],
    queryFn: () => whatsappApi.contenido.buscarInventario(q),
    enabled: q.length >= 2,
  })
}
```

- [ ] **Step 4: `npx tsc -b` limpio y commit**

```bash
cd frontend && npx tsc -b
git add frontend/src/types/whatsapp.ts frontend/src/services/whatsapp.api.ts frontend/src/hooks/useWhatsApp.ts
git commit -m "feat(whatsapp): tipos, API y hooks de contenido del bot en el frontend"
```

---

### Task 6: Frontend — pestaña "Contenido del bot"

**Files:**
- Create: `frontend/src/pages/crm/CrmContenidoBotTab.tsx`
- Modify: `frontend/src/pages/crm/CrmPage.tsx`

**Interfaces:**
- Consumes: hooks de Task 5.
- Produces: `CrmContenidoBotTab()` — visible en `CrmPage` como pestaña `'contenido'`, solo si `usuario.rol === 'administrador'`.

- [ ] **Step 1: `CrmContenidoBotTab.tsx`**

```tsx
import { useRef, useState } from 'react'
import { Trash } from '@phosphor-icons/react'
import {
  useBuscarProductosInventario,
  useEliminarFotoProducto,
  useFotosProducto,
  useGuardarFotoProducto,
  useGuardarPromocion,
  usePromocion,
} from '../../hooks/useWhatsApp'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'

function PromocionForm() {
  const { data: promocion } = usePromocion()
  const guardar = useGuardarPromocion()
  const [texto, setTexto] = useState(promocion?.texto ?? '')
  const [foto, setFoto] = useState<File | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  const preview = foto ? URL.createObjectURL(foto) : promocion?.foto_base64

  const handleGuardar = () => {
    if (!texto.trim()) return
    guardar.mutate(
      { texto: texto.trim(), foto: foto ?? undefined },
      { onSuccess: () => { setFoto(null); if (fileRef.current) fileRef.current.value = '' } }
    )
  }

  return (
    <div className="kyro-card space-y-3 p-4">
      <h3 className="text-sm font-semibold">Promoción vigente</h3>
      {preview && <img src={preview} alt="Promoción" className="max-h-56 w-full rounded-kyro object-cover" />}
      <textarea
        value={texto || promocion?.texto || ''}
        onChange={e => setTexto(e.target.value)}
        rows={4}
        placeholder="Texto de la promoción vigente..."
        className="w-full rounded-kyro border border-kyro-border bg-transparent p-2 text-sm"
      />
      <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setFoto(e.target.files?.[0] ?? null)} />
      <Button variant="gold" size="sm" disabled={guardar.isPending} onClick={handleGuardar}>
        {guardar.isPending ? 'Guardando...' : 'Guardar promoción'}
      </Button>
    </div>
  )
}

function FotosProductoPanel() {
  const { data: fotos = [] } = useFotosProducto()
  const guardar = useGuardarFotoProducto()
  const eliminar = useEliminarFotoProducto()
  const [nombre, setNombre] = useState('')
  const [foto, setFoto] = useState<File | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)
  const { data: sugerencias = [] } = useBuscarProductosInventario(nombre)

  const handleSubir = () => {
    if (!nombre.trim() || !foto) return
    guardar.mutate(
      { productoNombre: nombre.trim(), foto },
      { onSuccess: () => { setNombre(''); setFoto(null); if (fileRef.current) fileRef.current.value = '' } }
    )
  }

  return (
    <div className="kyro-card space-y-3 p-4">
      <h3 className="text-sm font-semibold">Fotos de equipos</h3>
      <div className="relative">
        <Input value={nombre} onChange={e => setNombre(e.target.value)} placeholder="Nombre del producto (ej. iPhone 13 128GB)" />
        {sugerencias.length > 0 && nombre.length >= 2 && (
          <div className="absolute z-10 mt-1 w-full rounded-kyro border border-kyro-border bg-kyro-surface shadow-lg">
            {sugerencias.map(s => (
              <button key={s} type="button" onClick={() => setNombre(s)} className="block w-full px-3 py-1.5 text-left text-xs hover:bg-kyro-border/40">
                {s}
              </button>
            ))}
          </div>
        )}
      </div>
      <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setFoto(e.target.files?.[0] ?? null)} />
      <Button variant="gold" size="sm" disabled={guardar.isPending} onClick={handleSubir}>
        {guardar.isPending ? 'Subiendo...' : 'Subir / reemplazar foto'}
      </Button>

      <div className="max-h-72 space-y-1 overflow-y-auto">
        {fotos.map(f => (
          <div key={f.id} className="flex items-center justify-between rounded-kyro border border-kyro-border px-2 py-1.5">
            <div className="flex items-center gap-2 min-w-0">
              <img src={f.foto_base64} alt={f.producto_nombre} className="h-9 w-9 rounded object-cover" />
              <span className="truncate text-xs">{f.producto_nombre}</span>
            </div>
            <button
              type="button"
              onClick={() => { if (confirm(`Eliminar la foto de "${f.producto_nombre}"?`)) eliminar.mutate(f.id) }}
              className="text-kyro-muted hover:text-red-400"
            >
              <Trash size={14} />
            </button>
          </div>
        ))}
        {fotos.length === 0 && <p className="py-4 text-center text-xs text-kyro-muted">Sin fotos todavía.</p>}
      </div>
    </div>
  )
}

export function CrmContenidoBotTab() {
  return (
    <div className="grid gap-4 md:grid-cols-2">
      <PromocionForm />
      <FotosProductoPanel />
    </div>
  )
}
```

- [ ] **Step 2: Cablear en `CrmPage.tsx`**

Agregar `'contenido'` a `CrmTab`, un botón nuevo en `TABS` (con `Image` de `@phosphor-icons/react`) solo si el rol es administrador, e importar/renderizar `CrmContenidoBotTab` cuando `tab === 'contenido'`. Filtrar el array `TABS` con `.filter(t => t.value !== 'contenido' || esAdmin)` donde `esAdmin = normalizarRol(usuario?.rol) === 'administrador'` (importar `normalizarRol` de `../../utils/roles`, mismo patrón ya usado en `CrmWhatsAppTab`).

- [ ] **Step 3: `npx tsc -b` limpio, prueba en navegador y commit**

```bash
cd frontend && npx tsc -b
git add frontend/src/pages/crm/CrmContenidoBotTab.tsx frontend/src/pages/crm/CrmPage.tsx
git commit -m "feat(whatsapp): pestana Contenido del bot (promocion y fotos de equipos)"
```

---

### Task 7: Prueba end-to-end en producción

**Files:** ninguno nuevo (operación).

- [ ] **Step 1:** correr migraciones + `db:seed --class=WhatsAppBotReglasSeeder --force` no aplica de nuevo (el seeder es idempotente y ya corrió en F5) — en su lugar, actualizar manualmente vía tinker o SQL directo la regla "Planes" (`usa_promocion_dinamica=1`) y "Equipos" (`tipo='equipos'`) si el seeder no vuelve a correr.
- [ ] **Step 2:** subir una promo real con foto desde la pestaña nueva.
- [ ] **Step 3:** escribir "precio" desde otro número a la cuenta de prueba → debe llegar la foto con el texto como caption. Si Evolution rechaza el base64 puro en `media`, revisar `Log::warning('evolution.enviar_media_fallo', ...)` y probar con el data URI completo como alternativa.
- [ ] **Step 4:** subir foto de un producto real con stock en la tienda de la cuenta de prueba → "equipos" → debe llegar la foto + resumen de los demás modelos.
- [ ] **Step 5:** verificar que una tienda sin stock cae al fallback sin romper.

---

## Fuera de alcance de este plan

- Múltiples promociones simultáneas.
- Editor de imágenes (recorte, filtros).
- Validación dura de `producto_nombre` contra el inventario real al guardar (el autocompletar es solo ayuda).
