<?php

namespace App\Services\Facturacion;

use App\Models\ComprobanteCola;

/** Desenlace de procesar UNA fila de la cola. Lo consumen el comando y el endpoint. */
final readonly class ResultadoProceso
{
    public function __construct(
        public string $resultado,
        public ComprobanteCola $cola,
        public string $mensaje = '',
    ) {
    }

    public function es(string $resultado): bool
    {
        return $this->resultado === $resultado;
    }
}
