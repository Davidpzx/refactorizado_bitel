<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_id');
            $table->enum('tipo_comprobante', ['03', '01'])->default('03'); // 03=Boleta, 01=Factura
            $table->char('serie', 4);
            $table->unsignedInteger('numero');
            $table->enum('estado_sunat', ['PENDIENTE', 'ENVIADO', 'ACEPTADO', 'RECHAZADO', 'ANULADO'])->default('PENDIENTE');
            $table->string('xml_path', 255)->nullable();
            $table->string('cdr_path', 255)->nullable();
            $table->string('hash_cpe', 100)->nullable();
            $table->text('mensaje_sunat')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamp('proximo_intento')->nullable();
            $table->timestamp('creado_en')->useCurrent();

            $table->unique(['tipo_comprobante', 'serie', 'numero'], 'uk_cpe');
            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
