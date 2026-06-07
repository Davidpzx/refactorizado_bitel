<?php

namespace App\Jobs;

use App\Models\Comprobante;
use App\Services\GreenterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnviarComprobanteSunat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Máximo 10 intentos con backoff exponencial.
     * En producción la queue debe ser Redis:  QUEUE_CONNECTION=redis
     * Levantar worker:  php artisan queue:work redis --queue=sunat --tries=10
     */
    public int $tries   = 10;
    public int $timeout = 60;

    public function __construct(public readonly int $comprobanteId) {}

    /**
     * Backoff en segundos entre reintentos: 1min → 5min → 15min → 30min → 1h
     * Se reutiliza cíclicamente a partir del quinto intento.
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(GreenterService $greenter): void
    {
        $comprobante = Comprobante::findOrFail($this->comprobanteId);

        // Idempotencia: si ya fue aceptado, salir sin error
        if ($comprobante->estaAceptado()) {
            return;
        }

        $comprobante->increment('intentos');

        $resultado = $greenter->enviarComprobante($comprobante);

        $comprobante->update([
            'estado_sunat'    => $resultado['estado_sunat'],
            'xml_path'        => $resultado['xml_path'],
            'cdr_path'        => $resultado['cdr_path'],
            'hash_cpe'        => $resultado['hash_cpe'],
            'mensaje_sunat'   => $resultado['mensaje_sunat'],
            'proximo_intento' => null,
        ]);

        // Si SUNAT rechazó, programar próximo reintento y re-encolar
        if ($resultado['estado_sunat'] === 'ERROR') {
            $backoffSeg = $this->backoff()[min($this->attempts() - 1, 4)];
            $comprobante->update(['proximo_intento' => now()->addSeconds($backoffSeg)]);
            $this->release($backoffSeg);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("EnviarComprobanteSunat #{$this->comprobanteId} agotó reintentos: " . $e->getMessage(), [
            'comprobante_id' => $this->comprobanteId,
            'exception'      => $e,
        ]);

        Comprobante::where('id', $this->comprobanteId)->update([
            'estado_sunat'  => 'ERROR',
            'mensaje_sunat' => 'Job agotó reintentos: ' . substr($e->getMessage(), 0, 450),
        ]);
    }

    /** Etiquetas para Horizon / queue:monitor */
    public function tags(): array
    {
        return ["comprobante:{$this->comprobanteId}"];
    }
}
