<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── inventario_chips ───────────────────────────────────────────────────
        if (!Schema::hasTable('inventario_chips')) {
            Schema::create('inventario_chips', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('tienda_id');
                $table->string('tienda_origen', 20)->comment('Código de la tienda propietaria original');
                $table->string('tipo_chip', 30)->default('FÍSICO');
                $table->integer('stock_actual')->default(0);
                $table->timestamps();

                $table->index('tienda_id');
                $table->index(['tienda_id', 'tienda_origen']);
            });
        }

        // ── traslados_stock (equipos y accesorios) ─────────────────────────────
        if (!Schema::hasTable('traslados_stock')) {
            Schema::create('traslados_stock', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('producto_id');
                $table->string('tienda_origen', 20);
                $table->string('tienda_destino', 20);
                $table->integer('cantidad')->default(1);
                $table->enum('estado', [
                    'PENDIENTE',
                    'PENDIENTE_APROBACION',
                    'CONFIRMADO',
                    'RECHAZADO',
                    'CANCELADO',
                ])->default('PENDIENTE');
                $table->unsignedBigInteger('creado_por');
                $table->string('notas', 200)->nullable();
                $table->string('codigo_lote', 32)->nullable();
                $table->unsignedInteger('enviado_por_id')->nullable();
                $table->string('enviado_dni', 8)->nullable();
                $table->unsignedInteger('confirmado_por_id')->nullable();
                $table->string('confirmado_dni', 8)->nullable();
                $table->string('observacion_recepcion', 200)->nullable();
                $table->string('producto_nombre_snap', 200)->nullable();
                $table->string('imei_serial_snap', 100)->nullable();
                $table->timestamp('fecha_confirmacion')->nullable();
                $table->timestamps();

                $table->index('estado');
                $table->index('tienda_origen');
                $table->index('tienda_destino');
                $table->index('codigo_lote');
            });
        }

        // ── traslados_chips ────────────────────────────────────────────────────
        if (!Schema::hasTable('traslados_chips')) {
            Schema::create('traslados_chips', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('chip_id_origen');
                $table->string('tienda_origen', 20);
                $table->string('tienda_destino', 20);
                $table->integer('cantidad');
                $table->enum('estado', [
                    'PENDIENTE',
                    'PENDIENTE_APROBACION',
                    'CONFIRMADO',
                    'RECHAZADO',
                    'CANCELADO',
                ])->default('PENDIENTE');
                $table->unsignedBigInteger('creado_por');
                $table->string('notas', 200)->nullable();
                $table->unsignedInteger('enviado_por_id')->nullable();
                $table->string('enviado_dni', 8)->nullable();
                $table->unsignedInteger('confirmado_por_id')->nullable();
                $table->string('confirmado_dni', 8)->nullable();
                $table->string('observacion_recepcion', 200)->nullable();
                $table->timestamp('fecha_confirmacion')->nullable();
                $table->timestamps();

                $table->index('estado');
                $table->index('tienda_origen');
                $table->index('tienda_destino');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('traslados_chips');
        Schema::dropIfExists('traslados_stock');
        Schema::dropIfExists('inventario_chips');
    }
};
