<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('excepciones_jornada')) {
            return;
        }

        // Shape canónico: sql/migracion_esquema_brisel.sql (legacy sis_bipay), que es
        // el script mantenido de "tablas faltantes" — usado por delante del CREATE TABLE
        // IF NOT EXISTS más simple embebido en ajax_excepcion_jornada.php/planilla_agentes.php.
        Schema::create('excepciones_jornada', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('agente_id');
            $table->date('fecha');
            $table->enum('tipo', ['MEDIO_TIEMPO', 'TURNO_LIBRE', 'OTRO'])->default('MEDIO_TIEMPO');
            $table->decimal('horas_trabajadas', 4, 2)->default(4.00);
            $table->unsignedInteger('registrado_por')->nullable();
            $table->text('notas')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['agente_id', 'fecha'], 'uq_agente_fecha');
            $table->index('agente_id', 'idx_agente');
            $table->index('fecha', 'idx_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('excepciones_jornada');
    }
};
