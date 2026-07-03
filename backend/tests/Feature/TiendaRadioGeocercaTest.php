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
