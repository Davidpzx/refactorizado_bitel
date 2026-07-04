<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tickets_emitidos') && !Schema::hasColumn('tickets_emitidos', 'venta_id')) {
            Schema::table('tickets_emitidos', function (Blueprint $table) {
                $table->unsignedBigInteger('venta_id')->nullable()->after('id');
                $table->index('venta_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets_emitidos') && Schema::hasColumn('tickets_emitidos', 'venta_id')) {
            Schema::table('tickets_emitidos', function (Blueprint $table) {
                $table->dropIndex(['venta_id']);
                $table->dropColumn('venta_id');
            });
        }
    }
};
