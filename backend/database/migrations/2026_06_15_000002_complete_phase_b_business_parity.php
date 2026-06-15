<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reportes') && ! Schema::hasColumn('reportes', 'pago_payjoy')) {
            Schema::table('reportes', function (Blueprint $table) {
                $table->decimal('pago_payjoy', 12, 2)->default(0)->after('pago_krece');
            });
        }

        if (! Schema::hasTable('reporte_salidas')) {
            Schema::create('reporte_salidas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reporte_id');
                $table->enum('tipo', ['adelanto', 'gasto', 'pasaje', 'otro']);
                $table->decimal('monto', 10, 2);
                $table->text('observacion')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->foreign('reporte_id')->references('id')->on('reportes')->cascadeOnDelete();
                $table->index('reporte_id');
            });
        }

        if (! Schema::hasTable('reporte_comisiones_operativas')) {
            Schema::create('reporte_comisiones_operativas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('reporte_id');
                $table->string('tipo_servicio', 30);
                $table->decimal('monto_base', 12, 2)->default(0);
                $table->decimal('ganancia', 12, 2)->default(0);
                $table->timestamps();

                $table->foreign('reporte_id')->references('id')->on('reportes')->cascadeOnDelete();
                $table->unique(['reporte_id', 'tipo_servicio'], 'uq_reporte_comision_operativa');
                $table->index('tipo_servicio');
            });
        }

        if (Schema::hasTable('asistencias') && ! Schema::hasColumn('asistencias', 'horas_extras_aprobadas')) {
            Schema::table('asistencias', function (Blueprint $table) {
                $table->decimal('horas_extras_aprobadas', 5, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reporte_comisiones_operativas');
        Schema::dropIfExists('reporte_salidas');

        if (Schema::hasTable('asistencias') && Schema::hasColumn('asistencias', 'horas_extras_aprobadas')) {
            Schema::table('asistencias', function (Blueprint $table) {
                $table->dropColumn('horas_extras_aprobadas');
            });
        }

        if (Schema::hasTable('reportes') && Schema::hasColumn('reportes', 'pago_payjoy')) {
            Schema::table('reportes', function (Blueprint $table) {
                $table->dropColumn('pago_payjoy');
            });
        }
    }
};
