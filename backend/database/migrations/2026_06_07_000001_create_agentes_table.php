<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('agentes', function (Blueprint $table) {
            $table->id();
            $table->string('dni', 8)->unique();
            $table->string('nombres', 200);
            $table->string('tienda_base', 10);
            $table->string('hora_ingreso', 8)->nullable();
            $table->string('hora_salida', 8)->nullable();
            $table->decimal('sueldo_base', 10, 2)->default(0);
            $table->tinyInteger('dia_pago')->nullable();
            $table->enum('estado', ['ACTIVO', 'INACTIVO', 'BAJA'])->default('ACTIVO');
            $table->string('dia_descanso', 20)->nullable();
            $table->string('pin_seguridad', 255); // bcrypt hash
            $table->date('fecha_ingreso');
            $table->string('correo', 120)->nullable();
            $table->string('telefono', 15)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->boolean('es_gerencia')->default(false);
            $table->boolean('permiso_largo')->default(false);
            $table->date('fecha_retorno')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->string('hash_dispositivo', 255)->nullable();
            $table->string('hash_facial', 255)->nullable();
            $table->boolean('antecedentes_penales')->nullable();
            $table->json('formacion_academica')->nullable();
            $table->json('carga_familiar')->nullable();
            $table->json('experiencia_laboral')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index('tienda_base');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agentes');
    }
};
