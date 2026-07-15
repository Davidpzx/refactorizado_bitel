<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// El backend ya valida unique:agentes,dni en StoreAgenteRequest, pero la tabla
// `agentes` en produccion viene de un dump legacy que puede no tener el indice
// unico a nivel de motor. Si ya existen DNI duplicados (dato legado), el ALTER
// fallaria — por eso se verifica antes y se omite sin romper el deploy,
// dejando registrado en el log que falta limpiar los duplicados manualmente.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('agentes') || !Schema::hasColumn('agentes', 'dni')) {
            return;
        }

        // SHOW INDEX es sintaxis MySQL; en otros drivers (sqlite en tests) se omite
        // la deteccion de indice existente y se confia en el intento de creacion.
        if (DB::connection()->getDriverName() === 'mysql') {
            $indexes = collect(DB::select('SHOW INDEX FROM agentes'))->pluck('Key_name');
            if ($indexes->contains('agentes_dni_unique')) {
                return;
            }
        }

        $duplicados = DB::table('agentes')
            ->select('dni')
            ->whereNotNull('dni')
            ->where('dni', '!=', '')
            ->groupBy('dni')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('dni');

        if ($duplicados->isNotEmpty()) {
            Log::warning('No se pudo agregar el indice unico agentes.dni: existen DNI duplicados en datos legados.', [
                'dnis_duplicados' => $duplicados->all(),
            ]);
            return;
        }

        Schema::table('agentes', function (Blueprint $table) {
            $table->unique('dni', 'agentes_dni_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('agentes')) {
            Schema::table('agentes', function (Blueprint $table) {
                $table->dropUnique('agentes_dni_unique');
            });
        }
    }
};
