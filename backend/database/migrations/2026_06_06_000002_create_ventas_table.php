<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reporte_id');
            $table->integer('vendedor_id');
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->enum('tipo_venta', ['EQUIPO', 'ACCESORIO', 'POSTPAGO', 'PREPAGO', 'OTROS_FLUJO']);
            $table->string('subtipo', 50)->nullable();
            $table->boolean('cross_selling')->default(false);
            $table->string('tienda_destino', 10)->nullable();
            $table->decimal('monto_total', 10, 2)->default(0);
            $table->decimal('efectivo_inicial', 10, 2)->default(0);
            $table->decimal('comision_generada', 10, 2)->default(0);
            $table->enum('comision_estado', ['ACTIVA', 'PENDIENTE', 'ANULADA'])->default('ACTIVA');
            $table->boolean('es_remate')->default(false);
            $table->boolean('es_extranjero')->default(false);
            $table->timestamp('creado_en')->useCurrent();

            $table->index('reporte_id');
            $table->index('vendedor_id');
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventas');
    }
};
