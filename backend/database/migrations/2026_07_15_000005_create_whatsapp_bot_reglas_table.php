<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_bot_reglas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->nullable()->constrained('whatsapp_cuentas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->enum('tipo', ['texto', 'menu'])->default('texto');
            $table->boolean('es_bienvenida')->default(false);
            $table->json('palabras_clave')->nullable();
            $table->text('respuesta')->nullable();
            $table->string('menu_titulo', 150)->nullable();
            $table->json('opciones')->nullable();
            $table->integer('prioridad')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_bot_reglas');
    }
};
