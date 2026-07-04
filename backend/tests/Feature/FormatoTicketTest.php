<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormatoTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_formato_ticket_por_defecto_es_80(): void
    {
        $usuario = Usuario::factory()->admin()->create();

        $this->assertEquals('80', $usuario->refresh()->formato_ticket);
    }

    public function test_admin_actualiza_el_formato_de_ticket_de_un_usuario(): void
    {
        $admin   = Usuario::factory()->admin()->create();
        $usuario = Usuario::factory()->admin()->create(['formato_ticket' => '80']);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/usuarios/{$usuario->id}", ['formato_ticket' => '58'])
            ->assertOk()
            ->assertJsonFragment(['formato_ticket' => '58']);

        $this->assertDatabaseHas('usuarios', ['id' => $usuario->id, 'formato_ticket' => '58']);
    }

    public function test_formato_de_ticket_invalido_devuelve_422(): void
    {
        $admin   = Usuario::factory()->admin()->create();
        $usuario = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/usuarios/{$usuario->id}", ['formato_ticket' => '110'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formato_ticket']);
    }

    public function test_me_expone_el_formato_de_ticket_del_usuario_autenticado(): void
    {
        $usuario = Usuario::factory()->admin()->create(['formato_ticket' => '58']);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['formato_ticket' => '58']);
    }
}
