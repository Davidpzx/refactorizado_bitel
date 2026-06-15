<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventario_chips') && ! Schema::hasColumn('inventario_chips', 'series_info')) {
            Schema::table('inventario_chips', function (Blueprint $table) {
                $table->text('series_info')->nullable();
            });
        }

        if (Schema::hasTable('historial_inventario')) {
            $columns = [
                'tienda_origen' => fn (Blueprint $table) => $table->string('tienda_origen', 20)->nullable(),
                'imei_serial' => fn (Blueprint $table) => $table->string('imei_serial', 50)->nullable(),
                'precio_en_ese_momento' => fn (Blueprint $table) => $table->decimal('precio_en_ese_momento', 10, 2)->nullable(),
                'dni_autorizacion' => fn (Blueprint $table) => $table->string('dni_autorizacion', 15)->nullable(),
                'stock_anterior' => fn (Blueprint $table) => $table->integer('stock_anterior')->nullable(),
                'stock_nuevo' => fn (Blueprint $table) => $table->integer('stock_nuevo')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('historial_inventario', $column)) {
                    Schema::table('historial_inventario', $definition);
                }
            }
        }

        if (Schema::hasTable('agentes')) {
            $columns = [
                'apellidos' => fn (Blueprint $table) => $table->string('apellidos', 150)->nullable(),
                'fecha_ingreso' => fn (Blueprint $table) => $table->date('fecha_ingreso')->nullable(),
                'fecha_prueba_inicio' => fn (Blueprint $table) => $table->date('fecha_prueba_inicio')->nullable(),
                'fecha_prueba_fin' => fn (Blueprint $table) => $table->date('fecha_prueba_fin')->nullable(),
                'fecha_nacimiento' => fn (Blueprint $table) => $table->date('fecha_nacimiento')->nullable(),
                'lugar_nacimiento' => fn (Blueprint $table) => $table->string('lugar_nacimiento', 255)->nullable(),
                'formacion_academica' => fn (Blueprint $table) => $table->longText('formacion_academica')->nullable(),
                'carga_familiar' => fn (Blueprint $table) => $table->longText('carga_familiar')->nullable(),
                'experiencia_laboral' => fn (Blueprint $table) => $table->longText('experiencia_laboral')->nullable(),
                'contactos_emergencia' => fn (Blueprint $table) => $table->longText('contactos_emergencia')->nullable(),
                'sistema_pension' => fn (Blueprint $table) => $table->string('sistema_pension', 50)->nullable(),
                'nombre_afp' => fn (Blueprint $table) => $table->string('nombre_afp', 50)->nullable(),
                'numero_cuspp' => fn (Blueprint $table) => $table->string('numero_cuspp', 50)->nullable(),
                'grupo_sanguineo' => fn (Blueprint $table) => $table->string('grupo_sanguineo', 10)->nullable(),
                'alergias' => fn (Blueprint $table) => $table->text('alergias')->nullable(),
                'antecedentes_penales' => fn (Blueprint $table) => $table->boolean('antecedentes_penales')->default(false),
                'antecedentes_policial' => fn (Blueprint $table) => $table->boolean('antecedentes_policial')->default(false),
                'antecedentes_judicial' => fn (Blueprint $table) => $table->boolean('antecedentes_judicial')->default(false),
                'correo' => fn (Blueprint $table) => $table->string('correo', 120)->nullable(),
                'telefono' => fn (Blueprint $table) => $table->string('telefono', 15)->nullable(),
                'direccion' => fn (Blueprint $table) => $table->text('direccion')->nullable(),
                'es_gerencia' => fn (Blueprint $table) => $table->string('es_gerencia', 30)->default('0'),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('agentes', $column)) {
                    Schema::table('agentes', $definition);
                }
            }
        }
    }

    public function down(): void
    {
        // Additive compatibility migration. Existing legacy data must not be removed.
    }
};
