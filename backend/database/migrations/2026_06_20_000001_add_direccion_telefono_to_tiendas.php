<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tiendas')) {
            Schema::table('tiendas', function (Blueprint $table) {
                if (!Schema::hasColumn('tiendas', 'direccion')) {
                    $table->string('direccion', 200)->nullable()->after('nombre');
                }
                if (!Schema::hasColumn('tiendas', 'telefono')) {
                    $table->string('telefono', 20)->nullable()->after('direccion');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tiendas')) {
            Schema::table('tiendas', function (Blueprint $table) {
                if (Schema::hasColumn('tiendas', 'telefono')) {
                    $table->dropColumn('telefono');
                }
                if (Schema::hasColumn('tiendas', 'direccion')) {
                    $table->dropColumn('direccion');
                }
            });
        }
    }
};
