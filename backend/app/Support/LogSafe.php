<?php

namespace App\Support;

/**
 * Enmascara PII antes de escribirla en logs de aplicación (SEC-08).
 * Los logs tienen menos control de acceso que la BD y se copian a backups;
 * no deben acumular DNIs en texto plano salvo un log de auditoría explícito
 * (p. ej. DniController::consultar, que registra la consulta a propósito).
 */
class LogSafe
{
    /** '12345678' -> '****5678'. Valores no numéricos/cortos se enmascaran por completo. */
    public static function dni(?string $dni): string
    {
        if ($dni === null || strlen($dni) < 4) {
            return '****';
        }

        return '****'.substr($dni, -4);
    }
}
