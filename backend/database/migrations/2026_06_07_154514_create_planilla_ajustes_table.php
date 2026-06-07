<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('planilla_ajustes', function (Blueprint $table) {
            $table->unsignedInteger('agente_id');
            $table->string('mes', 7)->comment('YYYY-MM');
            $table->decimal('dias_trabajados', 5, 1)->default(30.0);
            $table->decimal('comision_jefe', 10, 2)->default(0.00);
            $table->decimal('comision_equipo', 10, 2)->default(0.00);
            $table->decimal('comision_planes', 10, 2)->default(0.00);
            $table->decimal('comision_online', 10, 2)->default(0.00);
            $table->decimal('retencion_uniforme', 10, 2)->default(0.00);
            $table->decimal('faltas_permisos', 10, 2)->default(0.00);
            $table->decimal('tardanzas', 10, 2)->default(0.00);
            $table->decimal('faltante_efectivo', 10, 2)->default(0.00);
            $table->boolean('override_comisiones')->default(false);
            $table->text('notas')->nullable();
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();

            $table->primary(['agente_id', 'mes']);
            $table->index('agente_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planilla_ajustes');
    }
};
