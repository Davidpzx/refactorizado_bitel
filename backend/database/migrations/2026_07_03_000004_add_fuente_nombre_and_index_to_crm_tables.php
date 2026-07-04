<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes de la revision de code review Wave 4 / M5 (crm-temperatura):
 * - crm_clientes.fuente_nombre: de donde salio nombres/apellidos (no se pisan tras el
 *   primer INSERT), para que DniController pueda distinguir un cache-hit verificado por
 *   RENIEC de un nombre tipeado a mano (MANUAL_CON_FALLBACK) antes de reportarlo como tal.
 * - indice compuesto en crm_interacciones para los filtros de CrmTemperaturaController::index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_clientes', 'fuente_nombre')) {
            Schema::table('crm_clientes', function (Blueprint $t) {
                $t->enum('fuente_nombre', ['RENIEC_API', 'MANUAL_CON_FALLBACK'])->nullable()->after('apellidos');
            });

            // Backfill: nombres/apellidos se fijan en el primer INSERT (el ON DUPLICATE KEY
            // de ClienteCrmController::guardar no los actualiza), asi que la fuente real es
            // la de la interaccion mas antigua de ese cliente. Sin interaccion previa no hay
            // forma de saberlo -> queda NULL, tratado como no-verificado (opcion conservadora).
            // UPDATE...JOIN es sintaxis MySQL; en sqlite (tests) no hay datos legacy que
            // migrar, así que el backfill se salta ahí.
            if (Schema::hasTable('crm_interacciones') && DB::connection()->getDriverName() === 'mysql') {
                DB::statement('
                    UPDATE crm_clientes c
                    JOIN (
                        SELECT i1.cliente_id, i1.fuente_registro
                        FROM crm_interacciones i1
                        WHERE i1.fecha_hora = (
                            SELECT MIN(i2.fecha_hora) FROM crm_interacciones i2 WHERE i2.cliente_id = i1.cliente_id
                        )
                        GROUP BY i1.cliente_id, i1.fuente_registro
                    ) primera ON primera.cliente_id = c.id
                    SET c.fuente_nombre = primera.fuente_registro
                ');
            }
        }

        if (Schema::hasTable('crm_interacciones') && ! $this->indexExists('crm_interacciones', 'idx_interaccion_tienda_fecha')) {
            Schema::table('crm_interacciones', function (Blueprint $t) {
                $t->index(['tienda_codigo', 'fecha_hora'], 'idx_interaccion_tienda_fecha');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_clientes', 'fuente_nombre')) {
            Schema::table('crm_clientes', fn (Blueprint $t) => $t->dropColumn('fuente_nombre'));
        }

        if (Schema::hasTable('crm_interacciones') && $this->indexExists('crm_interacciones', 'idx_interaccion_tienda_fecha')) {
            Schema::table('crm_interacciones', fn (Blueprint $t) => $t->dropIndex('idx_interaccion_tienda_fecha'));
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(Schema::getIndexes($table))->pluck('name')->contains($indexName);
    }
};
