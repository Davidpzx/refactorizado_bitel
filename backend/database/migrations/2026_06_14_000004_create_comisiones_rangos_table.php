<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comisiones_rangos')) {
            return;
        }

        // Rangos de ganancia por monto para Bipay / Krece / Payjoy (esquema legacy DB.sql).
        Schema::create('comisiones_rangos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_servicio', 30)->comment('bipay | krece | payjoy');
            $table->decimal('monto_min', 10, 2)->default(0);
            $table->decimal('monto_max', 10, 2)->nullable()->comment('NULL = sin límite superior');
            $table->decimal('ganancia', 10, 2)->default(0);
            $table->timestamp('creado_en')->useCurrent();
            $table->index(['tipo_servicio', 'monto_min'], 'idx_tipo_min');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_rangos');
    }
};
