<?php

namespace App\Services;

use App\Models\Agente;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AgenteService
{
    public function crear(array $datos): Agente
    {
        $datos['pin_seguridad'] = Hash::make($datos['pin_seguridad']);
        $agente = Agente::create($datos);
        Log::info('agente.creado', ['id' => $agente->id, 'dni' => $agente->dni]);
        return $agente;
    }

    public function actualizar(Agente $agente, array $datos): Agente
    {
        if (!empty($datos['pin_seguridad'])) {
            $datos['pin_seguridad'] = Hash::make($datos['pin_seguridad']);
        } else {
            unset($datos['pin_seguridad']);
        }
        $agente->update($datos);
        Log::info('agente.actualizado', ['id' => $agente->id]);
        return $agente->fresh();
    }
}
