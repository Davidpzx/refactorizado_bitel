<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SEC-04: las consultas RENIEC (DNI) y SUNAT (RUC) exponen datos de terceros. Deben
 * quedar restringidas por rol (admin/tienda) y limitadas por throttle (30/min) para
 * evitar que cualquier usuario autenticado las use como scraper de datos personales.
 */
class DniRucAuthThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_rol_sin_permiso_recibe_403_en_dni(): void
    {
        $usuario = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/dni/45454545')
            ->assertForbidden();
    }

    public function test_rol_sin_permiso_recibe_403_en_ruc(): void
    {
        $usuario = Usuario::factory()->create(['rol' => 'agente']);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/v1/ruc/20123456789')
            ->assertForbidden();
    }

    public function test_dni_esta_limitado_a_30_por_minuto(): void
    {
        Http::fake([
            'api.apis.net.pe/*' => Http::response([
                'nombres'         => 'Prueba',
                'apellidoPaterno' => 'Throttle',
                'apellidoMaterno' => 'Limite',
            ], 200),
        ]);

        $admin = Usuario::factory()->admin()->create();

        // throttle:30,1 → las primeras 30 pasan.
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/v1/dni/45454545')
                ->assertOk();
        }

        // La request 31 dentro del mismo minuto debe rechazarse con 429.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/dni/45454545')
            ->assertStatus(429);
    }

    public function test_ruc_esta_limitado_a_30_por_minuto(): void
    {
        Http::fake([
            'api.apis.net.pe/*' => Http::response([
                'numeroDocumento' => '20123456789',
                'nombre'          => 'EMPRESA THROTTLE SAC',
                'estado'          => 'ACTIVO',
            ], 200),
        ]);

        $admin = Usuario::factory()->admin()->create();

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($admin, 'sanctum')
                ->getJson('/api/v1/ruc/20123456789')
                ->assertOk();
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/ruc/20123456789')
            ->assertStatus(429);
    }
}
