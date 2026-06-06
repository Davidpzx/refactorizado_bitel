<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->string('dni_ruc', 11)->unique();
            $table->string('nombre', 200)->default('');
            $table->string('telefono', 15)->nullable();
            $table->string('correo', 120)->nullable();
            $table->enum('tipo_documento', ['DNI', 'RUC', 'CE', 'PAS'])->default('DNI');
            $table->timestamp('creado_en')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
