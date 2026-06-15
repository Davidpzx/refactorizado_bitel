<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ventas') || Schema::hasColumn('ventas', 'chips_descontados')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            // Nº de chips descontados de inventario_chips al guardar la venta.
            // Se persiste para revertir con exactitud al editar el reporte (reprocesar),
            // ya que tipo_alta/migracion/upgrade/esim no bastan para recomputar el descuento.
            $table->unsignedInteger('chips_descontados')->default(0)->after('es_extranjero');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas') && Schema::hasColumn('ventas', 'chips_descontados')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->dropColumn('chips_descontados');
            });
        }
    }
};
