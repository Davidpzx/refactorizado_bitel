<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// APP-08 — Consentimiento de rastreo de ubicación (DECISIÓN-APP-03: sin historial de
// pings, solo el registro de que el agente aceptó el texto antes de empezar a rastrear).
// Una fila por agente+dispositivo; re-aceptar con una version_texto nueva actualiza la fila.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asistencia_consentimientos_ubicacion')) {
            Schema::create('asistencia_consentimientos_ubicacion', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('agente_id');
                $table->string('device_hash', 128);
                $table->string('version_texto', 20);
                $table->dateTime('aceptado_en');
                $table->timestamps();

                $table->unique(['agente_id', 'device_hash'], 'uq_consentimiento_agente_dispositivo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencia_consentimientos_ubicacion');
    }
};
