<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * APP-09a — canal de distribución del APK de la app de asistencia.
 *
 * Fila única `id = 1` (igual que `configuracion_empresa`) con los metadatos de
 * la última versión publicada. El archivo .apk en sí vive en
 * storage/app/app-terminal (config('app_terminal.apk_path')), no en la BD —
 * esta tabla solo guarda version + metadatos para no tener que leer el
 * filesystem cada vez que se pide la versión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_terminal_version', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->timestamp('actualizado_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_terminal_version');
    }
};
