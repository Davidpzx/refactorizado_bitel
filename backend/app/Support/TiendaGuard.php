<?php

namespace App\Support;

/**
 * Regla única para guards de "un no-admin solo ve recursos de su propia tienda".
 * Fail-closed: un no-admin sin tienda_id nunca pasa, y un recurso sin tienda
 * tampoco hace match laxo (evita el hueco null === null).
 */
class TiendaGuard
{
    public static function bloqueaAcceso(bool $esAdmin, ?string $userTiendaId, ?string ...$recursoTiendas): bool
    {
        if ($esAdmin) {
            return false;
        }

        $userTiendaId = trim((string) $userTiendaId);
        if ($userTiendaId === '') {
            return true;
        }

        foreach ($recursoTiendas as $tienda) {
            $tienda = trim((string) $tienda);
            if ($tienda !== '' && $tienda === $userTiendaId) {
                return false;
            }
        }

        return true;
    }
}
