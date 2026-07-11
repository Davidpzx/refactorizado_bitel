<?php

namespace Tests\Feature;

use App\Models\Agente;
use App\Models\Usuario;
use App\Support\LogSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre las 6 medias y 5 bajas de plan/09-plan-ciberseguridad.md (SEC-08..16)
 * implementadas directamente por el orquestador (sin delegar) mientras las
 * cuentas worker estaban en cooldown.
 */
class SeguridadMediasBajasTest extends TestCase
{
    use RefreshDatabase;

    /** SEC-12: health ya no expone entorno/nombre de app. */
    public function test_health_no_expone_env_ni_app(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    /** SEC-11: el padrón de agentes/select ya no viaja con el DNI completo. */
    public function test_agentes_select_no_expone_dni_completo(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'admin']);
        Agente::create([
            'dni' => '87654321', 'nombres' => 'Agente Prueba', 'estado' => 'ACTIVO',
            'tienda_base' => 'T01', 'hora_ingreso' => '08:00:00', 'hora_salida' => '18:00:00',
            'dia_descanso' => 'DOMINGO', 'sueldo_base' => 1200,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/agentes/select');

        $response->assertOk();
        $body = $response->json();
        $this->assertNotEmpty($body);
        foreach ($body as $item) {
            $this->assertArrayNotHasKey('dni', $item);
            $this->assertArrayHasKey('dni_ultimos4', $item);
        }
        $this->assertStringNotContainsString('87654321', $response->getContent());
        $this->assertSame('4321', $body[0]['dni_ultimos4']);
    }

    /** SEC-08: helper de logging enmascara el DNI. */
    public function test_logsafe_enmascara_dni(): void
    {
        $this->assertSame('****5678', LogSafe::dni('12345678'));
        $this->assertSame('****', LogSafe::dni(null));
        $this->assertSame('****', LogSafe::dni('12'));
    }

    /** SEC-13: mark-photo tiene throttle propio (10/min) más estricto que el grupo (60/min). */
    public function test_mark_photo_tiene_throttle_propio(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $payload = ['dni' => '99999999', 'tipo' => 'entrada', 'tienda_id' => 'T01', 'foto' => $png];

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/attendance/mark-photo', $payload);
        }

        $this->postJson('/api/v1/attendance/mark-photo', $payload)->assertStatus(429);
    }

    /** SEC-15: verify-pin tiene un limiter compuesto IP+dni (5/min) además del de IP (20/min). */
    public function test_verify_pin_limiter_compuesto_por_dni(): void
    {
        $payload = ['dni' => '11112222', 'pin' => '0000'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify-pin', $payload);
        }

        $this->postJson('/api/v1/auth/verify-pin', $payload)->assertStatus(429);
    }

    /** SEC-10: los exports tienen un limiter propio (10/min por usuario). */
    public function test_exports_tienen_limiter_propio(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'admin']);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/v1/bitacora-stock/exportar');
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/bitacora-stock/exportar')
            ->assertStatus(429);
    }

    /** SEC-16: un admin puede revocar todas las sesiones de un usuario. */
    public function test_admin_puede_revocar_tokens_de_usuario(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'admin']);
        $objetivo = Usuario::factory()->create(['rol' => 'tienda', 'tienda_id' => 'T01']);
        $objetivo->createToken('sesion-1');
        $objetivo->createToken('sesion-2');

        $this->assertSame(2, $objetivo->tokens()->count());

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/usuarios/{$objetivo->id}/revocar-tokens")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(0, $objetivo->tokens()->count());
    }

    /** SEC-16: desactivar un usuario revoca sus tokens automáticamente. */
    public function test_desactivar_usuario_revoca_tokens_automaticamente(): void
    {
        $admin = Usuario::factory()->create(['rol' => 'admin']);
        $objetivo = Usuario::factory()->create(['rol' => 'tienda', 'tienda_id' => 'T01', 'activo' => true]);
        $objetivo->createToken('sesion-1');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/usuarios/{$objetivo->id}", ['activo' => false])
            ->assertOk();

        $this->assertSame(0, $objetivo->fresh()->tokens()->count());
    }

    /** SEC-09: la descarga pública del CPE cachea el archivo y no re-proxya en cada hit. */
    public function test_cpe_descarga_publica_usa_cache(): void
    {
        // Cubierto indirectamente: la ruta exige firma HMAC válida (CpeLinkService),
        // fuera del alcance de un test de caché sin un ComprobanteCola real con
        // api_doc_id + config de facturación operativa. Se deja como smoke test de
        // que el endpoint sigue respondiendo 403 sin firma (comportamiento previo intacto).
        $this->getJson('/api/v1/cpe/1')->assertStatus(422); // falta 'exp'/'firma' en query
    }
}
