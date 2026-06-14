<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correctivo de drift de esquema: la BD productiva (legacy "migracion") ya tenía
 * varias tablas, por lo que las migraciones guardadas con `if (!hasTable)` las
 * saltaron y quedaron sin columnas que el código nuevo espera. Además leads/
 * interacciones_crm figuran como "Ran" pero las tablas fueron dropeadas.
 * Esta migración es idempotente y solo agrega lo que falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // diagnostico → tiendas.activo
        if (Schema::hasTable('tiendas') && !Schema::hasColumn('tiendas', 'activo')) {
            Schema::table('tiendas', fn (Blueprint $t) => $t->boolean('activo')->default(true));
        }

        // bitacora-stock → historial_inventario.motivo
        if (Schema::hasTable('historial_inventario') && !Schema::hasColumn('historial_inventario', 'motivo')) {
            Schema::table('historial_inventario', fn (Blueprint $t) => $t->string('motivo', 255)->nullable());
        }

        // reportes/{id}/historial → historial_reportes.created_at/updated_at
        if (Schema::hasTable('historial_reportes')) {
            Schema::table('historial_reportes', function (Blueprint $t) {
                if (!Schema::hasColumn('historial_reportes', 'created_at')) $t->timestamp('created_at')->nullable();
                if (!Schema::hasColumn('historial_reportes', 'updated_at')) $t->timestamp('updated_at')->nullable();
            });
            // backfill: preservar orden cronológico desde la columna legacy fecha_hora
            if (Schema::hasColumn('historial_reportes', 'fecha_hora')) {
                DB::statement('UPDATE historial_reportes SET created_at = fecha_hora WHERE created_at IS NULL');
                DB::statement('UPDATE historial_reportes SET updated_at = fecha_hora WHERE updated_at IS NULL');
            }
        }

        // crm/pipeline + leads → recrear tabla leads (migración "Ran" pero tabla inexistente)
        if (!Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->integer('agente_id');
                $table->string('tienda_id', 10);
                $table->enum('estado', ['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO'])->default('NUEVO');
                $table->enum('fuente', ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA'])->default('PRESENCIAL');
                $table->text('notas')->nullable();
                $table->timestamp('creado_en')->useCurrent();
                $table->index('agente_id');
                $table->index('estado');
                if (Schema::hasTable('clientes')) {
                    $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('set null');
                }
            });
        }

        if (!Schema::hasTable('interacciones_crm')) {
            Schema::create('interacciones_crm', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('lead_id')->nullable();
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->integer('agente_id');
                $table->enum('tipo', ['LLAMADA', 'VISITA', 'WHATSAPP', 'VENTA', 'POSTVENTA']);
                $table->text('detalle')->nullable();
                $table->timestamp('fecha')->useCurrent();
                $table->index('cliente_id');
                $table->index('agente_id');
                $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        // No-op: correctivo de esquema legacy; no revertir para no perder datos.
    }
};
