<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historial_reportes')) {
            // Add FK retroactively if reportes now exists
            if (Schema::hasTable('reportes') && !Schema::hasColumn('historial_reportes', 'reporte_id_fk_exists')) {
                try {
                    Schema::table('historial_reportes', function (Blueprint $table) {
                        $table->foreign('reporte_id')->references('id')->on('reportes')->onDelete('cascade');
                    });
                } catch (\Throwable) {}
            }
            return;
        }

        Schema::create('historial_reportes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reporte_id');
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->enum('accion', [
                'crear', 'solicito_edicion', 'edicion_aprobada', 'edicion_rechazada',
                'edicion_reporte', 'edicion_critica', 'edicion_restaurada', 'destino_modificado',
            ]);
            $table->text('detalle')->nullable();
            $table->json('snapshot_antes')->nullable();
            $table->json('snapshot_despues')->nullable();
            $table->timestamps();

            $table->index('reporte_id');
        });

        // Add FK only if reportes table exists (remote DB may already have it)
        if (Schema::hasTable('reportes')) {
            Schema::table('historial_reportes', function (Blueprint $table) {
                $table->foreign('reporte_id')->references('id')->on('reportes')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_reportes');
    }
};
