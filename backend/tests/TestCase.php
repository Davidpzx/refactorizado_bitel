<?php

namespace Tests;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function vendedorVinculado(string $tienda = 'PUNDA50'): Usuario
    {
        $agenteId = 1000 + DB::table('usuarios')->count() + 1;

        DB::table('agentes')->insert([
            'id' => $agenteId,
            'nombres' => 'Agente vinculado '.$agenteId,
            'estado' => 'ACTIVO',
            'tienda_base' => $tienda,
            'dni' => str_pad((string) $agenteId, 8, '0', STR_PAD_LEFT),
        ]);

        $usuario = Usuario::factory()->vendedor($tienda)->create([
            'agente_id' => $agenteId,
        ]);

        DB::table('asistencias')->insert([
            'agente_id' => $agenteId,
            'tienda_id' => $tienda,
            'fecha' => now()->toDateString(),
            'hora_ingreso' => '08:00:00',
            'hora_salida' => null,
        ]);

        return $usuario;
    }
}
