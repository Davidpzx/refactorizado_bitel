<?php

namespace Tests\Feature;

use App\Models\FacturacionConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cubre la rama de la migración que adopta una tabla `facturacion_config` ya
 * existente (BD heredada del legacy): añade columnas faltantes, migra el
 * `api_token` a TEXT cifrado y normaliza la fila global `tienda_id = ''` a NULL.
 */
class FacturacionConfigMigracionLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_07_08_000001_create_facturacion_config_table.php');
    }

    /** Recrea la tabla tal como la dejaba el auto-migrador del legacy. */
    private function crearTablaLegacy(): void
    {
        Schema::dropIfExists('facturacion_config');

        DB::statement("
            CREATE TABLE facturacion_config (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                tienda_id           VARCHAR(20)  NOT NULL DEFAULT '',
                base_url            VARCHAR(255) NOT NULL DEFAULT '',
                api_token           VARCHAR(255) NOT NULL DEFAULT '',
                ruc                 VARCHAR(20)  NOT NULL DEFAULT '',
                razon_social_emisor VARCHAR(200) NOT NULL DEFAULT '',
                modo                VARCHAR(10)  NOT NULL DEFAULT 'beta',
                serie_boleta        VARCHAR(10)  NOT NULL DEFAULT 'B001',
                serie_factura       VARCHAR(10)  NOT NULL DEFAULT 'F001',
                igv_porcentaje      DECIMAL(5,2) NOT NULL DEFAULT 18.00,
                activo              TINYINT(1)   NOT NULL DEFAULT 0,
                actualizado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function test_adopta_la_tabla_legacy_normalizando_global_y_cifrando_el_token(): void
    {
        $this->crearTablaLegacy();

        DB::table('facturacion_config')->insert([
            // Fila global legacy: tienda_id = '' y token en texto plano.
            ['tienda_id' => '', 'base_url' => 'https://api.legacy.test', 'api_token' => 'token_en_claro_global', 'ruc' => '20100000001', 'activo' => 1],
            ['tienda_id' => 'T01', 'base_url' => 'https://api.legacy.test', 'api_token' => 'token_en_claro_t01', 'ruc' => '20200000002', 'activo' => 1],
        ]);

        $this->migracion()->up();

        // 1. Columnas nuevas presentes con sus defaults del legacy.
        foreach (['company_id', 'branch_id', 'serie_nota_credito', 'emisor_ubigeo', 'emisor_distrito', 'emisor_provincia', 'emisor_departamento', 'creado_en'] as $columna) {
            $this->assertTrue(Schema::hasColumn('facturacion_config', $columna), "Falta la columna {$columna}");
        }

        // 2. La fila global quedó con tienda_id NULL.
        $this->assertSame(0, DB::table('facturacion_config')->where('tienda_id', '')->count());
        $this->assertSame(1, DB::table('facturacion_config')->whereNull('tienda_id')->count());

        // 3. Los tokens quedaron cifrados en reposo y se descifran bien.
        $global = FacturacionConfig::globalConfig();
        $this->assertNotNull($global);
        $this->assertSame('token_en_claro_global', $global->api_token);

        $crudo = DB::table('facturacion_config')->whereNull('tienda_id')->value('api_token');
        $this->assertNotSame('token_en_claro_global', $crudo);
        $this->assertSame('token_en_claro_global', Crypt::decryptString($crudo));

        // 4. La resolución por tienda sigue funcionando sobre la tabla adoptada.
        $this->assertSame('20200000002', FacturacionConfig::paraTienda('T01')->ruc);
        $this->assertSame('token_en_claro_t01', FacturacionConfig::paraTienda('T01')->api_token);
        $this->assertSame('20100000001', FacturacionConfig::paraTienda('T99')->ruc);
    }

    public function test_la_migracion_es_idempotente_y_no_recifra_tokens(): void
    {
        $this->crearTablaLegacy();

        DB::table('facturacion_config')->insert([
            ['tienda_id' => '', 'base_url' => 'https://api.legacy.test', 'api_token' => 'token_en_claro_global', 'ruc' => '20100000001', 'activo' => 1],
        ]);

        $migracion = $this->migracion();
        $migracion->up();

        $cifradoTrasPrimeraCorrida = DB::table('facturacion_config')->value('api_token');

        // Segunda corrida sobre la tabla ya migrada: no debe fallar ni doble-cifrar.
        $migracion->up();

        $this->assertSame(
            'token_en_claro_global',
            Crypt::decryptString(DB::table('facturacion_config')->value('api_token')),
            'Un segundo `up()` no debe volver a cifrar un token ya cifrado.'
        );
        $this->assertSame(
            'token_en_claro_global',
            Crypt::decryptString($cifradoTrasPrimeraCorrida)
        );
        $this->assertSame(1, DB::table('facturacion_config')->count());
    }

    public function test_sobre_tabla_ya_creada_por_la_migracion_no_hace_nada_destructivo(): void
    {
        // Aquí la tabla viene de la propia migración (RefreshDatabase), no del legacy.
        FacturacionConfig::factory()->global()->create(['api_token' => 'tok_nuevo', 'ruc' => '20300000003']);

        $this->migracion()->up();

        $global = FacturacionConfig::globalConfig();
        $this->assertNotNull($global);
        $this->assertSame('tok_nuevo', $global->api_token);
        $this->assertSame('20300000003', $global->ruc);
    }
}
