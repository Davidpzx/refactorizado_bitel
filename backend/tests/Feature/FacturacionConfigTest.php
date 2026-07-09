<?php

namespace Tests\Feature;

use App\Models\FacturacionConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paridad legacy `config/facturacion_config.php`: resolución de la config de
 * facturación por tienda con fallback a la global, y cifrado del token.
 */
class FacturacionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_resuelve_la_config_propia_de_la_tienda(): void
    {
        FacturacionConfig::factory()->global()->create(['ruc' => '20100000001']);
        FacturacionConfig::factory()->deTienda('T01')->create([
            'ruc'        => '20200000002',
            'company_id' => 7,
            'branch_id'  => 3,
        ]);

        $config = FacturacionConfig::paraTienda('T01');

        $this->assertNotNull($config);
        $this->assertSame('T01', $config->tienda_id);
        $this->assertSame('20200000002', $config->ruc);
        $this->assertSame(7, $config->company_id);
        $this->assertSame(3, $config->branch_id);
        $this->assertFalse($config->esGlobal());
    }

    public function test_tienda_sin_config_propia_cae_a_la_global(): void
    {
        FacturacionConfig::factory()->global()->create(['ruc' => '20100000001']);
        FacturacionConfig::factory()->deTienda('T01')->create(['ruc' => '20200000002']);

        $config = FacturacionConfig::paraTienda('T99');

        $this->assertNotNull($config);
        $this->assertNull($config->tienda_id);
        $this->assertSame('20100000001', $config->ruc);
        $this->assertTrue($config->esGlobal());
    }

    public function test_sin_ninguna_config_devuelve_null(): void
    {
        $this->assertNull(FacturacionConfig::paraTienda('T01'));
        $this->assertNull(FacturacionConfig::paraTienda());
        $this->assertNull(FacturacionConfig::globalConfig());
    }

    public function test_paraTienda_sin_argumento_o_vacio_devuelve_la_global(): void
    {
        FacturacionConfig::factory()->global()->create(['ruc' => '20100000001']);
        FacturacionConfig::factory()->deTienda('T01')->create(['ruc' => '20200000002']);

        foreach ([null, '', '   '] as $entrada) {
            $config = FacturacionConfig::paraTienda($entrada);

            $this->assertNotNull($config);
            $this->assertSame('20100000001', $config->ruc, sprintf('Entrada: %s', var_export($entrada, true)));
        }
    }

    public function test_el_api_token_se_guarda_cifrado_en_la_bd(): void
    {
        $tokenEnClaro = 'tok_secreto_de_la_api_externa_123';

        $config = FacturacionConfig::factory()->global()->create(['api_token' => $tokenEnClaro]);

        $crudo = DB::table('facturacion_config')->where('id', $config->id)->value('api_token');

        $this->assertNotSame($tokenEnClaro, $crudo, 'El token no debe persistirse en texto plano.');
        $this->assertStringNotContainsString($tokenEnClaro, (string) $crudo);
        $this->assertSame($tokenEnClaro, Crypt::decryptString($crudo));

        // Y el modelo lo devuelve descifrado al leerlo de nuevo.
        $this->assertSame($tokenEnClaro, $config->fresh()->api_token);
    }

    public function test_el_api_token_no_se_expone_al_serializar(): void
    {
        $config = FacturacionConfig::factory()->global()->create(['api_token' => 'tok_secreto']);

        $this->assertArrayNotHasKey('api_token', $config->toArray());
        $this->assertStringNotContainsString('tok_secreto', $config->toJson());
    }

    public function test_esta_operativa_exige_activo_base_url_y_token(): void
    {
        $ok = FacturacionConfig::factory()->global()->make();
        $this->assertTrue($ok->estaOperativa());

        $this->assertFalse(FacturacionConfig::factory()->global()->inactiva()->make()->estaOperativa());
        $this->assertFalse(FacturacionConfig::factory()->global()->make(['base_url' => ''])->estaOperativa());
        $this->assertFalse(FacturacionConfig::factory()->global()->make(['api_token' => ''])->estaOperativa());
    }

    public function test_una_tienda_no_puede_tener_dos_configuraciones(): void
    {
        FacturacionConfig::factory()->deTienda('T01')->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        FacturacionConfig::factory()->deTienda('T01')->create();
    }

    public function test_expone_los_endpoints_de_la_api_externa(): void
    {
        $endpoints = FacturacionConfig::factory()->global()->make()->endpoints();

        $this->assertSame([
            'boleta'       => '/api/v1/boletas',
            'factura'      => '/api/v1/invoices',
            'nota_credito' => '/api/v1/notas-credito',
        ], $endpoints);
    }

    public function test_el_seeder_crea_exactamente_una_global_y_es_idempotente(): void
    {
        $this->seed(\Database\Seeders\FacturacionConfigSeeder::class);
        $this->seed(\Database\Seeders\FacturacionConfigSeeder::class);

        $this->assertSame(1, FacturacionConfig::query()->globales()->count());

        $global = FacturacionConfig::globalConfig();
        $this->assertNotNull($global);
        // Se siembra inactiva: se activa a mano tras cargar credenciales reales.
        $this->assertFalse($global->activo);
        $this->assertFalse($global->estaOperativa());
    }

    public function test_la_fila_global_legacy_con_tienda_id_vacio_sigue_resolviendo(): void
    {
        // Defensa por si se adopta una BD legacy sin normalizar (tienda_id = '').
        FacturacionConfig::factory()->global()->create()->forceFill(['tienda_id' => ''])->save();

        $config = FacturacionConfig::paraTienda('T99');

        $this->assertNotNull($config);
        $this->assertTrue($config->esGlobal());
    }
}
