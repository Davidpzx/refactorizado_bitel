<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tickets_emitidos')) {
            Schema::create('tickets_emitidos', function (Blueprint $table) {
                $table->id();
                $table->string('tienda_id', 20)->default('');
                $table->integer('agente_id')->default(0);
                $table->string('vendedor', 100)->default('');
                $table->string('nombre_cliente', 150)->default('');
                $table->string('dni_cliente', 20)->default('');
                $table->string('forma_pago', 50)->default('Efectivo');
                $table->string('telefono', 20)->default('');
                $table->text('descripcion');
                $table->decimal('monto', 10, 2)->default(0);
                $table->integer('cantidad')->default(1);
                $table->decimal('efectivo', 10, 2)->default(0);
                $table->decimal('yape', 10, 2)->default(0);
                $table->decimal('bipay', 10, 2)->default(0);
                $table->decimal('transferencia', 10, 2)->default(0);
                $table->decimal('vuelto', 10, 2)->default(0);
                $table->timestamp('creado_en')->useCurrent();
                $table->timestamps();

                $table->index('tienda_id');
                $table->index('agente_id');
                $table->index('creado_en');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets_emitidos');
    }
};
