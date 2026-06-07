<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Login ────────────────────────────────────────────────────────────────────

    public function test_login_exitoso_devuelve_token_y_usuario(): void
    {
        Usuario::factory()->create([
            'email'    => 'admin@test.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@test.com',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonStructure([
                'token',
                'usuario' => ['id', 'nombre', 'email', 'rol', 'tienda_id'],
            ]);
    }

    public function test_login_password_incorrecto_devuelve_422(): void
    {
        Usuario::factory()->create([
            'email'    => 'admin@test.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'admin@test.com',
            'password' => 'wrong_password',
        ])->assertStatus(422);
    }

    public function test_login_usuario_no_existe_devuelve_422(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'noexiste@test.com',
            'password' => 'cualquiera',
        ])->assertStatus(422);
    }

    public function test_login_usuario_inactivo_devuelve_403(): void
    {
        Usuario::factory()->inactivo()->create([
            'email'    => 'inactivo@test.com',
            'password' => Hash::make('pass123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email'    => 'inactivo@test.com',
            'password' => 'pass123',
        ])->assertStatus(403);
    }

    public function test_login_sin_email_devuelve_422(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'password' => 'pass123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    // ── Me ───────────────────────────────────────────────────────────────────────

    public function test_me_autenticado_devuelve_datos_del_usuario(): void
    {
        $usuario = Usuario::factory()->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonFragment(['email' => $usuario->email, 'rol' => $usuario->rol]);
    }

    public function test_me_sin_autenticar_devuelve_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    // ── Logout ───────────────────────────────────────────────────────────────────

    public function test_logout_elimina_el_token_de_la_bd(): void
    {
        $usuario = Usuario::factory()->create();
        $usuario->createToken('api');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $token = $usuario->createToken('api-test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonFragment(['message' => 'Sesión cerrada correctamente.']);

        // Solo queda el primer token (el actual fue eliminado)
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
