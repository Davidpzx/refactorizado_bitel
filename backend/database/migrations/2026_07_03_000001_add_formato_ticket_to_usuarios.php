<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usuarios') && !Schema::hasColumn('usuarios', 'formato_ticket')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->enum('formato_ticket', ['58', '80'])->default('80')->after('activo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'formato_ticket')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->dropColumn('formato_ticket');
            });
        }
    }
};
