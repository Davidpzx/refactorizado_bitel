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
