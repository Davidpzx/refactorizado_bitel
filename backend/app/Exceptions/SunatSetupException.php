<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Fallo esperado del flujo `configure-sunat`. El mensaje está redactado para el
 * admin que lo va a leer en pantalla y NUNCA contiene secretos (contraseña del
 * certificado, clave SOL, api_token).
 */
class SunatSetupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
