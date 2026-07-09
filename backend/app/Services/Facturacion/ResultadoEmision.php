<?php

namespace App\Services\Facturacion;

/**
 * Resultado de emitir un CPE contra la API externa de facturación.
 *
 * Tres desenlaces posibles, y solo tres. Los nombra el constructor que se usó:
 *  - `aceptado()`     → SUNAT lo aceptó. Terminal.
 *  - `rechazado()`    → SUNAT o la API lo rechazaron. Terminal, NO se reintenta.
 *  - `reintentable()` → falla transitoria (red, 5xx, respuesta ilegible). Backoff.
 *
 * La distinción retryable / no-retryable es la pieza crítica: reintentar un
 * rechazo definitivo quema números de serie e insiste contra SUNAT sin remedio;
 * NO reintentar una caída de red pierde un comprobante que el cliente ya pagó.
 */
final readonly class ResultadoEmision
{
    /** @param array<string, mixed> $campos Subconjunto de columnas de `comprobantes_cola`. */
    private function __construct(
        public bool $aceptado,
        public bool $reintentable,
        public string $mensaje,
        public int $httpCode = 0,
        public array $campos = [],
    ) {
    }

    /** @param array<string, mixed> $campos */
    public static function aceptado(string $mensaje, array $campos = [], int $httpCode = 0): self
    {
        return new self(true, false, $mensaje, $httpCode, $campos);
    }

    /** @param array<string, mixed> $campos */
    public static function rechazado(string $mensaje, int $httpCode = 0, array $campos = []): self
    {
        return new self(false, false, $mensaje, $httpCode, $campos);
    }

    /** @param array<string, mixed> $campos */
    public static function reintentable(string $mensaje, int $httpCode = 0, array $campos = []): self
    {
        return new self(false, true, $mensaje, $httpCode, $campos);
    }

    /**
     * El id que la API asignó al documento, si el paso 1 llegó a crearlo.
     *
     * Viene incluso en los resultados fallidos: si el paso 2 falló, la fila guarda
     * este id para reanudar el envío en vez de crear un documento duplicado.
     */
    public function apiDocId(): ?int
    {
        return isset($this->campos['api_doc_id']) ? (int) $this->campos['api_doc_id'] : null;
    }
}
