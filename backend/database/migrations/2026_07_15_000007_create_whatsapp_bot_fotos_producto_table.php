<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_fotos_producto', function (Blueprint $table) {
            $table->id();
            $table->string('producto_nombre', 150)->unique();
            $table->longText('foto_base64');
            $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_fotos_producto');
    }
};
