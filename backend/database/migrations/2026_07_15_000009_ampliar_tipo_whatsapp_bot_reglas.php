<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El enum original de F5 ('texto','menu') quedo como CHECK constraint fijo en
 * SQLite (usado en tests) y no acepta el nuevo valor 'equipos' de F6. Se
 * reemplaza por un string simple con el mismo default, validado a nivel de
 * app (WhatsAppController::validarBotRegla) en vez de a nivel de columna —
 * evita depender de doctrine/dbal para modificar un enum existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->string('tipo', 20)->default('texto')->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->enum('tipo', ['texto', 'menu'])->default('texto')->after('nombre');
        });
    }
};
