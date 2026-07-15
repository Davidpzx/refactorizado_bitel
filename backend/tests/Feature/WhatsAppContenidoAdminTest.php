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
