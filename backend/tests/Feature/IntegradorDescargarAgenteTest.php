<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Descarga del paquete instalador del agente on-premise (Integrador Bitel).
 *
 * Regla clave: un ZIP sin los binarios del agente es un instalador NO funcional;
 * el endpoint debe fallar ruidosamente en vez de repartir un paquete inerte.
 */
class IntegradorDescargarAgenteTest extends TestCase
{
    use RefreshDatabase;

    /** Token de tienda válido (64 hex) usado en las pruebas. */
    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private string $agenteDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agenteDir = storage_path('app/integrador/agente');
        // Partir siempre de un servidor SIN binarios provisionados.
        File::deleteDirectory($this->agenteDir);

        DB::table('integrador_credenciales')->insert([
            'tienda_codigo' => 'PUNDA50',
            'bitel_username' => 'USUARIO_TEST',
            'bitel_password_enc' => 'x', // no se descifra en este endpoint
            'sync_intervalo_min' => 15,
            'agente_token_hash' => hash('sha256', self::TOKEN),
            'activo' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->agenteDir);
        parent::tearDown();
    }

    private function provisionarBinarios(): void
    {
        File::ensureDirectoryExists($this->agenteDir);
        foreach (['agente_bipay.php', 'BitelBipayClient.php', 'ejecutar_agente.bat'] as $f) {
            File::put($this->agenteDir.DIRECTORY_SEPARATOR.$f, "contenido de {$f}");
        }
    }

    private function url(string $tienda = 'PUNDA50', string $token = self::TOKEN): string
    {
        return '/api/v1/integrador/descargar-agente?tienda='.$tienda.'&token='.$token;
    }

    public function test_falla_ruidosamente_si_faltan_los_binarios_del_agente(): void
    {
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get($this->url())
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_descarga_el_zip_cuando_los_binarios_estan_provisionados(): void
    {
        $this->provisionarBinarios();
        $admin = Usuario::factory()->admin()->create();

        $resp = $this->actingAs($admin, 'sanctum')->get($this->url());

        $resp->assertStatus(200);
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
    }

    public function test_token_invalido_es_rechazado(): void
    {
        $this->provisionarBinarios();
        $admin = Usuario::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->get($this->url(token: 'no-es-hex'))
            ->assertStatus(403);
    }

    public function test_token_que_no_corresponde_a_la_tienda_es_rechazado(): void
    {
        $this->provisionarBinarios();
        $admin = Usuario::factory()->admin()->create();

        // Token bien formado (64 hex) pero que no coincide con el hash guardado.
        $this->actingAs($admin, 'sanctum')
            ->get($this->url(token: str_repeat('b', 64)))
            ->assertStatus(403);
    }
}
