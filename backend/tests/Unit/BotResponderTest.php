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
        $this->assertSame(0, BotResponder::puntuarInteres('buenas tardes'));
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
