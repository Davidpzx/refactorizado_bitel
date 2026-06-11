<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agentes') && Schema::hasColumn('agentes', 'pin_seguridad')) {
            Schema::table('agentes', function (Blueprint $table) {
                $table->string('pin_seguridad', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Reducir la columna truncaría los PIN ya hasheados.
    }
};
