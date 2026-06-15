<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('venta_lineas')) {
            Schema::table('venta_lineas', function (Blueprint $table) {
                if (! Schema::hasColumn('venta_lineas', 'es_migracion')) {
                    $table->boolean('es_migracion')->default(false);
                }
                if (! Schema::hasColumn('venta_lineas', 'es_upgrade')) {
                    $table->boolean('es_upgrade')->default(false);
                }
                if (! Schema::hasColumn('venta_lineas', 'plan_anterior')) {
                    $table->decimal('plan_anterior', 10, 2)->nullable();
                }
            });
        }

        if (! Schema::hasTable('venta_chip_movimientos')) {
            Schema::create('venta_chip_movimientos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('venta_id');
                $table->unsignedBigInteger('inventario_chip_id');
                $table->unsignedInteger('cantidad');
                $table->timestamp('created_at')->useCurrent();

                $table->index('venta_id');
                $table->index('inventario_chip_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('venta_chip_movimientos');

        if (Schema::hasTable('venta_lineas')) {
            Schema::table('venta_lineas', function (Blueprint $table) {
                $columnas = array_values(array_filter(
                    ['es_migracion', 'es_upgrade', 'plan_anterior'],
                    fn (string $columna) => Schema::hasColumn('venta_lineas', $columna)
                ));
                if ($columnas !== []) {
                    $table->dropColumn($columnas);
                }
            });
        }
    }
};
