<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// APP-04 — Monitoreo de presencia por ubicación (DECISIÓN-APP-03: estado, no historial).
// asistencia_presencia: UNA fila por agente en turno (upsert del último ping).
// asistencia_incidencias_ubicacion: solo eventos (fuera_de_rango|mock_gps|sin_senal).
// Sin foreign keys duras (convención del resto del esquema: agentes.id es integer
// unsigned y las tablas de dominio no declaran ->foreign()); se indexan las llaves.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asistencia_presencia')) {
            Schema::create('asistencia_presencia', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('agente_id')->unique();
                $table->unsignedBigInteger('tienda_id')->nullable();
                $table->string('device_hash', 128);
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->float('accuracy')->nullable();
                $table->string('estado', 20)->default('ok'); // ok|fuera_de_rango|mock_gps
                $table->unsignedTinyInteger('battery_pct')->nullable();
                $table->dateTime('capturado_en');
                $table->dateTime('recibido_en');
                $table->timestamps();

                $table->index('tienda_id', 'idx_presencia_tienda');
            });
        }

        if (! Schema::hasTable('asistencia_incidencias_ubicacion')) {
            Schema::create('asistencia_incidencias_ubicacion', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('agente_id');
                $table->unsignedBigInteger('tienda_id')->nullable();
                $table->string('device_hash', 128);
                $table->string('tipo', 20); // fuera_de_rango|mock_gps|sin_senal
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->float('accuracy')->nullable();
                $table->float('distancia_metros')->nullable();
                $table->dateTime('capturado_en');
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->index(['agente_id', 'created_at'], 'idx_incidencia_agente_fecha');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_incidencias_ubicacion');
        Schema::dropIfExists('asistencia_presencia');
    }
};
