<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuarioAdministradorProteccionTest extends TestCase
{
    use RefreshDatabase;

    public function test_gerente_no_puede_cambiar_password_ni_degradar_a_un_administrador(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $administrador = Usuario::factory()->administrador()->create([
            'password' => Hash::make('password-original'),
        ]);

        $this->actingAs($gerente, 'sanctum')
            ->patchJson('/api/v1/usuarios/'.$administrador->id, [
                'password' => 'password-tomado',
                'rol' => Usuario::ROL_GERENTE,
            ])
            ->assertForbidden();

        $administrador->refresh();
        $this->assertTrue($administrador->esAdministrador());
        $this->assertTrue(Hash::check('password-original', $administrador->password));
        $this->assertFalse(Hash::check('password-tomado', $administrador->password));
    }

    public function test_gerente_no_puede_desactivar_a_un_administrador(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($gerente, 'sanctum')
            ->patchJson('/api/v1/usuarios/'.$administrador->id, ['activo' => false])
            ->assertForbidden();

        $this->assertTrue($administrador->fresh()->activo);
    }

    public function test_gerente_no_puede_revocar_sesiones_de_un_administrador(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $administrador = Usuario::factory()->administrador()->create();
        $administrador->createToken('api');

        $this->actingAs($gerente, 'sanctum')
            ->postJson('/api/v1/usuarios/'.$administrador->id.'/revocar-tokens')
            ->assertForbidden();

        $this->assertSame(1, $administrador->tokens()->count());
    }

    public function test_gerente_no_puede_eliminar_a_un_administrador(): void
    {
        $gerente = Usuario::factory()->gerente()->create();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($gerente, 'sanctum')
            ->deleteJson('/api/v1/usuarios/'.$administrador->id)
            ->assertForbidden();

        $this->assertDatabaseHas('usuarios', ['id' => $administrador->id]);
    }

    public function test_administrador_si_puede_gestionar_a_otro_administrador(): void
    {
        $actor = Usuario::factory()->administrador()->create();
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($actor, 'sanctum')
            ->patchJson('/api/v1/usuarios/'.$administrador->id, ['nombre' => 'Admin Protegido'])
            ->assertOk();

        $this->assertSame('Admin Protegido', $administrador->fresh()->nombre);
    }
}
