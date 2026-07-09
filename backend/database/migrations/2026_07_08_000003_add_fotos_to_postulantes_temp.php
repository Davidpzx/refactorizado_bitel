<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('postulantes_temp', function (Blueprint $table) {
            if (! Schema::hasColumn('postulantes_temp', 'foto_perfil')) {
                $table->longText('foto_perfil')->nullable()->after('experiencia_laboral');
            }
            if (! Schema::hasColumn('postulantes_temp', 'foto_dni')) {
                $table->longText('foto_dni')->nullable()->after('foto_perfil');
            }
        });
    }

    public function down(): void
    {
        Schema::table('postulantes_temp', function (Blueprint $table) {
            $table->dropColumn(['foto_perfil', 'foto_dni']);
        });
    }
};
