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
