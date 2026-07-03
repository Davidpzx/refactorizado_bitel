<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresion end-to-end para los payloads que las revisiones de Tasks 3 y 4
 * rastrearon a mano (sin poder correr un navegador real): confirma que dejar
 * radio_permitido/direccion/telefono en blanco realmente persiste/valida
 * como se espera contra el backend real, no solo por lectura de codigo.
 */
class TiendaCamposOpcionalesEnBlancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualizar_tienda_sin_enviar_radio_permitido_no_lo_modifica(): void
    {
        $admin = Usuario::factory()->admin()->create();
        $id = DB::table('tiendas')->insertGetId([
            'codigo' => 'TB1', 'nombre' => 'Tienda Blanco 1', 'radio_permitido' => 75,
        ]);

        // Payload exactamente como lo traza el frontend cuando el campo queda en blanco:
        // la key 'radio_permitido' esta completamente ausente (no null).
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/tiendas/{$id}", ['nombre' => 'Tienda Blanco 1 Editada'])
            ->assertOk();

        $this->assertDatabaseHas('tiendas', ['id' => $id, 'radio_permitido' => 75]);
    }

    public function test_crear_tienda_sin_direccion_ni_telefono(): void
    {
        $admin = Usuario::factory()->admin()->create();

        // Payload exactamente como lo traza el frontend cuando ambos campos quedan en
        // blanco: se envian como string vacio (no se omiten ni se mandan null desde el
        // cliente). Laravel 11 aplica ConvertEmptyStringsToNull por defecto en todo el
        // stack de middleware (no hace falta registrarlo, por eso un grep de codigo no
        // lo encuentra) -- el string vacio llega a la validacion ya convertido a null.
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tiendas', [
                'codigo' => 'TB2', 'nombre' => 'Tienda Blanco 2',
                'direccion' => '', 'telefono' => '',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('tiendas', ['codigo' => 'TB2', 'direccion' => null, 'telefono' => null]);
    }

    public function test_crear_tienda_sin_radio_permitido_usa_default_60(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tiendas', ['codigo' => 'TB3', 'nombre' => 'Tienda Blanco 3'])
            ->assertCreated();

        $this->assertDatabaseHas('tiendas', ['codigo' => 'TB3', 'radio_permitido' => 60]);
    }
}
