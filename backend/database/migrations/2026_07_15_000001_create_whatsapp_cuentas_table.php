<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100);
            $table->string('numero', 20);
            $table->string('instancia', 100)->unique();
            $table->string('provider', 20)->default('evolution');
            $table->string('tienda_id', 10)->nullable();
            $table->string('estado', 20)->default('qr_pendiente');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_cuentas');
    }
};