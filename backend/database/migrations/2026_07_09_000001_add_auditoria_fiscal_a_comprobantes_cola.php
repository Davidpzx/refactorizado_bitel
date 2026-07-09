<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TICKET-007 — Nota de crédito y anulación sobre `comprobantes_cola`.
 *
 * `comprobante_afectado_id` es el vínculo fiscal DURO entre una fila
 * NOTA_CREDITO y la boleta/factura ACEPTADA que credita: sin él, la NC no
 * tiene contra qué documento afectar ante SUNAT. Self-referencia dentro de
 * la misma tabla, sin FK física (mismo criterio que el resto del esquema:
 * ver `create_comprobantes_cola_table`).
 *
 * `anulado_*` es la auditoría de la anulación (quién, cuándo, motivo) que
 * pide el ticket. Vive en la propia fila anulada: es ella la que cambia de
 * estado, no un tercero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comprobantes_cola', function (Blueprint $table) {
            if (!Schema::hasColumn('comprobantes_cola', 'comprobante_afectado_id')) {
                $table->unsignedBigInteger('comprobante_afectado_id')->nullable()->after('facturacion_config_id');
                $table->index('comprobante_afectado_id', 'idx_cola_comprobante_afectado');
            }

            if (!Schema::hasColumn('comprobantes_cola', 'anulado_por_usuario_id')) {
                $table->unsignedBigInteger('anulado_por_usuario_id')->nullable()->after('ultimo_error');
            }

            if (!Schema::hasColumn('comprobantes_cola', 'anulado_en')) {
                $table->timestamp('anulado_en')->nullable()->after('anulado_por_usuario_id');
            }

            if (!Schema::hasColumn('comprobantes_cola', 'anulado_motivo')) {
                $table->string('anulado_motivo', 255)->nullable()->after('anulado_en');
            }
        });
    }

    public function down(): void
    {
        Schema::table('comprobantes_cola', function (Blueprint $table) {
            $table->dropIndex('idx_cola_comprobante_afectado');
            $table->dropColumn([
                'comprobante_afectado_id',
                'anulado_por_usuario_id',
                'anulado_en',
                'anulado_motivo',
            ]);
        });
    }
};
