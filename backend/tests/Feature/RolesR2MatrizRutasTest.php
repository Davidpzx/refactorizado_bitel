<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan 16 — R2: matriz de rutas reetiquetada (routes/api.php) + regla de
 * UsuarioController "el gerente no crea/promueve administradores".
 *
 * Cubre los checks pedidos por el ticket R2: no reproduce toda la matriz (eso
 * es responsabilidad de EnsureRoleRolesTest a nivel de middleware), solo
 * verifica que las etiquetas de rol correctas quedaron en las rutas reales.
 */
class RolesR2MatrizRutasTest extends TestCase
{
    use RefreshDatabase;

    // ── Planilla: admin/gerente sí, jefe_tienda no ──────────────────────────

    public function test_gerente_200_en_planilla(): void
    {
        $gerente = Usuario::factory()->gerente()->create();

        $this->actingAs($gerente, 'sanctum')
            ->getJson('/api/v1/planilla/2026-06')
            ->assertOk();
    }

    public function test_jefe_tienda_403_en_planilla(): void
    {
        $jefeTienda = Usuario::factory()->jefeTienda()->create();

        $this->actingAs($jefeTienda, 'sanctum')
            ->getJson('/api/v1/planilla/2026-06')
            ->assertForbidden();
    }

    // ── Traslados: aprobar (gestionar) es SOLO admin/gerente ────────────────

    public function test_jefe_tienda_403_aprobando_traslado(): void
    {
        $jefeTienda = Usuario::factory()->jefeTienda()->create();

        $this->actingAs($jefeTienda, 'sanctum')
            ->postJson('/api/v1/traslados/1/gestionar', ['action' => 'aprobar'])
            ->assertForbidden();
    }

    // ── Asistencias: photo-action modifica el registro — SOLO admin/gerente ──

    public function test_jefe_tienda_403_en_photo_action(): void
    {
        $jefeTienda = Usuario::factory()->jefeTienda()->create();

        $this->actingAs($jefeTienda, 'sanctum')
            ->postJson('/api/v1/asistencias/1/photo-action', ['action' => 'aprobar'])
            ->assertForbidden();
    }

    // ── Facturación/SUNAT: SOLO administrador (ni el gerente) ───────────────

    public function test_gerente_403_en_configure_sunat(): void
    {
        $gerente = Usuario::factory()->gerente()->create();

        $this->actingAs($gerente, 'sanctum')
            ->postJson('/api/v1/facturacion-config/configure-sunat', [])
            ->assertForbidden();
    }

    public function test_administrador_no_recibe_403_en_configure_sunat(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        // El gate de rol pasa; el 422 de validación (falta el certificado) confirma
        // que llegó al controller y no fue bloqueado por el middleware de rol.
        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/v1/facturacion-config/configure-sunat', [])
            ->assertStatus(422);
    }

    // ── Usuarios: el gerente no crea/promueve administradores ───────────────

    public function test_gerente_403_creando_usuario_administrador(): void
    {
        $gerente = Usuario::factory()->gerente()->create();

        $this->actingAs($gerente, 'sanctum')
            ->postJson('/api/v1/usuarios', [
                'nombre'   => 'Nuevo Admin',
                'email'    => 'nuevo.admin@example.test',
                'password' => 'secret123',
                'rol'      => 'admin',
            ])
            ->assertForbidden();
    }

    public function test_administrador_200_creando_usuario_administrador(): void
    {
        $administrador = Usuario::factory()->administrador()->create();

        $this->actingAs($administrador, 'sanctum')
            ->postJson('/api/v1/usuarios', [
                'nombre'   => 'Nuevo Admin',
                'email'    => 'nuevo.admin2@example.test',
                'password' => 'secret123',
                'rol'      => 'admin',
            ])
            ->assertStatus(201);
    }

    // ── Inventario: el agente de ventas no tiene acceso ─────────────────────

    public function test_agente_ventas_403_en_inventario(): void
    {
        $agente = Usuario::factory()->agenteVentas()->create();

        $this->actingAs($agente, 'sanctum')
            ->getJson('/api/v1/inventario')
            ->assertForbidden();
    }
}
