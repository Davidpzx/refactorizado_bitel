<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 #10 — Baja de agente con motivo/clasificación + auditoría historial_agentes.
 *
 * Idempotente (patrón de 2026_06_14_000001_fix_legacy_schema_drift): en producción la BD fue
 * heredada del dump legacy, por lo que estas columnas/tabla probablemente YA existen. La migración
 * solo versiona lo que falte, sin romper datos existentes. `historial_agentes` también puede haber
 * sido creada en runtime por el comando bitel:auto-retorno con un shape mínimo; aquí se completan
 * las columnas extendidas del legacy (tipo_cambio/campo_anterior/campo_nuevo/registrado_por).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── agentes: columnas de baja ────────────────────────────────────────
        if (Schema::hasTable('agentes')) {
            $columns = [
                'clasificacion_baja' => fn (Blueprint $t) => $t->string('clasificacion_baja', 20)->nullable(),
                'motivo_baja'        => fn (Blueprint $t) => $t->text('motivo_baja')->nullable(),
                'fecha_baja'         => fn (Blueprint $t) => $t->date('fecha_baja')->nullable(),
                'observacion'        => fn (Blueprint $t) => $t->text('observacion')->nullable(),
            ];
            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('agentes', $column)) {
                    Schema::table('agentes', $definition);
                }
            }
        }

        // ── historial_agentes: auditoría (shape completo del legacy) ──────────
        if (! Schema::hasTable('historial_agentes')) {
            Schema::create('historial_agentes', function (Blueprint $t) {
                $t->increments('id');
                $t->integer('id_agente')->index('idx_id_agente');
                $t->string('estado', 20);
                $t->string('clasificacion_baja', 20)->nullable();
                $t->text('observacion')->nullable();
                $t->timestamp('fecha_registro')->useCurrent();
                $t->string('tipo_cambio', 40)->default('ESTADO');
                $t->text('campo_anterior')->nullable();
                $t->text('campo_nuevo')->nullable();
                $t->unsignedBigInteger('registrado_por')->nullable();
            });
        } else {
            $columns = [
                'clasificacion_baja' => fn (Blueprint $t) => $t->string('clasificacion_baja', 20)->nullable(),
                'observacion'        => fn (Blueprint $t) => $t->text('observacion')->nullable(),
                'tipo_cambio'        => fn (Blueprint $t) => $t->string('tipo_cambio', 40)->default('ESTADO'),
                'campo_anterior'     => fn (Blueprint $t) => $t->text('campo_anterior')->nullable(),
                'campo_nuevo'        => fn (Blueprint $t) => $t->text('campo_nuevo')->nullable(),
                'registrado_por'     => fn (Blueprint $t) => $t->unsignedBigInteger('registrado_por')->nullable(),
            ];
            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('historial_agentes', $column)) {
                    Schema::table('historial_agentes', $definition);
                }
            }
        }
    }

    public function down(): void
    {
        // No-op: correctivo aditivo de esquema legacy; no revertir para no perder datos.
    }
};
