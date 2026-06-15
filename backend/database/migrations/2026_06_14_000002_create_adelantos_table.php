<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('adelantos')) {
            return;
        }

        Schema::create('adelantos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('agente_id')->index();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->string('motivo', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adelantos');
    }
};
