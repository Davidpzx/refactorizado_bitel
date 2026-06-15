<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('usuarios') || Schema::hasColumn('usuarios', 'agente_id')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->unsignedBigInteger('agente_id')->nullable()->index()->after('tienda_id');
        });

        $usuarios = DB::table('usuarios')->select('id', 'nombre', 'email', 'tienda_id')->get();
        $tieneDni = Schema::hasColumn('usuarios', 'dni');
        $tieneCorreo = Schema::hasColumn('agentes', 'correo');

        foreach ($usuarios as $usuario) {
            $candidatos = DB::table('agentes')->select('id');

            if ($tieneDni) {
                $dni = trim((string) DB::table('usuarios')->where('id', $usuario->id)->value('dni'));
                if ($dni !== '') {
                    $candidatos->whereRaw('TRIM(dni) = ?', [$dni]);
                } elseif ($tieneCorreo && trim((string) $usuario->email) !== '') {
                    $candidatos->whereRaw('LOWER(TRIM(correo)) = ?', [mb_strtolower(trim((string) $usuario->email))]);
                } else {
                    $this->filtrarPorNombreYTienda($candidatos, $usuario);
                }
            } elseif ($tieneCorreo && trim((string) $usuario->email) !== '') {
                $candidatos->whereRaw('LOWER(TRIM(correo)) = ?', [mb_strtolower(trim((string) $usuario->email))]);
            } else {
                $this->filtrarPorNombreYTienda($candidatos, $usuario);
            }

            $ids = $candidatos->limit(2)->pluck('id');
            if ($ids->count() === 1) {
                DB::table('usuarios')->where('id', $usuario->id)->update(['agente_id' => $ids->first()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('usuarios') && Schema::hasColumn('usuarios', 'agente_id')) {
            Schema::table('usuarios', function (Blueprint $table) {
                $table->dropColumn('agente_id');
            });
        }
    }

    private function filtrarPorNombreYTienda($query, object $usuario): void
    {
        $query->whereRaw('LOWER(TRIM(nombres)) = ?', [mb_strtolower(trim((string) $usuario->nombre))]);

        if (trim((string) $usuario->tienda_id) !== '') {
            $query->where('tienda_base', $usuario->tienda_id);
        }
    }
};
