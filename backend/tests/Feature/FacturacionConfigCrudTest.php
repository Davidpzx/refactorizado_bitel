<?php

namespace Tests\Feature;

use App\Models\FacturacionConfig;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CRUD de `facturacion_config` (TICKET-006). Solo admin, igual que el legacy.
 */
class FacturacionConfigCrudTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/v1/facturacion-config';

    private function admin(): Usuario
    {
        return Usuario::factory()->admin()->create();
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'company_id'          => 1,
            'branch_id'           => 1,
            'base_url'            => 'https://facturacion.example.test',
            'api_token'           => 'tok_de_la_api_externa',
            'ruc'                 => '20123456789',
            'razon_social_emisor' => 'KYRO SAC',
            'modo'                => 'beta',
            'serie_boleta'        => 'B001',
            'serie_factura'       => 'F001',
        ], $extra);
    }

    // ── Permisos: admin sí, tienda no ───────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function rutasProtegidas(): array
    {
        return [
            'listar'    => ['getJson', self::RUTA],
            'ver'       => ['getJson', self::RUTA.'/1'],
            'crear'     => ['postJson', self::RUTA],
            'actualizar' => ['putJson', self::RUTA.'/1'],
            'eliminar'  => ['deleteJson', self::RUTA.'/1'],
        ];
    }

    #[DataProvider('rutasProtegidas')]
    public function test_un_usuario_de_tienda_no_puede_tocar_la_config_de_facturacion(string $metodo, string $ruta): void
    {
        FacturacionConfig::factory()->global()->create();

        $response = $this->actingAs(Usuario::factory()->vendedor('PUNDA50')->create(), 'sanctum')
            ->{$metodo}($ruta, []);

        $response->assertForbidden();
    }

    #[DataProvider('rutasProtegidas')]
    public function test_un_invitado_no_puede_tocar_la_config_de_facturacion(string $metodo, string $ruta): void
    {
        FacturacionConfig::factory()->global()->create();

        $this->{$metodo}($ruta, [])->assertUnauthorized();
    }

    public function test_el_admin_si_puede_listar(): void
    {
        FacturacionConfig::factory()->global()->create();

        $this->actingAs($this->admin(), 'sanctum')->getJson(self::RUTA)->assertOk();
    }

    // ── index / show ────────────────────────────────────────────────────────

    public function test_index_devuelve_la_global_primero_y_luego_las_tiendas(): void
    {
        FacturacionConfig::factory()->deTienda('T02')->create();
        FacturacionConfig::factory()->deTienda('T01')->create();
        FacturacionConfig::factory()->global()->create();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson(self::RUTA);

        $response->assertOk();
        $this->assertSame([null, 'T01', 'T02'], array_column($response->json('data'), 'tienda_id'));
        $this->assertTrue($response->json('data.0.es_global'));
    }

    public function test_el_api_token_nunca_viaja_en_las_respuestas(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['api_token' => 'tok_secreto_xyz']);

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson(self::RUTA.'/'.$config->id);

        $response->assertOk()
            ->assertJsonMissingPath('api_token')
            ->assertJsonPath('tiene_api_token', true);

        $this->assertStringNotContainsString('tok_secreto_xyz', $response->getContent());
    }

    public function test_show_marca_tiene_api_token_en_false_si_no_hay_token(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['api_token' => null]);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson(self::RUTA.'/'.$config->id)
            ->assertOk()
            ->assertJsonPath('tiene_api_token', false)
            ->assertJsonPath('esta_operativa', false);
    }

    // ── store ───────────────────────────────────────────────────────────────

    public function test_crea_la_config_global_y_cifra_el_token(): void
    {
        $response = $this->actingAs($this->admin(), 'sanctum')->postJson(self::RUTA, $this->payload());

        $response->assertCreated()->assertJsonPath('es_global', true);

        $config = FacturacionConfig::globalConfig();
        $this->assertNotNull($config);
        $this->assertSame('tok_de_la_api_externa', $config->api_token);

        $crudo = DB::table('facturacion_config')->where('id', $config->id)->value('api_token');
        $this->assertNotSame('tok_de_la_api_externa', $crudo);
        $this->assertSame('tok_de_la_api_externa', Crypt::decryptString($crudo));
    }

    public function test_crea_la_config_de_una_tienda(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $this->payload(['tienda_id' => 'PUNDA50']))
            ->assertCreated()
            ->assertJsonPath('tienda_id', 'PUNDA50')
            ->assertJsonPath('es_global', false);
    }

    public function test_no_se_puede_crear_una_segunda_config_global(): void
    {
        FacturacionConfig::factory()->global()->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tienda_id');

        $this->assertSame(1, FacturacionConfig::query()->globales()->count());
    }

    public function test_no_se_puede_crear_una_segunda_config_para_la_misma_tienda(): void
    {
        FacturacionConfig::factory()->deTienda('PUNDA50')->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $this->payload(['tienda_id' => 'PUNDA50']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tienda_id');

        $this->assertSame(1, FacturacionConfig::query()->count());
    }

    public function test_una_tienda_id_vacia_se_normaliza_a_global(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $this->payload(['tienda_id' => '   ']))
            ->assertCreated()
            ->assertJsonPath('tienda_id', null)
            ->assertJsonPath('es_global', true);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function payloadsInvalidos(): array
    {
        return [
            'base_url que no es url' => [['base_url' => 'no-es-una-url'], 'base_url'],
            'modo desconocido'       => [['modo' => 'staging'], 'modo'],
            'company_id cero'        => [['company_id' => 0], 'company_id'],
            'igv fuera de rango'     => [['igv_porcentaje' => 150], 'igv_porcentaje'],
            'tienda_id larguisima'   => [['tienda_id' => str_repeat('X', 21)], 'tienda_id'],
        ];
    }

    #[DataProvider('payloadsInvalidos')]
    public function test_rechaza_payloads_invalidos(array $extra, string $campoEsperado): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $this->payload($extra))
            ->assertStatus(422)
            ->assertJsonValidationErrors($campoEsperado);
    }

    public function test_crear_exige_company_id_y_branch_id(): void
    {
        $payload = $this->payload();
        unset($payload['company_id'], $payload['branch_id']);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson(self::RUTA, $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_id', 'branch_id']);
    }

    // ── update ──────────────────────────────────────────────────────────────

    public function test_actualiza_campos_sueltos_sin_tocar_el_resto(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['serie_boleta' => 'B001', 'modo' => 'beta']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/'.$config->id, ['serie_boleta' => 'B002'])
            ->assertOk()
            ->assertJsonPath('serie_boleta', 'B002')
            ->assertJsonPath('modo', 'beta');
    }

    public function test_omitir_api_token_conserva_el_token_existente(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['api_token' => 'tok_original']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/'.$config->id, ['serie_boleta' => 'B009'])
            ->assertOk();

        $this->assertSame('tok_original', $config->fresh()->api_token);
    }

    public function test_enviar_api_token_vacio_borra_el_token(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['api_token' => 'tok_original']);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/'.$config->id, ['api_token' => ''])
            ->assertOk()
            ->assertJsonPath('tiene_api_token', false);

        $this->assertNull($config->fresh()->api_token);
    }

    public function test_no_se_puede_mover_una_tienda_a_un_codigo_ya_ocupado(): void
    {
        FacturacionConfig::factory()->deTienda('T01')->create();
        $otra = FacturacionConfig::factory()->deTienda('T02')->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/'.$otra->id, ['tienda_id' => 'T01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tienda_id');

        $this->assertSame('T02', $otra->fresh()->tienda_id);
    }

    public function test_reenviar_la_misma_tienda_id_no_choca_consigo_misma(): void
    {
        $config = FacturacionConfig::factory()->deTienda('T01')->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/'.$config->id, ['tienda_id' => 'T01', 'serie_boleta' => 'B007'])
            ->assertOk()
            ->assertJsonPath('serie_boleta', 'B007');
    }

    public function test_actualizar_una_config_inexistente_da_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson(self::RUTA.'/9999', ['serie_boleta' => 'B002'])
            ->assertNotFound();
    }

    // ── destroy ─────────────────────────────────────────────────────────────

    public function test_elimina_una_config(): void
    {
        $config = FacturacionConfig::factory()->deTienda('T01')->create();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson(self::RUTA.'/'.$config->id)
            ->assertOk();

        $this->assertDatabaseMissing('facturacion_config', ['id' => $config->id]);
    }
}
