<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            // Paridad legacy (confirmar_desembolso.php): quién y cuándo confirmó el desembolso.
            if (! Schema::hasColumn('ventas', 'desembolso_confirmado_por')) {
                $table->unsignedBigInteger('desembolso_confirmado_por')->nullable()->after('comision_estado');
            }
            if (! Schema::hasColumn('ventas', 'desembolso_confirmado_en')) {
                $table->timestamp('desembolso_confirmado_en')->nullable()->after('desembolso_confirmado_por');
            }
        });

        if (! $this->hasIndex('ventas', 'ventas_desembolso_confirmado_en_index')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->index('desembolso_confirmado_en');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ventas')) {
            return;
        }

        if ($this->hasIndex('ventas', 'ventas_desembolso_confirmado_en_index')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->dropIndex('ventas_desembolso_confirmado_en_index');
            });
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'desembolso_confirmado_en')) {
                $table->dropColumn('desembolso_confirmado_en');
            }
            if (Schema::hasColumn('ventas', 'desembolso_confirmado_por')) {
                $table->dropColumn('desembolso_confirmado_por');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::getIndexes($table);
        foreach ($indexes as $index) {
            if ($index['name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
};
