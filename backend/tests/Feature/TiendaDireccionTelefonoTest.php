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
