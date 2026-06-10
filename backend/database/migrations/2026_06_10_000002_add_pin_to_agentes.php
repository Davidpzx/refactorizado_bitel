<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agentes', function (Blueprint $table) {
            if (!Schema::hasColumn('agentes', 'pin_seguridad')) {
                $table->string('pin_seguridad', 4)->nullable();
            }
            if (!Schema::hasColumn('agentes', 'hash_dispositivo')) {
                $table->string('hash_dispositivo', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agentes', function (Blueprint $table) {
            $table->dropColumn(['pin_seguridad', 'hash_dispositivo']);
        });
    }
};
