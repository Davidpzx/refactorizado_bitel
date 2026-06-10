<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tiendas')) {
            Schema::table('tiendas', function (Blueprint $table) {
                if (!Schema::hasColumn('tiendas', 'radio_permitido')) {
                    $table->integer('radio_permitido')->default(60)->after('nombre');
                }
                if (!Schema::hasColumn('tiendas', 'lat_centro')) {
                    $table->decimal('lat_centro', 10, 7)->nullable()->after('radio_permitido');
                }
                if (!Schema::hasColumn('tiendas', 'lng_centro')) {
                    $table->decimal('lng_centro', 10, 7)->nullable()->after('lat_centro');
                }
            });
        }
    }

    public function down(): void {}
};
