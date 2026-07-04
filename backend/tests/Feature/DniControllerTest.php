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

    public function test_consultar_dni_usa_cache_local_verificado_por_reniec_antes_de_llamar_api_externa(): void
    {
        DB::table('crm_clientes')->insert([
            'dni'            => '45454545',
            'nombres'        => 'Juan Carlos',
            'apellidos'      => 'Perez Gomez',
            'fuente_nombre'  => 'RENIEC_API',
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
                'fuente'           => 'RENIEC_API',
            ]);

        Http::assertNothingSent();
    }

    public function test_consultar_dni_cache_local_no_verificado_no_se_reporta_como_reniec(): void
    {
        // Nombre tipeado a mano (RENIEC no respondió cuando se guardó en el CRM) — no debe
        // reportarse como si fuera un dato verificado por RENIEC.
        DB::table('crm_clientes')->insert([
            'dni'            => '45454546',
            'nombres'        => 'Nombre Tipeado',
            'apellidos'      => 'A Mano',
            'fuente_nombre'  => 'MANUAL_CON_FALLBACK',
            'telefono'       => '987654321',
            'operadora'      => 'Bitel',
            'fecha_registro' => now(),
        ]);

        Http::fake([
            'api.apis.net.pe/*' => Http::response([], 500),
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/dni/45454546')
            ->assertOk()
            ->assertJson([
                'nombres' => 'Nombre Tipeado',
                'fuente'  => 'CRM_NO_VERIFICADO',
            ]);

        Http::assertNothingSent();
    }

    public function test_consultar_dni_cache_local_sin_fuente_registrada_se_trata_como_no_verificado(): void
    {
        // Filas sembradas antes de que existiera la columna fuente_nombre (backfill sin
        // interaccion previa que lo determine): por seguridad, se tratan como no verificadas.
        DB::table('crm_clientes')->insert([
            'dni'            => '45454547',
            'nombres'        => 'Sin Fuente',
            'apellidos'      => 'Registrada',
            'telefono'       => '987654321',
            'operadora'      => 'Bitel',
            'fecha_registro' => now(),
        ]);

        $this->actingAs($this->usuario, 'sanctum')
            ->getJson('/api/v1/dni/45454547')
            ->assertOk()
            ->assertJson(['fuente' => 'CRM_NO_VERIFICADO']);
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
                'fuente'           => 'RENIEC_API',
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), '46464646'));
    }
}
