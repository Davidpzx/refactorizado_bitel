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
        Schema::create('interacciones_crm', function (Blueprint $table) {
            $table->id()->unsigned();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->unsignedBigInteger('cliente_id')->nullable();
            $table->integer('agente_id');
            $table->enum('tipo', ['LLAMADA', 'VISITA', 'WHATSAPP', 'VENTA', 'POSTVENTA']);
            $table->text('detalle')->nullable();
            $table->timestamp('fecha')->useCurrent();

            $table->index('cliente_id');
            $table->index('agente_id');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interacciones_crm');
    }
};
