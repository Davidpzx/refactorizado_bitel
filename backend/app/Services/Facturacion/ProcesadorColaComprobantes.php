<?php

namespace App\Services\Facturacion;

use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Procesa UNA fila de `comprobantes_cola` contra la API externa de facturación.
 *
 * Es el único camino de emisión del sistema. El cron (`facturacion:procesar-cola`)
 * lo llama en lote; el endpoint "emitir ahora" lo llama para una sola fila. No hay
 * un segundo camino síncrono: la diferencia entre ambos es *quién* dispara, no
 * *qué* ocurre. Así, un "emitir ahora" que falla por causa transitoria deja la fila
 * exactamente donde el cron la va a recoger. Nunca se pierde una fila.
 *
 * Port de `cron/procesar_cola_comprobantes.php` + `reportes/ajax_emitir_ahora.php`,
 * que en el legacy eran dos copias del mismo bloque de código.
 */
class ProcesadorColaComprobantes
{
    /** SUNAT la aceptó. Terminal. */
    public const ACEPTADA = 'aceptada';
    /** Ya estaba aceptada antes de empezar: no se reemite. */
    public const YA_EMITIDA = 'ya_emitida';
    /** Rechazo definitivo (SUNAT o la API). Terminal. */
    public const RECHAZADA = 'rechazada';
    /** Falla transitoria: vuelve a la cola con backoff. */
    public const REINTENTAR = 'reintentar';
    /** Otro worker la tomó, o está en un estado terminal distinto de ACEPTADO. */
    public const SALTADA = 'saltada';
    /** El emisor no está configurado/activo: la fila vuelve intacta a PENDIENTE. */
    public const SIN_CONFIG = 'sin_config';

    public function procesar(ComprobanteCola $cola): ResultadoProceso
    {
        if ($cola->estaAceptado()) {
            return new ResultadoProceso(self::YA_EMITIDA, $cola, 'El comprobante ya fue aceptado por SUNAT.');
        }

        // El lock optimista es también el filtro de estado: solo PENDIENTE y ERROR
        // pasan a ENVIADO, y solo un proceso gana la carrera.
        if (!$cola->reclamar()) {
            return new ResultadoProceso(self::SALTADA, $cola, 'El comprobante está en proceso o en un estado terminal.');
        }

        try {
            return $this->emitir($cola);
        } catch (\Throwable $e) {
            Log::error('[cola-cpe] fallo al procesar la fila', [
                'cola_id'  => $cola->getKey(),
                'mensaje'  => $e->getMessage(),
            ]);

            // La fila NO se queda colgada en ENVIADO: vuelve a ERROR con backoff.
            $this->reintentarSeguro($cola, 'Excepción al emitir: '.$e->getMessage());

            return new ResultadoProceso(self::REINTENTAR, $cola, $e->getMessage());
        }
    }

    private function emitir(ComprobanteCola $cola): ResultadoProceso
    {
        $config = $cola->resolverConfig();

        if ($config === null || !$config->estaOperativa()) {
            // Sin emisor no se puede ni intentar: no gasta un intento ni programa
            // backoff. Vuelve a PENDIENTE y espera a que alguien configure la API.
            $cola->forceFill(['estado' => ComprobanteCola::ESTADO_PENDIENTE])->save();

            return new ResultadoProceso(self::SIN_CONFIG, $cola, 'Facturación no configurada o inactiva; quedó en cola.');
        }

        $resultado = $this->cliente($config)->emitir(
            $cola->tipo_comprobante,
            $this->documento($cola, $config),
            $cola->api_doc_id,
        );

        if ($resultado->aceptado) {
            $this->aceptar($cola, $config, $resultado);

            return new ResultadoProceso(self::ACEPTADA, $cola, $resultado->mensaje);
        }

        // Guardar el id del documento creado permite reanudar el `send-sunat` en el
        // próximo intento en vez de crear un documento nuevo en la API.
        if ($resultado->apiDocId() !== null) {
            $cola->forceFill(['api_doc_id' => $resultado->apiDocId()]);
        }

        if ($resultado->reintentable) {
            $cola->registrarError($resultado->mensaje);

            return new ResultadoProceso(self::REINTENTAR, $cola, $resultado->mensaje);
        }

        $cola->marcarRechazado($resultado->mensaje);

        return new ResultadoProceso(self::RECHAZADA, $cola, $resultado->mensaje);
    }

    /**
     * Persiste la aceptación junto con el emisor que la produjo, para que el índice
     * único `(emisor, serie, correlativo)` proteja de verdad contra el doble número.
     *
     * Si la API devolviera un número ya usado, ese índice haría fallar el guardado.
     * Perder el hecho fiscal —"SUNAT lo aceptó"— sería mucho peor que perder el
     * número: se guarda el estado sin serie/correlativo y se grita en los logs.
     */
    private function aceptar(ComprobanteCola $cola, FacturacionConfig $config, ResultadoEmision $resultado): void
    {
        try {
            $cola->forceFill(['facturacion_config_id' => $config->getKey()])->marcarAceptado($resultado->campos);
        } catch (UniqueConstraintViolationException) {
            Log::critical('[cola-cpe] la API devolvió un número de comprobante ya usado', [
                'cola_id'     => $cola->getKey(),
                'serie'       => $resultado->campos['serie'] ?? null,
                'correlativo' => $resultado->campos['correlativo'] ?? null,
                'emisor_id'   => $config->getKey(),
            ]);

            $cola->refresh()
                ->forceFill(['facturacion_config_id' => $config->getKey()])
                ->marcarAceptado(Arr::except($resultado->campos, ['serie', 'correlativo']));

            $cola->forceFill(['ultimo_error' => 'Aceptado por SUNAT, pero la API devolvió un número duplicado.'])->save();
        }
    }

    /** Registrar el error no puede tumbar al worker: si también falla, se anota y sigue. */
    private function reintentarSeguro(ComprobanteCola $cola, string $mensaje): void
    {
        try {
            $cola->registrarError($mensaje);
        } catch (\Throwable $e) {
            Log::error('[cola-cpe] fallo también al devolver la fila a la cola', [
                'cola_id' => $cola->getKey(),
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Snapshot fiscal congelado + datos del emisor resueltos AHORA.
     *
     * El orden del merge importa: `company_id`, `branch_id`, `serie` y el ubigeo
     * salen siempre de `FacturacionConfig` y pisan cualquier valor que traiga el
     * payload encolado. Si no, un payload viejo podría emitir contra el emisor 1/1
     * por defecto —es decir, contra el RUC equivocado—.
     *
     * @return array<string, mixed>
     */
    private function documento(ComprobanteCola $cola, FacturacionConfig $config): array
    {
        return array_merge(is_array($cola->payload) ? $cola->payload : [], [
            'ticket_id'           => $cola->ticket_id,
            'tienda_id'           => $cola->tienda_id,
            'agente_id'           => $cola->agente_id,
            'tipo_doc_cliente'    => $cola->tipo_doc_cliente,
            'num_doc_cliente'     => $cola->num_doc_cliente,
            'razon_social'        => $cola->razon_social,
            'direccion_cliente'   => $cola->direccion_cliente,
            'email_cliente'       => $cola->email_cliente,
            'moneda'              => $cola->moneda,
            'total'               => $cola->total,
            'company_id'          => $config->company_id,
            'branch_id'           => $config->branch_id,
            'serie'               => $cola->serieSegunTipo($config),
            'igv_porcentaje'      => (float) $config->igv_porcentaje,
            'emisor_ubigeo'       => $config->emisor_ubigeo,
            'emisor_distrito'     => $config->emisor_distrito,
            'emisor_provincia'    => $config->emisor_provincia,
            'emisor_departamento' => $config->emisor_departamento,
        ]);
    }

    /** Punto de extensión para los tests: un cliente por emisor, no un singleton. */
    protected function cliente(FacturacionConfig $config): FacturacionApiClient
    {
        return new FacturacionApiClient($config);
    }
}
