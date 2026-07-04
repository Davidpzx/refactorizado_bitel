<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // cuentas_bipay no se crea por migración en este repo (viene de la migración del
        // legacy Vitaltel/DASAM); se agrega la columna solo si la tabla existe y falta,
        // mismo patrón defensivo que 2026_07_02_000001_create_integrador_bitel_tables.php.
        if (Schema::hasTable('cuentas_bipay') && ! Schema::hasColumn('cuentas_bipay', 'razon_social')) {
            Schema::table('cuentas_bipay', fn (Blueprint $t) => $t->string('razon_social', 150)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cuentas_bipay') && Schema::hasColumn('cuentas_bipay', 'razon_social')) {
            Schema::table('cuentas_bipay', fn (Blueprint $t) => $t->dropColumn('razon_social'));
        }
    }
};
