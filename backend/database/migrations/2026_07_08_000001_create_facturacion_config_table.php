<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paridad legacy `config/facturacion_config.php` (sistema-rolando-salas).
 *
 * El legacy auto-migraba la tabla desde el request (CREATE TABLE IF NOT EXISTS +
 * ALTER por columna). Aquí eso vive donde corresponde: una migración idempotente.
 *
 * Dos diferencias deliberadas respecto del legacy:
 *  1. La fila global se identifica con `tienda_id IS NULL` (el legacy usaba `''`).
 *     Se normalizan las filas legacy existentes.
 *  2. `api_token` pasa a TEXT y se cifra en reposo (cast `encrypted` del modelo).
 *     El ciphertext de Laravel no cabe en VARCHAR(255).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tablaYaExistia = Schema::hasTable('facturacion_config');

        if (!$tablaYaExistia) {
            Schema::create('facturacion_config', function (Blueprint $table) {
                $table->increments('id');

                // Código de tienda (`tiendas.codigo`), NO el id numérico: es la
                // convención del resto del esquema y la del legacy (VARCHAR(20)).
                // NULL = configuración global (fallback).
                $table->string('tienda_id', 20)->nullable();

                $table->unsignedInteger('company_id')->default(1);
                $table->unsignedInteger('branch_id')->default(1);

                $table->string('base_url', 255)->default('');
                $table->text('api_token')->nullable();

                $table->string('ruc', 20)->default('');
                $table->string('razon_social_emisor', 200)->default('');

                $table->string('emisor_ubigeo', 10)->default('150101');
                $table->string('emisor_distrito', 60)->default('Lima');
                $table->string('emisor_provincia', 60)->default('Lima');
                $table->string('emisor_departamento', 60)->default('Lima');

                $table->string('modo', 10)->default('beta');

                $table->string('serie_boleta', 10)->default('B001');
                $table->string('serie_factura', 10)->default('F001');
                $table->string('serie_nota_credito', 10)->default('FC01');

                $table->decimal('igv_porcentaje', 5, 2)->default(18.00);
                $table->boolean('activo')->default(false);

                $table->timestamp('creado_en')->useCurrent();
                $table->timestamp('actualizado_en')->useCurrent();

                // Impide duplicar la config de una tienda. NO impide varias filas
                // globales: en MySQL/SQLite los NULL no colisionan en un UNIQUE.
                // La unicidad de la global se sostiene en el seeder (updateOrCreate)
                // y en FacturacionConfig::globalConfig(), que ordena por id.
                $table->unique('tienda_id', 'uq_facturacion_config_tienda');
            });

            return;
        }

        // ── Tabla preexistente (BD adoptada del legacy) ────────────────────────
        $columnas = [
            'company_id'          => fn (Blueprint $t) => $t->unsignedInteger('company_id')->default(1),
            'branch_id'           => fn (Blueprint $t) => $t->unsignedInteger('branch_id')->default(1),
            'serie_nota_credito'  => fn (Blueprint $t) => $t->string('serie_nota_credito', 10)->default('FC01'),
            'emisor_ubigeo'       => fn (Blueprint $t) => $t->string('emisor_ubigeo', 10)->default('150101'),
            'emisor_distrito'     => fn (Blueprint $t) => $t->string('emisor_distrito', 60)->default('Lima'),
            'emisor_provincia'    => fn (Blueprint $t) => $t->string('emisor_provincia', 60)->default('Lima'),
            'emisor_departamento' => fn (Blueprint $t) => $t->string('emisor_departamento', 60)->default('Lima'),
            // Sin `useCurrent()`: SQLite rechaza ADD COLUMN con default no constante.
            // Se rellena abajo desde `actualizado_en`.
            'creado_en'           => fn (Blueprint $t) => $t->timestamp('creado_en')->nullable(),
        ];

        foreach ($columnas as $columna => $definicion) {
            if (!Schema::hasColumn('facturacion_config', $columna)) {
                Schema::table('facturacion_config', $definicion);
            }
        }

        // Las filas legacy no tienen fecha de creación: la mejor aproximación
        // disponible es su última actualización.
        DB::table('facturacion_config')
            ->whereNull('creado_en')
            ->update(['creado_en' => DB::raw('actualizado_en')]);

        // `api_token` legacy: VARCHAR(255) en claro → TEXT cifrado.
        if (Schema::hasColumn('facturacion_config', 'api_token')) {
            Schema::table('facturacion_config', function (Blueprint $table) {
                $table->text('api_token')->nullable()->change();
            });

            $this->cifrarTokensEnClaro();
        }

        // La fila global legacy usa tienda_id = ''; aquí es NULL.
        Schema::table('facturacion_config', function (Blueprint $table) {
            $table->string('tienda_id', 20)->nullable()->change();
        });

        DB::table('facturacion_config')->where('tienda_id', '')->update(['tienda_id' => null]);
    }

    /**
     * Cifra los tokens que aún están en texto plano. Idempotente: un valor ya
     * cifrado se descifra sin excepción y se deja como está.
     */
    private function cifrarTokensEnClaro(): void
    {
        $filas = DB::table('facturacion_config')
            ->select('id', 'api_token')
            ->whereNotNull('api_token')
            ->where('api_token', '!=', '')
            ->get();

        foreach ($filas as $fila) {
            try {
                Crypt::decryptString($fila->api_token);
                continue; // Ya cifrado.
            } catch (\Throwable) {
                // En claro: cifrar.
            }

            DB::table('facturacion_config')
                ->where('id', $fila->id)
                ->update(['api_token' => Crypt::encryptString($fila->api_token)]);
        }
    }

    public function down(): void
    {
        // Tabla potencialmente compartida con el legacy: no se elimina en rollback.
    }
};
