<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pagos_planilla', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agente_id');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->decimal('sueldo_base',          10, 2)->default(0);
            $table->decimal('bonos_comisiones',     10, 2)->default(0);
            $table->decimal('descuento_tardanza',   10, 2)->default(0);
            $table->decimal('descuento_adelantos',  10, 2)->default(0);
            $table->decimal('total_pagado',         10, 2)->default(0);
            $table->enum('estado', ['PENDIENTE', 'PAGADO'])->default('PENDIENTE');
            $table->timestamp('fecha_pago')->useCurrent();
            $table->timestamps();

            $table->index('agente_id');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_planilla');
    }
};
