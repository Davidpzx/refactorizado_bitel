<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->integer('agente_id');
            $table->string('tienda_id', 10);
            $table->enum('estado', ['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO'])->default('NUEVO');
            $table->enum('fuente', ['PRESENCIAL', 'WHATSAPP', 'REFERIDO', 'LLAMADA'])->default('PRESENCIAL');
            $table->text('notas')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->index('agente_id');
            $table->index('estado');
            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
