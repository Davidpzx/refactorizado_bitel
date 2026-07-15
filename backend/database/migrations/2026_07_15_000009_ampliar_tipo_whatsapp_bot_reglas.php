<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El enum original de F5 ('texto','menu') no acepta el nuevo valor 'equipos'
 * de F6. Se reemplaza por un string simple, validado a nivel de app
 * (WhatsAppController::validarBotRegla) en vez de a nivel de columna — evita
 * depender de doctrine/dbal para modificar un enum existente.
 *
 * IMPORTANTE: dropColumn+addColumn borra los datos existentes. En MySQL se
 * usa ALTER TABLE...MODIFY (preserva los valores actuales). El camino de
 * drop+add solo corre en SQLite (usado por los tests con RefreshDatabase,
 * sin datos reales en riesgo), porque SQLite no soporta MODIFY COLUMN.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_bot_reglas MODIFY tipo VARCHAR(20) NOT NULL DEFAULT 'texto'");
            return;
        }

        $filas = DB::table('whatsapp_bot_reglas')->select('id', 'tipo')->get();

        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->string('tipo', 20)->default('texto')->after('nombre');
        });

        foreach ($filas as $fila) {
            DB::table('whatsapp_bot_reglas')->where('id', $fila->id)->update(['tipo' => $fila->tipo]);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE whatsapp_bot_reglas MODIFY tipo ENUM('texto','menu') NOT NULL DEFAULT 'texto'");
            return;
        }

        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->enum('tipo', ['texto', 'menu'])->default('texto')->after('nombre');
        });
    }
};
