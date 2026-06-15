<?php

namespace App\Services;

use App\Models\Agente;
use App\Models\Usuario;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserAgentResolver
{
    public function resolve(Usuario $usuario): ?Agente
    {
        if (Schema::hasColumn('usuarios', 'agente_id') && $usuario->agente_id) {
            $agente = Agente::find($usuario->agente_id);
            if ($agente) {
                return $agente;
            }
        }

        if (Schema::hasColumn('usuarios', 'dni')) {
            $dni = trim((string) $usuario->getAttribute('dni'));
            if ($dni !== '') {
                $agente = Agente::whereRaw('TRIM(dni) = ?', [$dni])->first();
                if ($agente) {
                    $this->persistirVinculo($usuario, $agente);
                    return $agente;
                }
            }
        }

        if (Schema::hasColumn('agentes', 'correo') && trim((string) $usuario->email) !== '') {
            $agentes = Agente::query()
                ->whereRaw('LOWER(TRIM(correo)) = ?', [mb_strtolower(trim((string) $usuario->email))])
                ->limit(2)
                ->get();

            if ($agentes->count() === 1) {
                $this->persistirVinculo($usuario, $agentes->first());
                return $agentes->first();
            }
        }

        return null;
    }

    public function resolveOrFail(Usuario $usuario): Agente
    {
        $agente = $this->resolve($usuario);

        if (! $agente) {
            throw new HttpException(
                422,
                'El usuario autenticado no está vinculado a un agente. Un administrador debe asignar usuarios.agente_id.'
            );
        }

        return $agente;
    }

    private function persistirVinculo(Usuario $usuario, Agente $agente): void
    {
        if (! Schema::hasColumn('usuarios', 'agente_id')) {
            return;
        }

        $usuario->forceFill(['agente_id' => $agente->id])->save();
    }
}
