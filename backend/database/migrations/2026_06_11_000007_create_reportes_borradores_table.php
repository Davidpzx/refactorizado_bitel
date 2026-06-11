<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reportes_borradores')) {
            return;
        }

        Schema::create('reportes_borradores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agente_id');
            $table->string('tienda_id', 20);
            $table->date('fecha');
            $table->mediumText('datos_json');
            $table->dateTime('creado_en')->useCurrent();
            $table->dateTime('actualizado_en')->useCurrent();

            $table->unique(
                ['agente_id', 'tienda_id', 'fecha'],
                'uq_borrador_agente_tienda_fecha'
            );
            $table->index('fecha', 'idx_reportes_borradores_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reportes_borradores');
    }
};
