<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->boolean('usa_promocion_dinamica')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_bot_reglas', fn (Blueprint $t) => $t->dropColumn('usa_promocion_dinamica'));
    }
};
