<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('venta_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_id');
            $table->string('tipo_item', 20)->default('EQUIPO'); // EQUIPO, ACCESORIO, LINEA, RECARGA
            $table->string('descripcion', 200)->default('');
            $table->string('imei_serial', 60)->nullable();
            $table->string('plan_nombre', 150)->nullable();
            $table->string('tipo_alta', 30)->nullable();
            $table->integer('cantidad')->default(1);
            $table->decimal('precio_unitario', 10, 2)->default(0);
            $table->decimal('comision_unitaria', 10, 2)->default(0);
            $table->string('financiera', 50)->nullable();
            $table->json('detalle_extra')->nullable();

            $table->index('venta_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_items');
    }
};
