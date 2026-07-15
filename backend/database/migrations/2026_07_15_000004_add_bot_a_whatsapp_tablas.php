<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_cuentas', function (Blueprint $table) {
            $table->boolean('bot_activo')->default(false);
        });
        Schema::table('whatsapp_chats', function (Blueprint $table) {
            $table->integer('interes_score')->default(0);
            $table->dateTime('bot_silenciado_hasta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_cuentas', fn (Blueprint $t) => $t->dropColumn('bot_activo'));
        Schema::table('whatsapp_chats', fn (Blueprint $t) => $t->dropColumn(['interes_score', 'bot_silenciado_hasta']));
    }
};
