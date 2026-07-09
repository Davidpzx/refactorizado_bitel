<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paridad legacy `config/facturacion_cola.php` + `cron/procesar_cola_comprobantes.php`
 * (sistema-rolando-salas).
 *
 * Regla de oro del legacy: el request web SOLO encola — nunca emite, nunca pierde
 * una fila. La emisión real contra la API externa de facturación (DECISIÓN-001: API
 * externa, no Greenter) la hace un proceso aparte que drena esta cola con backoff.
 *
 * Tabla NUEVA, no extensión de `comprobantes`:
 *  - `comprobantes` es la ruta Greenter (xml/cdr/hash_cpe) y sigue viva en
 *    `GreenterService` + `EnviarComprobanteSunat`. Son dos pipelines distintos.
 *  - `comprobantes.venta_id` es NOT NULL; la cola se encola desde venta, reporte o
 *    ticket, y el legacy ni siquiera conoce `ventas`.
 *  - La tabla legacy `comprobantes_cola` existe y habrá que importarla 1:1.
 *
 * Dos diferencias deliberadas respecto del legacy:
 *  1. `estado` y `tipo_comprobante` en MAYÚSCULAS (convención del refactor y del
 *     ticket). Las filas legacy se normalizan más abajo.
 *  2. `proximo_intento` pasa a llamarse `proximo_intento_at`, y `correlativo` pasa
 *     de VARCHAR(20) a entero: el correlativo se genera con lock, es un contador.
 *
 * El correlativo NO se deriva de `MAX(correlativo)+1` sobre esta tabla: vive en la
 * tabla de secuencias `comprobantes_correlativos`, una fila por (emisor, serie), que
 * se bloquea con `lockForUpdate()`. Evita el doble número bajo concurrencia.
 */
return new class extends Migration
{
    /** Estados de la cola. `ENVIADO` es el lock optimista (legacy: `enviando`). */
    private const ESTADOS_LEGACY = [
        'pendiente' => 'PENDIENTE',
        'enviando'  => 'ENVIADO',
        'enviado'   => 'ENVIADO',
        'aceptado'  => 'ACEPTADO',
        'rechazado' => 'RECHAZADO',
        'anulado'   => 'ANULADO',
        'error'     => 'ERROR',
    ];

    private const TIPOS_LEGACY = [
        'boleta'       => 'BOLETA',
        'factura'      => 'FACTURA',
        'nota_credito' => 'NOTA_CREDITO',
    ];

    public function up(): void
    {
        $this->crearTablaSecuencias();

        if (!Schema::hasTable('comprobantes_cola')) {
            $this->crearColaDesdeCero();

            return;
        }

        $this->adaptarColaLegacy();
    }

    /**
     * Una fila por (emisor, serie). `ultimo_correlativo` es el último número
     * entregado. Se bloquea con `SELECT ... FOR UPDATE` al pedir el siguiente.
     */
    private function crearTablaSecuencias(): void
    {
        if (Schema::hasTable('comprobantes_correlativos')) {
            return;
        }

        Schema::create('comprobantes_correlativos', function (Blueprint $table) {
            $table->id();

            // El emisor ES la fila de `facturacion_config` (company_id + branch_id +
            // RUC). Dos tiendas que caen al fallback global comparten secuencia,
            // que es exactamente lo correcto: comparten serie y RUC.
            $table->unsignedInteger('facturacion_config_id');
            $table->string('serie', 10);
            $table->unsignedInteger('ultimo_correlativo')->default(0);

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            $table->unique(['facturacion_config_id', 'serie'], 'uq_correlativo_emisor_serie');
        });
    }

    private function crearColaDesdeCero(): void
    {
        Schema::create('comprobantes_cola', function (Blueprint $table) {
            $table->id();

            // ── Origen. Los tres son opcionales: el legacy encola por `ticket_id`,
            // el refactor por venta o por reporte. Sin FK, igual que el resto del
            // esquema (ver `create_core_tables`).
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->unsignedBigInteger('reporte_id')->nullable();
            $table->unsignedBigInteger('ticket_id')->nullable();

            // Tienda emisora: CÓDIGO (`tiendas.codigo`), como `facturacion_config`.
            $table->string('tienda_id', 20)->nullable();
            $table->unsignedBigInteger('agente_id')->nullable();

            // Emisor resuelto. NULL mientras la fila está encolada: el legacy
            // resuelve la config recién al emitir, y así una config editada después
            // del encolado sigue aplicando.
            $table->unsignedInteger('facturacion_config_id')->nullable();

            // Evita encolar dos veces la misma venta/tipo. NULL = sin garantía de
            // unicidad (los NULL no colisionan en un UNIQUE, en MySQL y en SQLite).
            $table->string('clave_idempotencia', 190)->nullable();

            $table->string('tipo_comprobante', 20);

            // ── Snapshot fiscal congelado. NO se recalcula en los reintentos.
            $table->string('tipo_doc_cliente', 1)->default('1');
            $table->string('num_doc_cliente', 20)->default('');
            $table->string('razon_social', 200)->default('');
            $table->string('direccion_cliente', 255)->default('');
            $table->string('email_cliente', 120)->default('');
            $table->string('moneda', 3)->default('PEN');
            $table->decimal('total', 10, 2)->default(0);
            $table->json('payload');

            // ── Máquina de estados y backoff.
            $table->string('estado', 20)->default('PENDIENTE');
            $table->unsignedInteger('intentos')->default(0);
            $table->unsignedInteger('max_intentos')->default(8);
            $table->timestamp('proximo_intento_at')->nullable();
            $table->text('ultimo_error')->nullable();

            // ── Resultado de la emisión.
            $table->string('serie', 10)->nullable();
            $table->unsignedInteger('correlativo')->nullable();
            $table->unsignedBigInteger('api_doc_id')->nullable();
            $table->string('sunat_ticket', 60)->nullable();
            $table->string('cdr_estado', 20)->nullable();
            $table->string('cdr_hash', 100)->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('cdr_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();

            $table->timestamp('creado_en')->useCurrent();
            $table->timestamp('actualizado_en')->useCurrent();

            // Selección de candidatas del worker.
            $table->index(['estado', 'proximo_intento_at'], 'idx_cola_estado_intento');
            $table->index('venta_id', 'idx_cola_venta');
            $table->index('reporte_id', 'idx_cola_reporte');
            $table->index('ticket_id', 'idx_cola_ticket');

            $table->unique('clave_idempotencia', 'uq_cola_idempotencia');

            // Red de seguridad del correlativo: aunque la secuencia fallara, la BD
            // no acepta dos comprobantes con el mismo número para el mismo emisor.
            $table->unique(
                ['facturacion_config_id', 'serie', 'correlativo'],
                'uq_cola_emisor_serie_correlativo'
            );
        });
    }

    /**
     * BD adoptada del legacy: la tabla ya existe con el esquema de
     * `facturacion_cola_migrar()`. Se completa en vez de recrearse.
     */
    private function adaptarColaLegacy(): void
    {
        $columnas = [
            'venta_id'              => fn (Blueprint $t) => $t->unsignedBigInteger('venta_id')->nullable(),
            'reporte_id'            => fn (Blueprint $t) => $t->unsignedBigInteger('reporte_id')->nullable(),
            'facturacion_config_id' => fn (Blueprint $t) => $t->unsignedInteger('facturacion_config_id')->nullable(),
            'clave_idempotencia'    => fn (Blueprint $t) => $t->string('clave_idempotencia', 190)->nullable(),
            'cdr_path'              => fn (Blueprint $t) => $t->string('cdr_path', 255)->nullable(),
            'api_doc_id'            => fn (Blueprint $t) => $t->unsignedBigInteger('api_doc_id')->nullable(),
        ];

        foreach ($columnas as $columna => $definicion) {
            if (!Schema::hasColumn('comprobantes_cola', $columna)) {
                Schema::table('comprobantes_cola', $definicion);
            }
        }

        // `proximo_intento` → `proximo_intento_at`.
        if (
            Schema::hasColumn('comprobantes_cola', 'proximo_intento')
            && !Schema::hasColumn('comprobantes_cola', 'proximo_intento_at')
        ) {
            Schema::table('comprobantes_cola', function (Blueprint $table) {
                $table->renameColumn('proximo_intento', 'proximo_intento_at');
            });
        }

        // Estados y tipos legacy en minúsculas.
        foreach (self::ESTADOS_LEGACY as $legacy => $nuevo) {
            DB::table('comprobantes_cola')->where('estado', $legacy)->update(['estado' => $nuevo]);
        }

        foreach (self::TIPOS_LEGACY as $legacy => $nuevo) {
            DB::table('comprobantes_cola')->where('tipo_comprobante', $legacy)->update(['tipo_comprobante' => $nuevo]);
        }

        // `correlativo` VARCHAR('00000123') → entero. MySQL castea al cambiar el
        // tipo; se normaliza antes para que '' no acabe en 0 sino en NULL.
        DB::table('comprobantes_cola')->where('correlativo', '')->update(['correlativo' => null]);

        Schema::table('comprobantes_cola', function (Blueprint $table) {
            $table->unsignedInteger('correlativo')->nullable()->change();
        });

        $this->agregarIndices();
    }

    /** `hasIndex()` requiere Laravel 11+. Idempotente por si la migración se repite. */
    private function agregarIndices(): void
    {
        $indices = [
            'uq_cola_idempotencia'             => fn (Blueprint $t) => $t->unique('clave_idempotencia', 'uq_cola_idempotencia'),
            'uq_cola_emisor_serie_correlativo' => fn (Blueprint $t) => $t->unique(
                ['facturacion_config_id', 'serie', 'correlativo'],
                'uq_cola_emisor_serie_correlativo'
            ),
            'idx_cola_estado_intento'          => fn (Blueprint $t) => $t->index(['estado', 'proximo_intento_at'], 'idx_cola_estado_intento'),
            'idx_cola_venta'                   => fn (Blueprint $t) => $t->index('venta_id', 'idx_cola_venta'),
            'idx_cola_reporte'                 => fn (Blueprint $t) => $t->index('reporte_id', 'idx_cola_reporte'),
        ];

        foreach ($indices as $nombre => $definicion) {
            if (!Schema::hasIndex('comprobantes_cola', $nombre)) {
                Schema::table('comprobantes_cola', $definicion);
            }
        }
    }

    public function down(): void
    {
        // `comprobantes_cola` puede venir del legacy y contener CPE ya emitidos:
        // no se elimina en rollback, igual que `facturacion_config`.
        Schema::dropIfExists('comprobantes_correlativos');
    }
};
