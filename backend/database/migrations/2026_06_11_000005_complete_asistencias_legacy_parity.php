<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agentes')) {
            $columns = [
                'dni' => fn (Blueprint $table) => $table->string('dni', 8)->nullable()->index(),
                'hora_ingreso' => fn (Blueprint $table) => $table->time('hora_ingreso')->nullable(),
                'hora_salida' => fn (Blueprint $table) => $table->time('hora_salida')->nullable(),
                'hora_ref_inicio' => fn (Blueprint $table) => $table->time('hora_ref_inicio')->nullable(),
                'hora_ref_fin' => fn (Blueprint $table) => $table->time('hora_ref_fin')->nullable(),
                'dia_descanso' => fn (Blueprint $table) => $table->string('dia_descanso', 15)->nullable(),
                'deuda_dias' => fn (Blueprint $table) => $table->unsignedInteger('deuda_dias')->default(0),
                'hash_facial' => fn (Blueprint $table) => $table->string('hash_facial', 128)->nullable(),
                'tienda_registro_inicial' => fn (Blueprint $table) => $table->string('tienda_registro_inicial', 20)->nullable(),
                'fecha_registro_disp' => fn (Blueprint $table) => $table->dateTime('fecha_registro_disp')->nullable(),
                'token_emergencia' => fn (Blueprint $table) => $table->string('token_emergencia', 6)->nullable(),
                'expiracion_token' => fn (Blueprint $table) => $table->dateTime('expiracion_token')->nullable(),
                'permiso_largo' => fn (Blueprint $table) => $table->boolean('permiso_largo')->default(false),
                'fecha_retorno' => fn (Blueprint $table) => $table->date('fecha_retorno')->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('agentes', $column)) {
                    Schema::table('agentes', $definition);
                }
            }
        }

        if (Schema::hasTable('asistencias')) {
            if (Schema::hasColumn('asistencias', 'foto_marcacion')) {
                Schema::table('asistencias', function (Blueprint $table) {
                    $table->longText('foto_marcacion')->nullable()->change();
                });
            }

            $columns = [
                'tienda_salida' => fn (Blueprint $table) => $table->string('tienda_salida', 20)->nullable(),
                'tienda_inicio_ref' => fn (Blueprint $table) => $table->string('tienda_inicio_ref', 20)->nullable(),
                'tienda_fin_ref' => fn (Blueprint $table) => $table->string('tienda_fin_ref', 20)->nullable(),
                'minutos_refrigerio_asignado' => fn (Blueprint $table) => $table->unsignedInteger('minutos_refrigerio_asignado')->nullable(),
                'minutos_tardanza_refrigerio' => fn (Blueprint $table) => $table->unsignedInteger('minutos_tardanza_refrigerio')->default(0),
                'comodin_usado' => fn (Blueprint $table) => $table->boolean('comodin_usado')->default(false),
                'min_comodin' => fn (Blueprint $table) => $table->unsignedInteger('min_comodin')->default(0),
                'estado_extra' => fn (Blueprint $table) => $table->string('estado_extra', 20)->nullable(),
                'metodo_salida_refrigerio' => fn (Blueprint $table) => $table->string('metodo_salida_refrigerio', 10)->nullable(),
                'metodo_entrada_refrigerio' => fn (Blueprint $table) => $table->string('metodo_entrada_refrigerio', 10)->nullable(),
                'metodo_salida' => fn (Blueprint $table) => $table->string('metodo_salida', 10)->nullable(),
                'lat_salida_refrigerio' => fn (Blueprint $table) => $table->decimal('lat_salida_refrigerio', 10, 7)->nullable(),
                'lng_salida_refrigerio' => fn (Blueprint $table) => $table->decimal('lng_salida_refrigerio', 10, 7)->nullable(),
                'accuracy_salida_refrigerio' => fn (Blueprint $table) => $table->float('accuracy_salida_refrigerio')->nullable(),
                'distancia_salida_refrigerio' => fn (Blueprint $table) => $table->float('distancia_salida_refrigerio')->nullable(),
                'lat_entrada_refrigerio' => fn (Blueprint $table) => $table->decimal('lat_entrada_refrigerio', 10, 7)->nullable(),
                'lng_entrada_refrigerio' => fn (Blueprint $table) => $table->decimal('lng_entrada_refrigerio', 10, 7)->nullable(),
                'accuracy_entrada_refrigerio' => fn (Blueprint $table) => $table->float('accuracy_entrada_refrigerio')->nullable(),
                'distancia_entrada_refrigerio' => fn (Blueprint $table) => $table->float('distancia_entrada_refrigerio')->nullable(),
                'lat_salida' => fn (Blueprint $table) => $table->decimal('lat_salida', 10, 7)->nullable(),
                'lng_salida' => fn (Blueprint $table) => $table->decimal('lng_salida', 10, 7)->nullable(),
                'accuracy_salida' => fn (Blueprint $table) => $table->float('accuracy_salida')->nullable(),
                'distancia_salida' => fn (Blueprint $table) => $table->float('distancia_salida')->nullable(),
                'observacion_admin' => fn (Blueprint $table) => $table->string('observacion_admin', 500)->nullable(),
            ];

            foreach ($columns as $column => $definition) {
                if (! Schema::hasColumn('asistencias', $column)) {
                    Schema::table('asistencias', $definition);
                }
            }
        }

        if (! Schema::hasTable('asistencia_intentos_fallidos')) {
            Schema::create('asistencia_intentos_fallidos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agente_id')->nullable();
                $table->string('tipo_marcacion', 30)->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->float('accuracy')->nullable();
                $table->float('distancia')->nullable();
                $table->string('motivo', 40);
                $table->dateTime('fecha');
                $table->index(['agente_id', 'fecha']);
            });
        }

        if (! Schema::hasTable('log_fraude_dispositivo')) {
            Schema::create('log_fraude_dispositivo', function (Blueprint $table) {
                $table->id();
                $table->dateTime('fecha_hora');
                $table->unsignedBigInteger('agente_id')->nullable();
                $table->string('nombre_agente', 150)->nullable();
                $table->string('dni_ingresado', 20)->nullable();
                $table->string('dni_duenio_hash', 150)->nullable();
                $table->string('tienda_intento', 30)->nullable();
                $table->index(['agente_id', 'fecha_hora']);
            });
        }
    }

    public function down(): void
    {
        // Additive compatibility migration: legacy installations may already own these columns.
    }
};
