<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('asistencias')) {
            Schema::create('asistencias', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agente_id');
                $table->string('tienda_id', 20)->nullable();
                $table->date('fecha');
                $table->time('hora_ingreso')->nullable();
                $table->string('tienda_ingreso', 20)->nullable();
                $table->time('inicio_refrigerio')->nullable();
                $table->time('fin_refrigerio')->nullable();
                $table->time('hora_salida')->nullable();
                $table->integer('minutos_tardanza')->default(0);
                $table->integer('minutos_deuda')->default(0);
                $table->integer('minutos_extra')->default(0);
                $table->boolean('omitio_refrigerio')->default(false);
                $table->boolean('turno_extendido')->default(false);
                $table->enum('estado_asistencia', ['REGULAR', 'TARDANZA', 'FALTA_INJUSTIFICADA', 'PERMISO', 'CIERRE_AUTO'])->default('REGULAR');
                $table->boolean('requiere_revision')->default(false);
                $table->enum('metodo_marcacion', ['MANUAL', 'GPS', 'QR', 'FOTO'])->default('MANUAL');
                $table->longText('foto_marcacion')->nullable();
                $table->decimal('lat_entrada', 10, 7)->nullable();
                $table->decimal('lng_entrada', 10, 7)->nullable();
                $table->float('accuracy_entrada')->nullable();
                $table->float('distancia_entrada')->nullable();
                $table->dateTime('hora_intento_gps')->nullable();
                $table->string('observacion', 255)->nullable();
                $table->timestamps();

                $table->unique(['agente_id', 'fecha']);
                $table->index('fecha');
            });
            return;
        }

        // Si ya existe, agregar columnas faltantes
        Schema::table('asistencias', function (Blueprint $table) {
            $cols = [
                'tienda_ingreso'    => fn() => $table->string('tienda_ingreso', 20)->nullable()->after('tienda_id'),
                'inicio_refrigerio' => fn() => $table->time('inicio_refrigerio')->nullable()->after('hora_ingreso'),
                'fin_refrigerio'    => fn() => $table->time('fin_refrigerio')->nullable()->after('inicio_refrigerio'),
                'minutos_tardanza'  => fn() => $table->integer('minutos_tardanza')->default(0)->after('hora_salida'),
                'minutos_deuda'     => fn() => $table->integer('minutos_deuda')->default(0)->after('minutos_tardanza'),
                'minutos_extra'     => fn() => $table->integer('minutos_extra')->default(0)->after('minutos_deuda'),
                'omitio_refrigerio' => fn() => $table->boolean('omitio_refrigerio')->default(false),
                'turno_extendido'   => fn() => $table->boolean('turno_extendido')->default(false),
                'estado_asistencia' => fn() => $table->string('estado_asistencia', 30)->default('REGULAR'),
                'foto_marcacion'    => fn() => $table->longText('foto_marcacion')->nullable(),
                'lat_entrada'       => fn() => $table->decimal('lat_entrada', 10, 7)->nullable(),
                'lng_entrada'       => fn() => $table->decimal('lng_entrada', 10, 7)->nullable(),
                'accuracy_entrada'  => fn() => $table->float('accuracy_entrada')->nullable(),
                'distancia_entrada' => fn() => $table->float('distancia_entrada')->nullable(),
                'hora_intento_gps'  => fn() => $table->dateTime('hora_intento_gps')->nullable(),
            ];
            foreach ($cols as $col => $add) {
                if (!Schema::hasColumn('asistencias', $col)) $add();
            }
        });
    }

    public function down(): void {}
};
