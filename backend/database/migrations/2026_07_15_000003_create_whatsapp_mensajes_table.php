<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('whatsapp_chats')->cascadeOnDelete();
            $table->enum('direccion', ['in', 'out']);
            $table->string('tipo', 20)->default('texto');
            $table->text('contenido')->nullable();
            $table->string('media_url', 500)->nullable();
            $table->string('wa_message_id', 100)->nullable();
            $table->unsignedBigInteger('enviado_por')->nullable();
            $table->timestamp('timestamp');
            $table->timestamps();

            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensajes');
    }
};