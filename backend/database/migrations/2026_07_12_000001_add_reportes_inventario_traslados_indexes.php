<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> */
    private array $indexes = [
        'reportes' => [
            'idx_reportes_tienda_fecha_id' => ['tienda_id', 'fecha', 'id'],
            'idx_reportes_estado_fecha_id' => ['estado', 'fecha', 'id'],
        ],
        'inventario_tiendas' => [
            'idx_inventario_tienda_estado_tipo' => ['tienda_id', 'estado', 'tipo'],
            // producto_nombre is VARCHAR(150): this composite uses at most
            // 880 bytes in utf8mb4, below InnoDB's 3072-byte limit.
            'idx_inventario_tienda_producto_tipo_estado' => ['tienda_id', 'producto_nombre', 'tipo', 'estado'],
        ],
        'traslados_stock' => [
            'idx_traslados_stock_lote_estado_id' => ['codigo_lote', 'estado', 'id'],
        ],
        'interacciones_crm' => [
            'idx_interacciones_lead_fecha_id' => ['lead_id', 'fecha', 'id'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
                        $blueprint->index($columns, $name);
                    });
                }
            }
        }

        // traslados_chips has no codigo_lote column, so the batch index does
        // not apply to that table.
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($name) {
                        $blueprint->dropIndex($name);
                    });
                }
            }
        }
    }
};
