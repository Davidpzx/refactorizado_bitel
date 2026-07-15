<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('whatsapp_cuentas')->cascadeOnDelete();
            $table->string('jid', 60);
            $table->string('nombre_contacto', 150)->nullable();
            $table->string('numero_contacto', 20)->nullable();
            $table->unsignedBigInteger('crm_cliente_id')->nullable();
            $table->timestamp('ultimo_mensaje_at')->nullable();
            $table->unsignedInteger('no_leidos')->default(0);
            $table->timestamps();

            $table->unique(['cuenta_id', 'jid']);
            $table->index('numero_contacto');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_chats');
    }
};