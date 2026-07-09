<?php

namespace Tests\Feature;

use App\Models\ComprobanteCola;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cubre la rama de la migración que adopta una tabla `comprobantes_cola` ya
 * existente (BD heredada del legacy, creada por `facturacion_cola_migrar()`):
 * añade las columnas que faltan, renombra `proximo_intento` y normaliza los
 * estados y tipos a mayúsculas.
 */
class ComprobanteColaMigracionLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function migracion(): Migration
    {
        return require database_path('migrations/2026_07_08_000002_create_comprobantes_cola_table.php');
    }

    /** Recrea la tabla tal como la dejaba el auto-migrador del legacy. */
    private function crearTablaLegacy(): void
    {
        Schema::dropIfExists('comprobantes_cola');
        Schema::dropIfExists('comprobantes_correlativos');

        DB::statement("
            CREATE TABLE comprobantes_cola (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                ticket_id         INT          NOT NULL DEFAULT 0,
                tienda_id         VARCHAR(20)  NOT NULL DEFAULT '',
                agente_id         INT          NOT NULL DEFAULT 0,
                tipo_comprobante  VARCHAR(20)  NOT NULL,
                tipo_doc_cliente  VARCHAR(1)   NOT NULL DEFAULT '1',
                num_doc_cliente   VARCHAR(20)  NOT NULL DEFAULT '',
                razon_social      VARCHAR(200) NOT NULL DEFAULT '',
                direccion_cliente VARCHAR(255) NOT NULL DEFAULT '',
                email_cliente     VARCHAR(120) NOT NULL DEFAULT '',
                moneda            VARCHAR(3)   NOT NULL DEFAULT 'PEN',
                total             DECIMAL(10,2) NOT NULL DEFAULT 0,
                payload           TEXT         NOT NULL,
                estado            VARCHAR(20)  NOT NULL DEFAULT 'pendiente',
                intentos          INT          NOT NULL DEFAULT 0,
                max_intentos      INT          NOT NULL DEFAULT 8,
                proximo_intento   DATETIME     NULL,
                ultimo_error      TEXT         NULL,
                serie             VARCHAR(10)  NULL,
                correlativo       VARCHAR(20)  NULL,
                api_doc_id        INT          NULL,
                sunat_ticket      VARCHAR(60)  NULL,
                cdr_estado        VARCHAR(20)  NULL,
                cdr_hash          VARCHAR(100) NULL,
                xml_path          VARCHAR(255) NULL,
                pdf_path          VARCHAR(255) NULL,
                creado_en         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    private function insertarFilasLegacy(): void
    {
        $base = [
            'tienda_id' => 'PUNDA50', 'agente_id' => 1001, 'intentos' => 0,
            'serie' => null, 'correlativo' => null, 'api_doc_id' => null, 'proximo_intento' => null,
        ];

        DB::table('comprobantes_cola')->insert([
            ['ticket_id' => 11, 'tipo_comprobante' => 'boleta', 'estado' => 'pendiente',
                'payload' => '{"total":118}', 'total' => 118.00] + $base,

            ['ticket_id' => 12, 'tipo_comprobante' => 'factura', 'estado' => 'aceptado',
                'payload' => '{"total":236}', 'total' => 236.00,
                'serie' => 'F001', 'correlativo' => '00000123', 'api_doc_id' => 77] + $base,

            // `correlativo = ''` es lo que deja el legacy cuando nunca se emitió.
            ['ticket_id' => 13, 'tipo_comprobante' => 'nota_credito', 'estado' => 'error',
                'payload' => '{"total":50}', 'total' => 50.00,
                'intentos' => 3, 'proximo_intento' => '2026-07-08 10:00:00', 'correlativo' => ''] + $base,
        ]);
    }

    public function test_adopta_la_tabla_legacy_completando_columnas_y_normalizando_estados(): void
    {
        $this->crearTablaLegacy();
        $this->insertarFilasLegacy();

        $this->migracion()->up();

        // 1. Columnas nuevas presentes.
        foreach (['venta_id', 'reporte_id', 'facturacion_config_id', 'clave_idempotencia', 'cdr_path'] as $columna) {
            $this->assertTrue(Schema::hasColumn('comprobantes_cola', $columna), "Falta la columna {$columna}");
        }

        // 2. `proximo_intento` → `proximo_intento_at`.
        $this->assertTrue(Schema::hasColumn('comprobantes_cola', 'proximo_intento_at'));
        $this->assertFalse(Schema::hasColumn('comprobantes_cola', 'proximo_intento'));

        // 3. Estados y tipos en mayúsculas.
        $this->assertEqualsCanonicalizing(
            ['PENDIENTE', 'ACEPTADO', 'ERROR'],
            DB::table('comprobantes_cola')->pluck('estado')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['BOLETA', 'FACTURA', 'NOTA_CREDITO'],
            DB::table('comprobantes_cola')->pluck('tipo_comprobante')->all()
        );

        // 4. La tabla de secuencias se creó junto a la cola adoptada.
        $this->assertTrue(Schema::hasTable('comprobantes_correlativos'));

        // 5. Ninguna fila se perdió y el correlativo textual quedó como entero.
        $this->assertSame(3, DB::table('comprobantes_cola')->count());

        $aceptada = ComprobanteCola::query()->where('ticket_id', 12)->first();
        $this->assertSame(123, $aceptada->correlativo);
        $this->assertSame('F001-00000123', $aceptada->numero_completo);
        $this->assertTrue($aceptada->estaAceptado());

        // El correlativo vacío del legacy no se convierte en el número 0.
        $this->assertNull(ComprobanteCola::query()->where('ticket_id', 13)->value('correlativo'));
    }

    public function test_el_backoff_y_el_scope_pendientes_operan_sobre_la_tabla_adoptada(): void
    {
        $this->crearTablaLegacy();
        $this->insertarFilasLegacy();

        $this->migracion()->up();

        // ticket 11 (PENDIENTE) y ticket 13 (ERROR con backoff ya vencido) son candidatas.
        // ticket 12 (ACEPTADO) no lo es.
        $this->assertEqualsCanonicalizing(
            [11, 13],
            ComprobanteCola::pendientes()->pluck('ticket_id')->all()
        );
    }

    public function test_la_migracion_es_idempotente_sobre_la_tabla_legacy(): void
    {
        $this->crearTablaLegacy();
        $this->insertarFilasLegacy();

        $migracion = $this->migracion();
        $migracion->up();
        $migracion->up(); // segunda corrida: no debe fallar ni duplicar índices/filas

        $this->assertSame(3, DB::table('comprobantes_cola')->count());
        $this->assertSame(123, ComprobanteCola::query()->where('ticket_id', 12)->value('correlativo'));
    }

    public function test_sobre_la_tabla_creada_por_la_migracion_no_hace_nada_destructivo(): void
    {
        // Aquí la tabla viene de la propia migración (RefreshDatabase), no del legacy.
        $cola = ComprobanteCola::factory()->create(['ticket_id' => 99, 'serie' => 'B001', 'correlativo' => 5]);

        $this->migracion()->up();

        $this->assertSame(1, DB::table('comprobantes_cola')->count());
        $this->assertSame(5, $cola->fresh()->correlativo);
        $this->assertSame(ComprobanteCola::ESTADO_PENDIENTE, $cola->fresh()->estado);
    }
}
