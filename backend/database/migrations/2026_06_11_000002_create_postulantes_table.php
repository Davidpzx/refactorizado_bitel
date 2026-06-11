<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('postulantes_temp')) {
            Schema::create('postulantes_temp', function (Blueprint $table) {
                $table->id();
                $table->string('dni', 12);
                $table->string('nombres', 150);
                $table->string('apellidos', 150);
                $table->string('telefono', 15)->nullable();
                $table->string('correo', 120)->nullable();
                $table->date('fecha_nacimiento')->nullable();
                $table->text('direccion')->nullable();
                $table->string('lugar_nacimiento', 255)->nullable();
                $table->string('tienda_postulada', 20)->nullable();
                $table->longText('formacion_academica')->nullable();
                $table->text('carga_familiar')->nullable();
                $table->text('experiencia_laboral')->nullable();
                $table->string('sistema_pension', 10)->default('ONP');
                $table->string('nombre_afp', 50)->nullable();
                $table->string('numero_cuspp', 20)->nullable();
                $table->string('grupo_sanguineo', 5)->nullable();
                $table->text('alergias')->nullable();
                $table->boolean('antecedentes_penales')->default(false);
                $table->boolean('antecedentes_policial')->default(false);
                $table->boolean('antecedentes_judicial')->default(false);
                $table->text('contactos_emergencia')->nullable();
                $table->text('notas_admin')->nullable();
                $table->string('estado', 20)->default('PENDIENTE');
                $table->timestamp('revisado_en')->nullable();
                $table->unsignedBigInteger('revisado_por')->nullable();
                $table->timestamps();

                $table->index('dni');
                $table->index('estado');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('postulantes_temp');
    }
};
