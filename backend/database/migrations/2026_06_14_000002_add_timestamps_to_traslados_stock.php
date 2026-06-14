<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drift legacy: la tabla traslados_stock ya existía sin timestamps, así que la
 * migración guardada (if !hasTable) la saltó. El modelo TrasladoStock usa
 * timestamps=true → created_at/updated_at faltantes rompen `order by created_at`
 * (pendientesAprobacion vía ->get()) y TrasladoStock::create(). index() solo
 * parecía funcionar porque la tabla está vacía y paginate() omite el query de
 * datos cuando count=0. Idempotente: agrega lo que falte (incluye traslados_chips
 * por las dudas).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['traslados_stock', 'traslados_chips'] as $tabla) {
            if (!Schema::hasTable($tabla)) continue;
            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                if (!Schema::hasColumn($tabla, 'created_at')) $t->timestamp('created_at')->nullable();
                if (!Schema::hasColumn($tabla, 'updated_at')) $t->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // No-op: correctivo de esquema legacy.
    }
};
