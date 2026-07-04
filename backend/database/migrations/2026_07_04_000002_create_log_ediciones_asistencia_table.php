<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('log_ediciones_asistencia')) {
            return;
        }

        // Shape canónico: sql/migracion_esquema_brisel.sql y config/attendance_helpers.php
        // (auto-migración) del legacy sis_bipay — auditoría de ediciones/eliminaciones/
        // creaciones manuales de asistencias hechas por admin/gerencia.
        Schema::create('log_ediciones_asistencia', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('asistencia_id');
            $table->integer('agente_id');
            $table->integer('admin_id');
            $table->string('campo_modificado', 60);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->timestamp('fecha_cambio')->useCurrent();
            $table->string('ip_admin', 45)->default('');

            $table->index('asistencia_id', 'idx_log_asis_asistencia');
            $table->index('agente_id', 'idx_log_asis_agente');
            $table->index('fecha_cambio', 'idx_log_asis_fecha');
        });
    }

    public function down(): void
    {
        // Tabla compartida con el legacy: no se elimina en rollback por seguridad.
    }
};
