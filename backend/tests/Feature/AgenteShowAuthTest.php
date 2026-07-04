<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el hueco de seguridad de AgenteController::show(): antes cualquier usuario
 * autenticado podia ver la ficha completa (datos personales RRHH) de cualquier agente
 * de cualquier tienda solo cambiando el id en la URL.
 * Mismo criterio que ConstanciaController::agente (commit 9c0b334).
 */
class AgenteShowAuthTest extends TestCase
{
    use RefreshDatabase;

    private function crearAgente(string $tienda = 'PUNDA50'): Agente
    {
        return Agente::create([
            'dni' => '87654321', 'nombres' => 'Agente Ficha', 'estado' => 'ACTIVO',
            'tienda_base' => $tienda, 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);
    }

    public function test_admin_puede_ver_la_ficha_de_cualquier_agente(): void
    {
        $agente = $this->crearAgente('PUNDA50');
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/agentes/{$agente->id}")
            ->assertOk()
            ->assertJsonPath('id', $agente->id);
    }

    public function test_usuario_puede_ver_agente_de_su_propia_tienda(): void
    {
        $agente = $this->crearAgente('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA50')->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/v1/agentes/{$agente->id}")
            ->assertOk()
            ->assertJsonPath('id', $agente->id);
    }

    public function test_usuario_de_otra_tienda_recibe_403(): void
    {
        $agente = $this->crearAgente('PUNDA50');
        $usuario = Usuario::factory()->vendedor('PUNDA11')->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson("/api/v1/agentes/{$agente->id}")
            ->assertForbidden();
    }

    public function test_usuario_sin_tienda_id_falla_cerrado_con_403(): void
    {
        $agente = $this->crearAgente('PUNDA50');
        $usuarioSinTienda = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $this->actingAs($usuarioSinTienda, 'sanctum')
            ->getJson("/api/v1/agentes/{$agente->id}")
            ->assertForbidden();
    }

    /**
     * Hueco null === null: un agente sin tienda_base asignada + un usuario sin
     * tienda_id no deben hacer match. Antes del fix, ambos null hacian que
     * `$agente->tienda_base !== $user->tienda_id` fuera false y se colaba.
     */
    public function test_usuario_sin_tienda_id_y_agente_sin_tienda_base_recibe_403(): void
    {
        $agente = $this->crearAgente('PUNDA50');
        $agente->update(['tienda_base' => null]);

        $usuarioSinTienda = Usuario::factory()->vendedor('PUNDA50')->create(['tienda_id' => null]);

        $this->actingAs($usuarioSinTienda, 'sanctum')
            ->getJson("/api/v1/agentes/{$agente->id}")
            ->assertForbidden();
    }
}
