# F5 — Bot de auto-respuesta + detección de interesados (refactorizado_bitel) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bot de auto-respuesta por reglas (menú de bienvenida interactivo + anti-baneo) y detección automática de interesados que mueve leads a `INTERESADO`, sobre el inbox de F1-F4.

**Architecture:** El webhook puntúa interés y despacha un job diferido (`ResponderBotWhatsApp`, Redis queue) con delay aleatorio; el job re-verifica condiciones, envía presencia "escribiendo..." y responde vía `EvolutionProvider`. Matching puro en `App\Services\WhatsApp\BotResponder` (testeable sin HTTP). Reglas en `whatsapp_bot_reglas` con CRUD solo-admin y UI React en el inbox. Ver spec: `docs/superpowers/specs/2026-07-15-whatsapp-f5-bot-design.md`.

**Tech Stack:** Laravel 11 (jobs + Redis, PHPUnit RefreshDatabase), React 19 + TS + TanStack Query.

## Global Constraints

- `php artisan test --filter=WhatsApp` limpio tras cada task de backend; `npx tsc -b` limpio tras cada task de frontend.
- El webhook NUNCA responde inline — solo `dispatch()->delay(rand(25,90)s)`.
- Marca de bot: `whatsapp_mensajes.enviado_por IS NULL` con `direccion='out'`.
- Límites: 1 respuesta de bot por chat/minuto, 20 por cuenta/hora.
- Convención: opción `id === 'op_asesor'` → silencia bot 24h, `interes_score += 3`, responde "Listo, un asesor te escribe en breve 👋".
- Umbral: `interes_score >= 5` (al cruzarlo por primera vez) → badge 🔥 + lead a `INTESADO` si aplica. El estado del lead solo se cambia si está en `NUEVO` o `CONTACTADO` (nunca degradar `CONVERTIDO`/`PERDIDO`).
- El job tiene `$tries = 1` (sin reintentos: un bot que reintenta es un bot que spamea).

---

### Task 1: Migraciones + modelo + seeder

**Files:**
- Create: `backend/database/migrations/2026_07_15_000004_add_bot_a_whatsapp_tablas.php`
- Create: `backend/database/migrations/2026_07_15_000005_create_whatsapp_bot_reglas_table.php`
- Create: `backend/app/Models/WhatsAppBotRegla.php`
- Create: `backend/database/seeders/WhatsAppBotReglasSeeder.php`
- Test: `backend/tests/Feature/WhatsAppBotMigracionesTest.php`

**Interfaces:**
- Produces: columnas `whatsapp_cuentas.bot_activo` (bool), `whatsapp_chats.interes_score` (int), `whatsapp_chats.bot_silenciado_hasta` (datetime null); modelo `WhatsAppBotRegla` con fillable `[cuenta_id, nombre, tipo, es_bienvenida, palabras_clave, respuesta, menu_titulo, opciones, prioridad, activa]`, casts `palabras_clave: array`, `opciones: array`, `es_bienvenida: bool`, `activa: bool`; seeder con 4 reglas (bienvenida-menú + planes + equipos + horario).

- [ ] **Step 1: Test que falla**

`backend/tests/Feature/WhatsAppBotMigracionesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotMigracionesTest extends TestCase
{
    use RefreshDatabase;

    public function test_puede_crear_regla_y_campos_de_bot(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada', 'bot_activo' => true]);
        $chat = WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => 'x@s.whatsapp.net', 'no_leidos' => 0, 'interes_score' => 7]);

        $regla = WhatsAppBotRegla::create([
            'nombre' => 'Planes',
            'tipo' => 'texto',
            'palabras_clave' => ['precio', 'planes'],
            'respuesta' => 'Nuestros planes...',
            'prioridad' => 10,
        ]);

        $this->assertTrue($cuenta->fresh()->bot_activo);
        $this->assertSame(7, $chat->fresh()->interes_score);
        $this->assertSame(['precio', 'planes'], $regla->fresh()->palabras_clave);
    }
}
```

Agregar `'bot_activo'` al `$fillable` de `WhatsAppCuenta` y `'interes_score', 'bot_silenciado_hasta'` al de `WhatsAppChat` (con cast `bot_silenciado_hasta: datetime`, `bot_activo: boolean`).

- [ ] **Step 2: Correr y confirmar que falla**

```
cd backend && php artisan test --filter=WhatsAppBotMigracionesTest
```

- [ ] **Step 3: Migraciones**

`2026_07_15_000004_add_bot_a_whatsapp_tablas.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_cuentas', function (Blueprint $table) {
            $table->boolean('bot_activo')->default(false);
        });
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->integer('interes_score')->default(0);
            $table->dateTime('bot_silenciado_hasta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_cuentas', fn (Blueprint $t) => $t->dropColumn('bot_activo'));
        Schema::table('whatsapp_chats', fn (Blueprint $t) => $t->dropColumn(['interes_score', 'bot_silenciado_hasta']));
    }
};
```

`2026_07_15_000005_create_whatsapp_bot_reglas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->nullable()->constrained('whatsapp_cuentas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->enum('tipo', ['texto', 'menu'])->default('texto');
            $table->boolean('es_bienvenida')->default(false);
            $table->json('palabras_clave')->nullable();
            $table->text('respuesta')->nullable();
            $table->string('menu_titulo', 150)->nullable();
            $table->json('opciones')->nullable();
            $table->integer('prioridad')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_reglas');
    }
};
```

- [ ] **Step 4: Modelo**

`backend/app/Models/WhatsAppBotRegla.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotRegla extends Model
{
    protected $table = 'whatsapp_bot_reglas';

    protected $fillable = [
        'cuenta_id', 'nombre', 'tipo', 'es_bienvenida', 'palabras_clave',
        'respuesta', 'menu_titulo', 'opciones', 'prioridad', 'activa',
    ];

    protected $casts = [
        'palabras_clave' => 'array',
        'opciones' => 'array',
        'es_bienvenida' => 'boolean',
        'activa' => 'boolean',
    ];
}
```

- [ ] **Step 5: Seeder**

`backend/database/seeders/WhatsAppBotReglasSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\WhatsAppBotRegla;
use Illuminate\Database\Seeder;

class WhatsAppBotReglasSeeder extends Seeder
{
    public function run(): void
    {
        if (WhatsAppBotRegla::query()->exists()) {
            return; // idempotente
        }

        $planes = WhatsAppBotRegla::create([
            'nombre' => 'Planes', 'tipo' => 'texto', 'prioridad' => 10,
            'palabras_clave' => ['plan', 'planes', 'promocion', 'precio', 'cuanto'],
            'respuesta' => "Estos son nuestros planes vigentes 📱:\n\n• Plan S/29.90 — 20GB + llamadas ilimitadas\n• Plan S/39.90 — 40GB + llamadas ilimitadas\n• Plan S/49.90 — GB ilimitados\n\n(Escribe \"asesor\" si quieres que te contacte una persona.)",
        ]);
        $equipos = WhatsAppBotRegla::create([
            'nombre' => 'Equipos', 'tipo' => 'texto', 'prioridad' => 10,
            'palabras_clave' => ['equipo', 'equipos', 'celular', 'telefono', 'stock'],
            'respuesta' => 'Tenemos equipos desde S/199 con plan 📲. Cuéntanos qué modelo buscas y te confirmamos stock y precio.',
        ]);
        WhatsAppBotRegla::create([
            'nombre' => 'Horario y ubicacion', 'tipo' => 'texto', 'prioridad' => 10,
            'palabras_clave' => ['horario', 'direccion', 'donde', 'ubicacion'],
            'respuesta' => 'Nuestro horario de atención es de lunes a sábado, 9:00am a 8:00pm 🕗. Escríbenos y te pasamos la dirección de la tienda más cercana.',
        ]);
        WhatsAppBotRegla::create([
            'nombre' => 'Bienvenida', 'tipo' => 'menu', 'es_bienvenida' => true, 'prioridad' => 100,
            'menu_titulo' => '¡Hola! 👋 Gracias por escribir. ¿En qué te ayudamos?',
            'opciones' => [
                ['id' => 'op_planes', 'texto' => 'Planes y promociones', 'regla_id' => $planes->id],
                ['id' => 'op_equipos', 'texto' => 'Equipos disponibles', 'regla_id' => $equipos->id],
                ['id' => 'op_asesor', 'texto' => 'Hablar con un asesor', 'regla_id' => null],
            ],
        ]);
    }
}
```

- [ ] **Step 6: Correr migración + test y commit**

```bash
cd backend && php artisan migrate && php artisan test --filter=WhatsAppBotMigracionesTest
git add backend/database/migrations/2026_07_15_000004_add_bot_a_whatsapp_tablas.php backend/database/migrations/2026_07_15_000005_create_whatsapp_bot_reglas_table.php backend/app/Models/WhatsAppBotRegla.php backend/app/Models/WhatsAppCuenta.php backend/app/Models/WhatsAppChat.php backend/database/seeders/WhatsAppBotReglasSeeder.php backend/tests/Feature/WhatsAppBotMigracionesTest.php
git commit -m "feat(whatsapp): modelo de datos del bot (reglas, flags) + seeder"
```

---

### Task 2: Provider — presencia y listas

**Files:**
- Modify: `backend/app/Services/WhatsApp/WhatsAppProvider.php`
- Modify: `backend/app/Services/WhatsApp/EvolutionProvider.php`
- Test: `backend/tests/Unit/EvolutionProviderTest.php` (agregar casos)

**Interfaces:**
- Produces: `enviarPresencia(string $instancia, string $jid, int $delayMs): void`; `enviarLista(string $instancia, string $jid, string $titulo, array $opciones): array` (opciones `[['id'=>..,'texto'=>..], ...]`; `[]` en fallo para fallback).

- [ ] **Step 1: Tests que fallan** (agregar a `EvolutionProviderTest`)

```php
    public function test_enviar_presencia_llama_al_endpoint(): void
    {
        Http::fake(['*/chat/sendPresence/mi-instancia' => Http::response([], 200)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        (new EvolutionProvider())->enviarPresencia('mi-instancia', '51999@s.whatsapp.net', 5000);

        Http::assertSent(fn ($req) => str_contains($req->url(), '/chat/sendPresence/mi-instancia')
            && $req['presence'] === 'composing' && $req['delay'] === 5000);
    }

    public function test_enviar_lista_devuelve_vacio_en_fallo(): void
    {
        Http::fake(['*/message/sendList/*' => Http::response([], 500)]);
        config(['services.evolution.base_url' => 'https://evolution.example.com', 'services.evolution.api_key' => 'secreto']);

        $r = (new EvolutionProvider())->enviarLista('mi-instancia', '51999@s.whatsapp.net', 'Menu', [['id' => 'op_1', 'texto' => 'Uno']]);

        $this->assertSame([], $r);
    }
```

- [ ] **Step 2: Correr (FAIL), implementar, correr (PASS)**

Interface: agregar las dos firmas. `EvolutionProvider`:

```php
    public function enviarPresencia(string $nombreInstancia, string $jid, int $delayMs): void
    {
        $this->http()->post("/chat/sendPresence/{$nombreInstancia}", [
            'number' => $jid,
            'presence' => 'composing',
            'delay' => $delayMs,
        ]);
    }

    public function enviarLista(string $nombreInstancia, string $jid, string $titulo, array $opciones): array
    {
        $filas = array_map(fn ($o) => ['title' => $o['texto'], 'rowId' => $o['id']], $opciones);
        $response = $this->http()->post("/message/sendList/{$nombreInstancia}", [
            'number' => $jid,
            'title' => $titulo,
            'description' => 'Elige una opción',
            'buttonText' => 'Ver opciones',
            'sections' => [['title' => 'Opciones', 'rows' => $filas]],
        ]);

        if ($response->failed()) {
            Log::warning('evolution.send_list_fallo', ['instancia' => $nombreInstancia, 'status' => $response->status()]);
            return [];
        }

        return $response->json() ?? [];
    }
```

- [ ] **Step 3: Commit**

```bash
git add backend/app/Services/WhatsApp/WhatsAppProvider.php backend/app/Services/WhatsApp/EvolutionProvider.php backend/tests/Unit/EvolutionProviderTest.php
git commit -m "feat(whatsapp): presencia y sendList en EvolutionProvider"
```

---

### Task 3: Servicio `BotResponder` (matching + scoring puros)

**Files:**
- Create: `backend/app/Services/WhatsApp/BotResponder.php`
- Test: `backend/tests/Unit/BotResponderTest.php`

**Interfaces:**
- Produces: `BotResponder::puntuarInteres(string $texto): int` (estático); `BotResponder::decidir(WhatsAppChat $chat, string $texto, ?string $opcionId, bool $esPrimerMensaje): WhatsAppBotRegla|string|null` — devuelve la regla, la cadena `'op_asesor'`, o `null`. Constante `BotResponder::TEXTO_ASESOR = 'Listo, un asesor te escribe en breve 👋'`. `BotResponder::normalizar(string): string` (minúsculas sin tildes).

- [ ] **Step 1: Tests que fallan**

`backend/tests/Unit/BotResponderTest.php` (usa `RefreshDatabase` porque `decidir` consulta reglas):

```php
<?php

namespace Tests\Unit;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Services\WhatsApp\BotResponder;
use Database\Seeders\WhatsAppBotReglasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotResponderTest extends TestCase
{
    use RefreshDatabase;

    private function chat(): WhatsAppChat
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada', 'bot_activo' => true]);
        return WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => 'x@s.whatsapp.net', 'no_leidos' => 0]);
    }

    public function test_puntuar_interes_suma_keywords(): void
    {
        $this->assertGreaterThanOrEqual(5, BotResponder::puntuarInteres('Quiero saber el precio del plan'));
        $this->assertSame(0, BotResponder::puntuarInteres('hola buenas tardes'));
    }

    public function test_primer_mensaje_dispara_bienvenida(): void
    {
        $this->seed(WhatsAppBotReglasSeeder::class);
        $regla = BotResponder::decidir($this->chat(), 'hola', null, true);
        $this->assertInstanceOf(WhatsAppBotRegla::class, $regla);
        $this->assertTrue($regla->es_bienvenida);
    }

    public function test_keywords_matchean_con_tildes_normalizadas(): void
    {
        $this->seed(WhatsAppBotReglasSeeder::class);
        $regla = BotResponder::decidir($this->chat(), '¿Cuánto cuesta la promoción?', null, false);
        $this->assertSame('Planes', $regla->nombre);
    }

    public function test_opcion_de_menu_resuelve_por_id(): void
    {
        $this->seed(WhatsAppBotReglasSeeder::class);
        $regla = BotResponder::decidir($this->chat(), '', 'op_equipos', false);
        $this->assertSame('Equipos', $regla->nombre);
    }

    public function test_opcion_asesor_devuelve_sentinela(): void
    {
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->assertSame('op_asesor', BotResponder::decidir($this->chat(), '', 'op_asesor', false));
    }

    public function test_sin_match_devuelve_null(): void
    {
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->assertNull(BotResponder::decidir($this->chat(), 'xyzabc sin sentido', null, false));
    }
}
```

- [ ] **Step 2: Correr (FAIL), implementar**

`backend/app/Services/WhatsApp/BotResponder.php`:

```php
<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppMensaje;

class BotResponder
{
    public const TEXTO_ASESOR = 'Listo, un asesor te escribe en breve 👋';

    private const KEYWORDS_INTERES = [
        3 => ['portabilidad', 'cambiarme', 'quiero', 'me interesa', 'deseo'],
        2 => ['precio', 'cuanto', 'costo', 'plan', 'planes', 'promocion', 'donde', 'direccion', 'horario'],
    ];

    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        return strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    public static function puntuarInteres(string $texto): int
    {
        $t = self::normalizar($texto);
        $score = 0;
        foreach (self::KEYWORDS_INTERES as $puntos => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($t, $p)) {
                    $score += $puntos;
                }
            }
        }
        return $score;
    }

    /** @return WhatsAppBotRegla|string|null la regla, 'op_asesor', o null */
    public static function decidir(WhatsAppChat $chat, string $texto, ?string $opcionId, bool $esPrimerMensaje): WhatsAppBotRegla|string|null
    {
        $reglasVisibles = fn () => WhatsAppBotRegla::where('activa', true)
            ->where(fn ($q) => $q->where('cuenta_id', $chat->cuenta_id)->orWhereNull('cuenta_id'))
            ->orderByRaw('cuenta_id IS NULL ASC')
            ->orderByDesc('prioridad');

        // (a) Opcion de lista/boton por id.
        if ($opcionId !== null && $opcionId !== '') {
            if ($opcionId === 'op_asesor') {
                return 'op_asesor';
            }
            foreach ($reglasVisibles()->where('tipo', 'menu')->get() as $menu) {
                foreach ($menu->opciones ?? [] as $op) {
                    if (($op['id'] ?? null) === $opcionId && !empty($op['regla_id'])) {
                        return WhatsAppBotRegla::where('activa', true)->find($op['regla_id']);
                    }
                }
            }
            return null;
        }

        $t = self::normalizar($texto);

        // (b) Numero N respondiendo al ultimo menu del bot.
        if (preg_match('/^[1-9]$/', $t)) {
            $ultimoBot = WhatsAppMensaje::where('chat_id', $chat->id)
                ->where('direccion', 'out')->whereNull('enviado_por')
                ->orderByDesc('id')->value('contenido');
            if ($ultimoBot && str_contains($ultimoBot, '1.')) {
                $menu = $reglasVisibles()->where('tipo', 'menu')->first();
                $opciones = $menu->opciones ?? [];
                $idx = ((int) $t) - 1;
                if (isset($opciones[$idx])) {
                    return self::decidir($chat, '', $opciones[$idx]['id'], false);
                }
            }
        }

        // (c) Primer mensaje -> bienvenida.
        if ($esPrimerMensaje) {
            $regla = $reglasVisibles()->where('es_bienvenida', true)->first();
            if ($regla) {
                return $regla;
            }
        }

        // (d) Keywords por prioridad.
        foreach ($reglasVisibles()->where('es_bienvenida', false)->whereNotNull('palabras_clave')->get() as $regla) {
            foreach ($regla->palabras_clave ?? [] as $p) {
                if (str_contains($t, self::normalizar($p))) {
                    return $regla;
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 3: Correr (PASS) y commit**

```bash
cd backend && php artisan test --filter=BotResponderTest
git add backend/app/Services/WhatsApp/BotResponder.php backend/tests/Unit/BotResponderTest.php
git commit -m "feat(whatsapp): servicio BotResponder (matching de reglas + scoring de interes)"
```

---

### Task 4: Job `ResponderBotWhatsApp` + integración en el webhook

**Files:**
- Create: `backend/app/Jobs/ResponderBotWhatsApp.php`
- Modify: `backend/app/Http/Controllers/Api/WhatsAppWebhookController.php`
- Test: `backend/tests/Feature/WhatsAppBotWebhookTest.php`

**Interfaces:**
- Consumes: `BotResponder` (Task 3), `EvolutionProvider::enviarPresencia/enviarLista/enviarTexto` (Task 2), `WhatsAppProviderFactory`.
- Produces: `ResponderBotWhatsApp::dispatch(int $chatId, ?int $reglaId, bool $esAsesor)` con delay; el webhook actualiza `interes_score`, mueve leads a `INTERESADO` y despacha el job.

- [ ] **Step 1: Tests que fallan**

`backend/tests/Feature/WhatsAppBotWebhookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Jobs\ResponderBotWhatsApp;
use App\Models\Cliente;
use App\Models\Lead;
use App\Models\Usuario;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppCuenta;
use App\Models\WhatsAppMensaje;
use Database\Seeders\WhatsAppBotReglasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppBotWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(array $overrides = []): array
    {
        return array_replace_recursive([
            'instance' => 'inst-bot',
            'data' => [
                'key' => ['remoteJid' => '51999000111@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG1'],
                'pushName' => 'Cliente Prueba',
                'message' => ['conversation' => 'hola'],
                'messageTimestamp' => 1700000000,
            ],
        ], $overrides);
    }

    private function cuenta(bool $botActivo = true): WhatsAppCuenta
    {
        config(['services.evolution.webhook_token' => 'tok']);
        return WhatsAppCuenta::create(['nombre' => 'Bot', 'numero' => '1', 'instancia' => 'inst-bot', 'tienda_id' => 'T01', 'estado' => 'conectada', 'bot_activo' => $botActivo]);
    }

    public function test_primer_mensaje_despacha_job_del_bot(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->cuenta();

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertPushed(ResponderBotWhatsApp::class, 1);
    }

    public function test_bot_apagado_no_despacha(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $this->cuenta(false);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_humano_reciente_silencia_al_bot(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $cuenta = $this->cuenta();
        $chat = WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => '51999000111@s.whatsapp.net', 'no_leidos' => 0]);
        $humano = Usuario::factory()->create();
        WhatsAppMensaje::create(['chat_id' => $chat->id, 'direccion' => 'out', 'tipo' => 'texto', 'contenido' => 'hola soy humano', 'enviado_por' => $humano->id, 'timestamp' => now()]);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook())->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_cruce_de_umbral_mueve_lead_a_interesado(): void
    {
        Queue::fake();
        $this->seed(WhatsAppBotReglasSeeder::class);
        $cuenta = $this->cuenta();
        $cliente = Cliente::create(['dni_ruc' => '12345678', 'nombre' => 'Joan', 'telefono' => '999000111', 'tipo_documento' => 'DNI']);
        $lead = Lead::create(['cliente_id' => $cliente->id, 'agente_id' => Usuario::factory()->create()->id, 'tienda_id' => 'T01', 'estado' => 'NUEVO', 'fuente' => 'WHATSAPP']);
        WhatsAppChat::create(['cuenta_id' => $cuenta->id, 'jid' => '51999000111@s.whatsapp.net', 'crm_cliente_id' => $cliente->id, 'no_leidos' => 0]);

        $this->postJson('/api/v1/whatsapp/webhook?token=tok', $this->webhook([
            'data' => ['message' => ['conversation' => 'quiero portabilidad, cuanto cuesta el plan']],
        ]))->assertOk();

        $this->assertSame('INTERESADO', $lead->fresh()->estado);
    }
}
```

Nota: ajustar los campos de `Cliente::create`/`Lead::create` a los `$fillable` reales de esos modelos si difieren (revisarlos antes de correr; el patrón de asserts no cambia).

- [ ] **Step 2: Correr (FAIL), implementar el job**

`backend/app/Jobs/ResponderBotWhatsApp.php`:

```php
<?php

namespace App\Jobs;

use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppChat;
use App\Models\WhatsAppMensaje;
use App\Services\WhatsApp\BotResponder;
use App\Services\WhatsApp\WhatsAppProviderFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class ResponderBotWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public int $chatId,
        public ?int $reglaId,
        public bool $esAsesor = false,
    ) {}

    public function handle(): void
    {
        $chat = WhatsAppChat::with('cuenta')->find($this->chatId);
        if (!$chat || !$chat->cuenta) return;

        // Re-verificaciones al momento de ejecutar.
        if (!$chat->cuenta->bot_activo) return $this->descartar('bot_apagado');
        if (!$this->esAsesor && $chat->bot_silenciado_hasta && $chat->bot_silenciado_hasta->isFuture()) return $this->descartar('silenciado');

        $humanoReciente = WhatsAppMensaje::where('chat_id', $chat->id)
            ->where('direccion', 'out')->whereNotNull('enviado_por')
            ->where('timestamp', '>=', now()->subHours(4))->exists();
        if ($humanoReciente) return $this->descartar('humano_respondio');

        // Limites: 1/chat/minuto, 20/cuenta/hora.
        $porChat = WhatsAppMensaje::where('chat_id', $chat->id)
            ->where('direccion', 'out')->whereNull('enviado_por')
            ->where('timestamp', '>=', now()->subMinute())->count();
        if ($porChat >= 1) return $this->descartar('limite_chat');

        $porCuenta = WhatsAppMensaje::whereIn('chat_id', WhatsAppChat::where('cuenta_id', $chat->cuenta_id)->pluck('id'))
            ->where('direccion', 'out')->whereNull('enviado_por')
            ->where('timestamp', '>=', now()->subHour())->count();
        if ($porCuenta >= 20) return $this->descartar('limite_cuenta');

        // Resolver contenido.
        if ($this->esAsesor) {
            $tipo = 'texto';
            $contenido = BotResponder::TEXTO_ASESOR;
            $menuTitulo = '';
            $opciones = [];
        } else {
            $regla = WhatsAppBotRegla::where('activa', true)->find($this->reglaId);
            if (!$regla) return $this->descartar('regla_inexistente');
            $tipo = $regla->tipo;
            $contenido = (string) ($regla->respuesta ?? '');
            $menuTitulo = (string) ($regla->menu_titulo ?? '');
            $opciones = $regla->opciones ?? [];
        }

        $provider = WhatsAppProviderFactory::make($chat->cuenta->provider);

        // Presencia proporcional al largo (3-8s).
        $largo = $tipo === 'menu' ? mb_strlen($menuTitulo) + 60 : mb_strlen($contenido);
        $delayMs = max(3000, min(8000, $largo * 60));
        $provider->enviarPresencia($chat->cuenta->instancia, $chat->jid, $delayMs);
        usleep($delayMs * 1000);

        // Enviar (lista nativa con fallback a texto numerado).
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

        WhatsAppMensaje::create([
            'chat_id' => $chat->id,
            'direccion' => 'out',
            'tipo' => 'texto',
            'contenido' => $textoRegistrado,
            'enviado_por' => null,
            'timestamp' => now(),
        ]);
        $chat->update(['ultimo_mensaje_at' => now()]);
    }

    private function descartar(string $motivo): void
    {
        Log::info('whatsapp.bot_descartado', ['chat_id' => $this->chatId, 'motivo' => $motivo]);
    }
}
```

Nota: revisar el nombre real del campo timestamp en `WhatsAppMensaje` (el modelo usa `timestamp` según `frontend/src/types/whatsapp.ts`; confirmar contra la migración y usar el nombre real en TODO el job y los tests).

- [ ] **Step 3: Integrar en el webhook**

En `WhatsAppWebhookController::recibir`, tras crear el `WhatsAppMensaje` entrante y actualizar el chat (lógica actual intacta), agregar:

```php
        // ── F5: deteccion de interes + bot ────────────────────────────────
        $texto = (string) ($contenido ?? '');
        $opcionId = $data['message']['listResponseMessage']['singleSelectReply']['selectedRowId']
            ?? $data['message']['buttonsResponseMessage']['selectedButtonId']
            ?? null;

        $puntos = BotResponder::puntuarInteres($texto);
        if ($puntos > 0) {
            $scoreAntes = (int) $chat->interes_score;
            $chat->increment('interes_score', $puntos);
            if ($scoreAntes < 5 && ($scoreAntes + $puntos) >= 5 && $chat->crm_cliente_id) {
                Lead::where('cliente_id', $chat->crm_cliente_id)
                    ->whereIn('estado', ['NUEVO', 'CONTACTADO'])
                    ->update(['estado' => 'INTERESADO']);
                InteraccionCrm::create([
                    'cliente_id' => $chat->crm_cliente_id,
                    'agente_id' => null,
                    'tipo' => 'WHATSAPP',
                    'detalle' => 'Interés detectado por bot',
                    'fecha' => now(),
                ]);
            }
        }

        $chat->refresh();
        if ($cuenta->bot_activo
            && (!$chat->bot_silenciado_hasta || $chat->bot_silenciado_hasta->isPast())) {

            $humanoReciente = WhatsAppMensaje::where('chat_id', $chat->id)
                ->where('direccion', 'out')->whereNotNull('enviado_por')
                ->where('timestamp', '>=', now()->subHours(4))->exists();

            if (!$humanoReciente) {
                $esPrimerMensaje = WhatsAppMensaje::where('chat_id', $chat->id)->where('direccion', 'in')->count() === 1;
                $decision = BotResponder::decidir($chat, $texto, $opcionId, $esPrimerMensaje);

                if ($decision === 'op_asesor') {
                    $chat->update(['bot_silenciado_hasta' => now()->addDay(), 'interes_score' => $chat->interes_score + 3]);
                    ResponderBotWhatsApp::dispatch($chat->id, null, true)->delay(now()->addSeconds(rand(25, 90)));
                } elseif ($decision instanceof WhatsAppBotRegla) {
                    ResponderBotWhatsApp::dispatch($chat->id, $decision->id, false)->delay(now()->addSeconds(rand(25, 90)));
                }
            }
        }
```

Agregar los `use` correspondientes (`ResponderBotWhatsApp`, `BotResponder`, `WhatsAppBotRegla`, `Lead`, `InteraccionCrm`). Ajustar nombres de variables a los reales del controller (`$chat`, `$cuenta`, `$contenido` — revisar cómo se llaman en el método actual y adaptar). Revisar `$fillable`/campos reales de `InteraccionCrm` antes de usar (`agente_id` nullable, campo de fecha).

- [ ] **Step 4: Correr los tests (PASS) + suite completa y commit**

```bash
cd backend && php artisan test --filter=WhatsAppBotWebhookTest && php artisan test --filter=WhatsApp
git add backend/app/Jobs/ResponderBotWhatsApp.php backend/app/Http/Controllers/Api/WhatsAppWebhookController.php backend/tests/Feature/WhatsAppBotWebhookTest.php
git commit -m "feat(whatsapp): webhook puntua interes, mueve leads y despacha el bot"
```

---

### Task 5: Endpoints admin (toggle bot + CRUD reglas)

**Files:**
- Modify: `backend/app/Http/Controllers/Api/WhatsAppController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/WhatsAppBotAdminTest.php`

**Interfaces:**
- Produces: `PATCH /v1/whatsapp/cuentas/{id}/bot` `{bot_activo}` → `{ok:true}`; `GET/POST /v1/whatsapp/bot-reglas`, `PUT/DELETE /v1/whatsapp/bot-reglas/{id}`. Todos solo-admin (403 para gerente/jefe_tienda).

- [ ] **Step 1: Tests que fallan**

```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\WhatsAppBotRegla;
use App\Models\WhatsAppCuenta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBotAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_activar_bot(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/whatsapp/cuentas/{$cuenta->id}/bot", ['bot_activo' => true])
            ->assertOk();

        $this->assertTrue($cuenta->fresh()->bot_activo);
    }

    public function test_no_admin_recibe_403_en_toggle_y_crud(): void
    {
        $cuenta = WhatsAppCuenta::create(['nombre' => 'A', 'numero' => '1', 'instancia' => 'a', 'tienda_id' => 'T01', 'estado' => 'conectada']);
        $jefe = Usuario::factory()->create(['rol' => 'jefe_tienda', 'tienda_id' => 'T01']);

        $this->actingAs($jefe, 'sanctum')->patchJson("/api/v1/whatsapp/cuentas/{$cuenta->id}/bot", ['bot_activo' => true])->assertStatus(403);
        $this->actingAs($jefe, 'sanctum')->postJson('/api/v1/whatsapp/bot-reglas', ['nombre' => 'X', 'tipo' => 'texto', 'respuesta' => 'y'])->assertStatus(403);
    }

    public function test_admin_crud_de_reglas(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'administrador']);

        $crear = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/whatsapp/bot-reglas', [
            'nombre' => 'Regla X', 'tipo' => 'texto', 'palabras_clave' => ['hola'], 'respuesta' => 'Hola!', 'prioridad' => 5, 'activa' => true,
        ]);
        $crear->assertOk();
        $id = $crear->json('id');

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/whatsapp/bot-reglas/{$id}", [
            'nombre' => 'Regla X2', 'tipo' => 'texto', 'palabras_clave' => ['hola'], 'respuesta' => 'Hola!!', 'prioridad' => 5, 'activa' => false,
        ])->assertOk();
        $this->assertSame('Regla X2', WhatsAppBotRegla::find($id)->nombre);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/whatsapp/bot-reglas')->assertOk()->assertJsonCount(1);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/whatsapp/bot-reglas/{$id}")->assertOk();
        $this->assertNull(WhatsAppBotRegla::find($id));
    }
}
```

- [ ] **Step 2: Rutas** (dentro del grupo whatsapp existente)

```php
        Route::patch('cuentas/{id}/bot', [WhatsAppController::class, 'toggleBot']);
        Route::get('bot-reglas', [WhatsAppController::class, 'botReglas']);
        Route::post('bot-reglas', [WhatsAppController::class, 'crearBotRegla']);
        Route::put('bot-reglas/{id}', [WhatsAppController::class, 'actualizarBotRegla']);
        Route::delete('bot-reglas/{id}', [WhatsAppController::class, 'eliminarBotRegla']);
```

- [ ] **Step 3: Métodos en el controller**

```php
    public function toggleBot(Request $request, int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        $data = $request->validate(['bot_activo' => ['required', 'boolean']]);
        WhatsAppCuenta::findOrFail($id)->update(['bot_activo' => $data['bot_activo']]);

        return response()->json(['ok' => true]);
    }

    public function botReglas(): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        return response()->json(WhatsAppBotRegla::orderByDesc('prioridad')->orderBy('id')->get());
    }

    private function validarBotRegla(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', 'in:texto,menu'],
            'es_bienvenida' => ['sometimes', 'boolean'],
            'palabras_clave' => ['nullable', 'array'],
            'respuesta' => ['nullable', 'string'],
            'menu_titulo' => ['nullable', 'string', 'max:150'],
            'opciones' => ['nullable', 'array'],
            'prioridad' => ['sometimes', 'integer'],
            'activa' => ['sometimes', 'boolean'],
            'cuenta_id' => ['nullable', 'integer', 'exists:whatsapp_cuentas,id'],
        ]);
    }

    public function crearBotRegla(Request $request): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        $regla = WhatsAppBotRegla::create($this->validarBotRegla($request));

        return response()->json(['ok' => true, 'id' => $regla->id]);
    }

    public function actualizarBotRegla(Request $request, int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        WhatsAppBotRegla::findOrFail($id)->update($this->validarBotRegla($request));

        return response()->json(['ok' => true]);
    }

    public function eliminarBotRegla(int $id): JsonResponse
    {
        if (!$this->esAdministrador()) {
            return response()->json(['message' => 'Solo administradores.'], 403);
        }
        WhatsAppBotRegla::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
```

Agregar `use App\Models\WhatsAppBotRegla;`.

- [ ] **Step 4: Correr tests (PASS) y commit**

```bash
cd backend && php artisan test --filter=WhatsAppBotAdminTest && php artisan test --filter=WhatsApp
git add backend/app/Http/Controllers/Api/WhatsAppController.php backend/routes/api.php backend/tests/Feature/WhatsAppBotAdminTest.php
git commit -m "feat(whatsapp): endpoints admin de toggle de bot y CRUD de reglas"
```

---

### Task 6: Frontend — tipos, API, hooks

**Files:**
- Modify: `frontend/src/types/whatsapp.ts`
- Modify: `frontend/src/services/whatsapp.api.ts`
- Modify: `frontend/src/hooks/useWhatsApp.ts`

**Interfaces:**
- Produces: tipo `WhatsAppBotRegla`; `WhatsAppCuenta.bot_activo: boolean`; `WhatsAppChat.interes_score: number`; `whatsappApi.bot.{reglas, guardarRegla, eliminarRegla, toggle}`; hooks `useBotReglas`, `useGuardarBotRegla`, `useEliminarBotRegla`, `useToggleBotCuenta`.

- [ ] **Step 1: Tipos**

En `types/whatsapp.ts`: agregar `bot_activo: boolean` a `WhatsAppCuenta`, `interes_score: number` a `WhatsAppChat`, y:

```ts
export interface WhatsAppBotRegla {
  id: number
  cuenta_id: number | null
  nombre: string
  tipo: 'texto' | 'menu'
  es_bienvenida: boolean
  palabras_clave: string[] | null
  respuesta: string | null
  menu_titulo: string | null
  opciones: { id: string; texto: string; regla_id: number | null }[] | null
  prioridad: number
  activa: boolean
}
```

- [ ] **Step 2: API**

En `services/whatsapp.api.ts`, agregar al objeto `whatsappApi`:

```ts
  bot: {
    reglas: (): Promise<WhatsAppBotRegla[]> =>
      api.get('/v1/whatsapp/bot-reglas').then(r => r.data),

    guardarRegla: (data: Partial<WhatsAppBotRegla> & { nombre: string; tipo: 'texto' | 'menu' }): Promise<{ ok: boolean; id?: number }> =>
      data.id
        ? api.put(`/v1/whatsapp/bot-reglas/${data.id}`, data).then(r => r.data)
        : api.post('/v1/whatsapp/bot-reglas', data).then(r => r.data),

    eliminarRegla: (id: number): Promise<void> =>
      api.delete(`/v1/whatsapp/bot-reglas/${id}`).then(r => r.data),

    toggle: (cuentaId: number, botActivo: boolean): Promise<{ ok: boolean }> =>
      api.patch(`/v1/whatsapp/cuentas/${cuentaId}/bot`, { bot_activo: botActivo }).then(r => r.data),
  },
```

(importar `WhatsAppBotRegla` en el archivo).

- [ ] **Step 3: Hooks**

En `hooks/useWhatsApp.ts`:

```ts
export function useBotReglas(habilitado: boolean) {
  return useQuery({
    queryKey: ['whatsapp-bot-reglas'],
    queryFn: () => whatsappApi.bot.reglas(),
    enabled: habilitado,
  })
}

export function useGuardarBotRegla() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Parameters<typeof whatsappApi.bot.guardarRegla>[0]) => whatsappApi.bot.guardarRegla(data),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-bot-reglas'] }),
  })
}

export function useEliminarBotRegla() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => whatsappApi.bot.eliminarRegla(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-bot-reglas'] }),
  })
}

export function useToggleBotCuenta() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ cuentaId, botActivo }: { cuentaId: number; botActivo: boolean }) => whatsappApi.bot.toggle(cuentaId, botActivo),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['whatsapp-cuentas'] }),
  })
}
```

- [ ] **Step 4: `npx tsc -b` limpio y commit**

```bash
cd frontend && npx tsc -b
git add frontend/src/types/whatsapp.ts frontend/src/services/whatsapp.api.ts frontend/src/hooks/useWhatsApp.ts
git commit -m "feat(whatsapp): tipos, API y hooks del bot en el frontend"
```

---

### Task 7: Frontend — UI (toggle 🤖, modal de reglas, badge 🔥)

**Files:**
- Modify: `frontend/src/pages/crm/whatsapp/CuentaSelector.tsx`
- Create: `frontend/src/pages/crm/whatsapp/BotReglasModal.tsx`
- Modify: `frontend/src/pages/crm/whatsapp/ChatList.tsx`
- Modify: `frontend/src/pages/crm/CrmWhatsAppTab.tsx`

**Interfaces:**
- Consumes: hooks de Task 6.
- Produces: `BotReglasModal({ open, onClose }: { open: boolean; onClose: () => void })`; toggle 🤖 por cuenta en `CuentaSelector` (prop nueva no — usa el hook directamente); badge 🔥 en `ChatList` cuando `chat.interes_score >= 5`; entrada "Reglas del bot" en el dropdown (solo admin) que abre el modal desde `CrmWhatsAppTab`.

- [ ] **Step 1: Toggle 🤖 en `CuentaSelector`**

Importar `Robot` de `@phosphor-icons/react` y `useToggleBotCuenta`. Junto al botón de eliminar de cada cuenta (bloque `esAdmin`):

```tsx
              {esAdmin && (
                <button
                  type="button"
                  title={cuenta.bot_activo ? 'Bot activo — clic para apagar' : 'Bot apagado — clic para activar'}
                  onClick={() => toggleBot.mutate({ cuentaId: cuenta.id, botActivo: !cuenta.bot_activo })}
                  className={cuenta.bot_activo ? 'text-kyro-success' : 'text-kyro-muted hover:text-kyro-body'}
                >
                  <Robot size={14} />
                </button>
              )}
```

con `const toggleBot = useToggleBotCuenta()` en el componente. Agregar también, tras el botón "Agregar otro numero", una entrada "Reglas del bot" que llame una prop nueva `onAbrirReglas: () => void` (agregar la prop a la firma; `CrmWhatsAppTab` la provee).

- [ ] **Step 2: `BotReglasModal.tsx`**

```tsx
import { useState } from 'react'
import { PencilSimple, Trash } from '@phosphor-icons/react'
import type { WhatsAppBotRegla } from '../../../types/whatsapp'
import { useBotReglas, useEliminarBotRegla, useGuardarBotRegla } from '../../../hooks/useWhatsApp'
import { Dialog } from '../../../components/ui/dialog'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'

export function BotReglasModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: reglas = [] } = useBotReglas(open)
  const guardar = useGuardarBotRegla()
  const eliminar = useEliminarBotRegla()

  const [editando, setEditando] = useState<Partial<WhatsAppBotRegla> | null>(null)

  const handleGuardar = () => {
    if (!editando?.nombre) return
    guardar.mutate(
      {
        id: editando.id,
        nombre: editando.nombre,
        tipo: (editando.tipo ?? 'texto') as 'texto' | 'menu',
        palabras_clave: editando.palabras_clave ?? [],
        respuesta: editando.respuesta ?? '',
        prioridad: editando.prioridad ?? 10,
        activa: editando.activa ?? true,
      },
      { onSuccess: () => setEditando(null) }
    )
  }

  return (
    <Dialog open={open} onClose={onClose} title="Reglas del bot">
      <div className="space-y-3">
        <div className="flex justify-end">
          <Button variant="gold" size="sm" onClick={() => setEditando({ tipo: 'texto', activa: true })}>+ Nueva regla</Button>
        </div>

        <div className="max-h-72 space-y-1 overflow-y-auto">
          {reglas.map(r => (
            <div key={r.id} className="flex items-center justify-between rounded-kyro border border-kyro-border px-3 py-2 text-sm">
              <div className="min-w-0">
                <span className="font-medium">{r.nombre}</span>
                <span className="ml-2 rounded bg-kyro-indigo/15 px-1.5 text-[10px] text-kyro-indigo">{r.tipo}</span>
                {r.es_bienvenida && <span className="ml-1 rounded bg-kyro-gold/15 px-1.5 text-[10px] text-kyro-gold">bienvenida</span>}
                {!r.activa && <span className="ml-1 rounded bg-kyro-border px-1.5 text-[10px] text-kyro-muted">inactiva</span>}
                <p className="truncate text-xs text-kyro-muted">
                  {r.tipo === 'menu' ? r.menu_titulo : (r.palabras_clave ?? []).join(', ')}
                </p>
              </div>
              <div className="flex shrink-0 gap-2">
                <button type="button" onClick={() => setEditando(r)} className="text-kyro-muted hover:text-kyro-gold"><PencilSimple size={15} /></button>
                <button
                  type="button"
                  onClick={() => { if (confirm(`Eliminar la regla "${r.nombre}"?`)) eliminar.mutate(r.id) }}
                  className="text-kyro-muted hover:text-red-400"
                >
                  <Trash size={15} />
                </button>
              </div>
            </div>
          ))}
          {reglas.length === 0 && <p className="py-6 text-center text-xs text-kyro-muted">Sin reglas todavía.</p>}
        </div>

        {editando && (
          <div className="space-y-2 rounded-kyro border border-kyro-border p-3">
            <Input
              value={editando.nombre ?? ''}
              onChange={e => setEditando({ ...editando, nombre: e.target.value })}
              placeholder="Nombre de la regla"
            />
            <Input
              value={(editando.palabras_clave ?? []).join(', ')}
              onChange={e => setEditando({ ...editando, palabras_clave: e.target.value.split(',').map(s => s.trim()).filter(Boolean) })}
              placeholder="Palabras clave separadas por coma"
            />
            <textarea
              value={editando.respuesta ?? ''}
              onChange={e => setEditando({ ...editando, respuesta: e.target.value })}
              placeholder="Respuesta del bot"
              rows={3}
              className="w-full rounded-kyro border border-kyro-border bg-transparent p-2 text-sm"
            />
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setEditando(null)}>Cancelar</Button>
              <Button variant="gold" size="sm" disabled={!editando.nombre || guardar.isPending} onClick={handleGuardar}>
                {guardar.isPending ? 'Guardando...' : 'Guardar'}
              </Button>
            </div>
          </div>
        )}
      </div>
    </Dialog>
  )
}
```

Nota: revisar las props reales de `Dialog`/`Button` (variantes `gold`/`outline` ya usadas en F3) y ajustar si el tipo `menu` requiere edición del título — en esta fase el form edita reglas `texto`; las reglas `menu` se listan pero sus opciones solo se ajustan por BD (igual que en el proyecto hermano).

- [ ] **Step 3: Badge 🔥 en `ChatList`**

Junto al badge de `no_leidos`:

```tsx
                {chat.interes_score >= 5 && <span title="Cliente interesado">🔥</span>}
```

- [ ] **Step 4: Cablear en `CrmWhatsAppTab`**

Estado `const [reglasAbierto, setReglasAbierto] = useState(false)`; pasar `onAbrirReglas={() => setReglasAbierto(true)}` a `CuentaSelector`; renderizar `<BotReglasModal open={reglasAbierto} onClose={() => setReglasAbierto(false)} />` junto a `ConectarCuentaModal`.

- [ ] **Step 5: `npx tsc -b` limpio, prueba en navegador y commit**

```bash
cd frontend && npx tsc -b
git add frontend/src/pages/crm/whatsapp/CuentaSelector.tsx frontend/src/pages/crm/whatsapp/BotReglasModal.tsx frontend/src/pages/crm/whatsapp/ChatList.tsx frontend/src/pages/crm/CrmWhatsAppTab.tsx
git commit -m "feat(whatsapp): UI del bot (toggle por cuenta, reglas, badge de interes)"
```

---

### Task 8: Producción — seeder, queue worker y prueba end-to-end

**Files:** ninguno nuevo (operación).

- [ ] **Step 1: En producción**: correr las migraciones nuevas + `php artisan db:seed --class=WhatsAppBotReglasSeeder --force` en el contenedor del backend.

- [ ] **Step 2: Queue worker**: verificar si el contenedor ya corre `php artisan queue:work` (hay `QUEUE_CONNECTION=redis`). Si no hay worker, agregarlo al comando de arranque del contenedor (supervisor o proceso adicional en el Dockerfile/compose de Dokploy). Sin worker, los jobs del bot se acumulan en Redis y no se envían.

- [ ] **Step 3: Prueba end-to-end** (igual que el proyecto hermano):
1. Activar 🤖 en la cuenta conectada; 2. "hola" desde otro número → menú en ~1-2 min; 3. responder "1" → planes; 4. "quiero portabilidad cuanto cuesta" → 🔥 + lead a INTERESADO (si el chat está vinculado); 5. responder como humano → el bot se calla 4h; 6. "Hablar con un asesor" → texto fijo + silencio 24h.

---

## Fuera de alcance de este plan

- IA generativa (respuestas por LLM).
- Flujos conversacionales multi-paso (F6).
- Campañas salientes masivas.
- Editor visual de opciones de menú.
