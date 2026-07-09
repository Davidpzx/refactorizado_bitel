<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteCola;
use App\Services\Facturacion\ProcesadorColaComprobantes;
use App\Services\Facturacion\ResultadoProceso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * "Emitir ahora": el cajero necesita la boleta en la mano, no dentro de un minuto.
 *
 * Port de `reportes/ajax_emitir_ahora.php` (legacy). No es un camino de emisión
 * paralelo: encola (idempotente) y luego procesa ESA fila con el mismo
 * `ProcesadorColaComprobantes` que usa el cron. Si la API está caída, la fila
 * queda en la cola con su backoff y el cron la emitirá; el cajero solo pierde la
 * inmediatez, nunca el comprobante.
 */
class ComprobanteColaController extends Controller
{
    public function __construct(private readonly ProcesadorColaComprobantes $procesador)
    {
    }

    public function emitirAhora(Request $request): JsonResponse
    {
        $resultado = $this->procesador->procesar($this->filaDe($request));

        return response()->json($this->respuesta($resultado));
    }

    /** La fila ya encolada (`cola_id`), o una recién encolada con los datos del request. */
    private function filaDe(Request $request): ComprobanteCola
    {
        $datos = $request->validate([
            'cola_id' => ['sometimes', 'integer', 'exists:comprobantes_cola,id'],

            'tipo_comprobante' => ['required_without:cola_id', Rule::in([
                ComprobanteCola::TIPO_BOLETA,
                ComprobanteCola::TIPO_FACTURA,
                ComprobanteCola::TIPO_NOTA_CREDITO,
            ])],
            'payload' => ['required_without:cola_id', 'array'],

            'venta_id'          => ['nullable', 'integer'],
            'reporte_id'        => ['nullable', 'integer'],
            'ticket_id'         => ['nullable', 'integer'],
            'tienda_id'         => ['nullable', 'string', 'max:20'],
            'agente_id'         => ['nullable', 'integer'],
            'tipo_doc_cliente'  => ['nullable', 'string', 'max:1'],
            'num_doc_cliente'   => ['nullable', 'string', 'max:20'],
            'razon_social'      => ['nullable', 'string', 'max:200'],
            'direccion_cliente' => ['nullable', 'string', 'max:255'],
            'email_cliente'     => ['nullable', 'string', 'email', 'max:120'],
            'moneda'            => ['nullable', 'string', 'size:3'],
            'total'             => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($datos['cola_id'])) {
            return ComprobanteCola::findOrFail($datos['cola_id']);
        }

        // Encolar primero y emitir después no es una formalidad: si el proceso muere
        // entre ambos pasos, la fila ya existe y el cron la recoge.
        return ComprobanteCola::encolar(Arr::except($datos, ['cola_id']));
    }

    /** @return array<string, mixed> */
    private function respuesta(ResultadoProceso $resultado): array
    {
        $cola = $resultado->cola;

        $identidad = [
            'cola_id'         => $cola->getKey(),
            'estado'          => $cola->estado,
            'api_doc_id'      => $cola->api_doc_id,
            'serie'           => $cola->serie,
            'correlativo'     => $cola->correlativo,
            'numero_completo' => $cola->numero_completo,
        ];

        return match ($resultado->resultado) {
            ProcesadorColaComprobantes::ACEPTADA => ['ok' => true] + $identidad,

            ProcesadorColaComprobantes::YA_EMITIDA => ['ok' => true, 'ya_emitido' => true] + $identidad,

            // Sigue en la cola: el cron lo reintentará. Para el cajero no es un error
            // que deba resolver, así que la respuesta es 200 con `encolado`.
            ProcesadorColaComprobantes::REINTENTAR => [
                'ok'       => false,
                'encolado' => true,
                'msg'      => 'La API de facturación no respondió; se emitirá automáticamente en breve.',
                'detalle'  => $resultado->mensaje,
            ] + $identidad,

            ProcesadorColaComprobantes::SIN_CONFIG => [
                'ok'       => false,
                'encolado' => true,
                'msg'      => 'Facturación no configurada o inactiva; quedó en cola.',
            ] + $identidad,

            ProcesadorColaComprobantes::SALTADA => [
                'ok'       => false,
                'encolado' => true,
                'msg'      => 'El comprobante está siendo emitido por otro proceso.',
            ] + $identidad,

            // Rechazo definitivo de SUNAT o de la API: no se reintenta.
            default => ['ok' => false, 'msg' => $resultado->mensaje] + $identidad,
        };
    }
}
