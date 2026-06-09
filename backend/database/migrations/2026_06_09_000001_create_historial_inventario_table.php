<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historial_inventario')) {
            return;
        }

        Schema::create('historial_inventario', function (Blueprint $table) {
            $table->id();
            $table->string('tienda_id', 20)->index();
            $table->unsignedInteger('agente_id')->nullable();
            $table->unsignedBigInteger('producto_id')->nullable();
            $table->enum('accion', ['SUMA', 'RESTA'])->default('SUMA');
            $table->integer('cantidad')->default(1);
            $table->string('motivo', 255)->nullable();
            $table->text('observacion')->nullable();
            $table->timestamp('fecha_hora')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_inventario');
    }
};
