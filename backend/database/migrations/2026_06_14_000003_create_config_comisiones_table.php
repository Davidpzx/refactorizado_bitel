<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('config_comisiones')) {
            return;
        }

        // Esquema legacy (DB.sql): tarifas simples (tipo+monto) y rangos PLAN/EQUIPO.
        Schema::create('config_comisiones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 50);
            $table->integer('rango_desde')->nullable();
            $table->integer('rango_hasta')->nullable();
            $table->decimal('monto', 10, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_comisiones');
    }
};
