<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DniControllerTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = Usuario::factory()->create();
    }

    public function test_consultar_dni_usa_cache_local_de_crm_clientes_antes_de_llamar_api_externa(): void
    {
        DB::table('crm_clientes')->insert([
            'dni'            => '45454545',
            'nombres'        => 'Juan Carlos',
            'apellidos'      => 'Perez Gomez',
            'telefono'       => '987654321',
            'operadora'      => 'Bitel',
            'fecha_registro' => now(),
        ]);

        Http::fake([
            'api.apis.net.pe/*' => Http::response([], 500),
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/dni/45454545')
            ->assertOk()
            ->assertJson([
                'nombres'          => 'Juan Carlos',
                'apellido_paterno' => 'Perez',
                'apellido_materno' => 'Gomez',
                'numero_documento' => '45454545',
                'fuente'           => 'cache_local',
            ]);

        Http::assertNothingSent();
    }

    public function test_consultar_dni_sin_cache_local_cae_a_api_externa(): void
    {
        Http::fake([
            'api.apis.net.pe/*' => Http::response([
                'nombres'          => 'Maria',
                'apellidoPaterno'  => 'Lopez',
                'apellidoMaterno'  => 'Diaz',
                'numeroDocumento'  => '46464646',
            ], 200),
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/dni/46464646')
            ->assertOk()
            ->assertJson([
                'nombres'          => 'Maria',
                'apellido_paterno' => 'Lopez',
                'apellido_materno' => 'Diaz',
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '46464646'));
    }
}
