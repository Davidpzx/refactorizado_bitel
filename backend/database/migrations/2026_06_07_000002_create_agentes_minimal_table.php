<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Esquema mínimo para que los tests de Feature puedan validar exists:agentes,id
// En producción esta migración es no-op (la tabla ya existe con schema completo)
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agentes')) {
            return;
        }

        Schema::create('agentes', function (Blueprint $table) {
            $table->integer('id')->unsigned()->primary();
            $table->string('nombres', 100)->default('');
            $table->string('estado', 20)->default('ACTIVO');
            $table->string('tienda_base', 10)->nullable();
            $table->decimal('sueldo_base', 10, 2)->default(0.00);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentes');
    }
};
