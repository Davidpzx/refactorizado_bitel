<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteCola;
use App\Models\FacturacionConfig;
use App\Models\Usuario;
use App\Services\Facturacion\CpeLinkService;
use App\Services\Facturacion\FacturacionApiClient;
use App\Services\Facturacion\ProcesadorColaComprobantes;
use App\Services\Facturacion\ResultadoProceso;
use App\Support\Paginacion;
use App\Support\TiendaGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Listado de la cola con filtros. Port de `gerencia/comprobantes_emitidos.php`.
     *
     * A diferencia del legacy (LIMIT 200 sin paginar), esto pagina de verdad: la
     * cola crece sin techo y el gerente necesita poder ir hacia atrás en el tiempo.
     */
    public function index(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'estado' => ['sometimes', 'string', Rule::in([
                ComprobanteCola::ESTADO_PENDIENTE,
                ComprobanteCola::ESTADO_ENVIADO,
                ComprobanteCola::ESTADO_ACEPTADO,
                ComprobanteCola::ESTADO_RECHAZADO,
                ComprobanteCola::ESTADO_ANULADO,
                ComprobanteCola::ESTADO_ERROR,
            ])],
            'tipo_comprobante' => ['sometimes', 'string', Rule::in([
                ComprobanteCola::TIPO_BOLETA,
                ComprobanteCola::TIPO_FACTURA,
                ComprobanteCola::TIPO_NOTA_CREDITO,
            ])],
            'tienda_id' => ['sometimes', 'string', 'max:20'],
            'desde'     => ['sometimes', 'date'],
            'hasta'     => ['sometimes', 'date'],
            'per_page'  => ['sometimes', 'integer'],
        ]);

        $cola = ComprobanteCola::query()
            ->when($datos['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado', $v))
            ->when($datos['tipo_comprobante'] ?? null, fn (Builder $q, string $v) => $q->where('tipo_comprobante', $v))
            ->when($datos['tienda_id'] ?? null, fn (Builder $q, string $v) => $q->where('tienda_id', $v))
            ->when($datos['desde'] ?? null, fn (Builder $q, string $v) => $q->where('creado_en', '>=', $v.' 00:00:00'))
            ->when($datos['hasta'] ?? null, fn (Builder $q, string $v) => $q->where('creado_en', '<=', $v.' 23:59:59'))
            ->latest('creado_en')
            ->paginate(Paginacion::desde($request, 20))
            ->withQueryString();

        return response()->json($cola);
    }

    /**
     * Encola una NOTA_CREDITO que afecta a un comprobante ACEPTADO.
     *
     * Port de `gerencia/emitir_nc.php`. SOLO encola: la emisión real contra la API
     * la hace el mismo drenador de `comprobantes_cola` (ticket 005), no este
     * endpoint. `comprobante_afectado_id` es el vínculo fiscal duro — sin un
     * comprobante ACEPTADO con serie/correlativo no hay NC posible.
     */
    public function notaCredito(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'cod_motivo' => ['sometimes', Rule::in(['01', '02', '07'])],
            'des_motivo' => ['sometimes', 'string', 'max:200'],
        ]);

        $original = ComprobanteCola::findOrFail($id);

        if (
            !$original->estaAceptado()
            || !in_array($original->tipo_comprobante, [ComprobanteCola::TIPO_BOLETA, ComprobanteCola::TIPO_FACTURA], true)
        ) {
            return response()->json([
                'error' => 'Solo se puede emitir Nota de Crédito sobre boletas o facturas aceptadas.',
            ], 422);
        }

        if ($original->serie === null || $original->correlativo === null) {
            return response()->json([
                'error' => 'El comprobante original no tiene serie/correlativo asignado.',
            ], 422);
        }

        // Idempotencia por vínculo fiscal: reintentar el clic no duplica la NC. Un
        // rechazo definitivo sí libera para volver a intentarlo.
        $existente = ComprobanteCola::query()
            ->where('comprobante_afectado_id', $original->getKey())
            ->where('tipo_comprobante', ComprobanteCola::TIPO_NOTA_CREDITO)
            ->where('estado', '!=', ComprobanteCola::ESTADO_RECHAZADO)
            ->first();

        if ($existente !== null) {
            return response()->json(['ok' => true, 'ya_existe' => true] + $this->identidad($existente));
        }

        $numeroOriginal  = (string) $original->numero_completo;
        $tipoDocAfectado = $original->tipo_comprobante === ComprobanteCola::TIPO_BOLETA ? '03' : '01';
        $codMotivo       = $datos['cod_motivo'] ?? '01';
        $desMotivo       = $datos['des_motivo'] ?? 'Anulacion de la operacion';

        $nc = ComprobanteCola::query()->create([
            'ticket_id'               => $original->ticket_id,
            'tienda_id'               => $original->tienda_id,
            'agente_id'               => $original->agente_id,
            'comprobante_afectado_id' => $original->getKey(),
            'tipo_comprobante'        => ComprobanteCola::TIPO_NOTA_CREDITO,
            'tipo_doc_cliente'        => $original->tipo_doc_cliente,
            'num_doc_cliente'         => $original->num_doc_cliente,
            'razon_social'            => $original->razon_social,
            'direccion_cliente'       => $original->direccion_cliente,
            'email_cliente'           => $original->email_cliente,
            'moneda'                  => $original->moneda,
            'total'                   => $original->total,
            'payload'                 => [
                'items' => [[
                    'descripcion'     => 'Nota de credito - '.$numeroOriginal,
                    'cantidad'        => 1,
                    'precio_unitario' => (float) $original->total,
                ]],
                'total'             => (float) $original->total,
                'moneda'            => $original->moneda,
                'tipo_doc_afectado' => $tipoDocAfectado,
                'num_doc_afectado'  => $numeroOriginal,
                'cod_motivo'        => $codMotivo,
                'des_motivo'        => $desMotivo,
            ],
        ]);

        return response()->json(['ok' => true] + $this->identidad($nc), 201);
    }

    /**
     * Anula (comunicación de baja) una boleta ACEPTADA contra la API externa.
     *
     * Port de `gerencia/anular_boleta.php`. Solo boletas: una factura se afecta
     * con NC, no con baja. El estado local SOLO cambia después de que la API
     * confirme la baja — nunca antes, para no desincronizar el CPE fiscal del
     * registro local.
     */
    public function anular(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'motivo' => ['sometimes', 'string', 'max:255'],
        ]);

        $cola = ComprobanteCola::findOrFail($id);

        if ($cola->tipo_comprobante !== ComprobanteCola::TIPO_BOLETA) {
            return response()->json(['error' => 'Solo se pueden anular boletas.'], 422);
        }

        if (!$cola->estaAceptado()) {
            return response()->json(['error' => 'Solo se pueden anular boletas aceptadas.'], 422);
        }

        if ($cola->api_doc_id === null) {
            return response()->json(['error' => 'El comprobante no tiene un identificador de la API asociado.'], 422);
        }

        $config = $cola->resolverConfig();

        if ($config === null || !$config->estaOperativa()) {
            return response()->json(['error' => 'Facturación no configurada o inactiva.'], 422);
        }

        $respuesta = $this->cliente($config)->anular($cola->tipo_comprobante, $cola->api_doc_id);
        $cuerpo    = $respuesta->json();

        if (!$respuesta->successful() || !is_array($cuerpo) || empty($cuerpo['success'])) {
            $mensaje = is_array($cuerpo) ? (string) ($cuerpo['message'] ?? 'La API rechazó la anulación.') : 'Respuesta inválida de la API de facturación.';

            return response()->json(['error' => $mensaje], 502);
        }

        $summaryId = data_get($cuerpo, 'data.summary_id');
        $ticket    = data_get($cuerpo, 'data.ticket');

        $cola->marcarAnulado(
            $ticket !== null ? ('BAJA ticket='.$ticket) : null,
            Auth::id(),
            $datos['motivo'] ?? null,
        );

        return response()->json(['ok' => true, 'summary_id' => $summaryId, 'ticket' => $ticket] + $this->identidad($cola));
    }

    /**
     * Descarga (proxy, sin persistir) el pdf/xml/cdr de un comprobante ACEPTADO.
     *
     * Port de `gerencia/descargar_comprobante.php`. El pdf no se genera solo al
     * emitir: hay que pedirlo primero (`generate-pdf`) antes de poder bajarlo.
     */
    public function descargar(Request $request, int $id, string $tipo): Response|JsonResponse
    {
        $formatos = ['pdf' => 'application/pdf', 'xml' => 'application/xml', 'cdr' => 'application/zip'];

        if (!isset($formatos[$tipo])) {
            return response()->json(['error' => 'Formato no soportado.'], 404);
        }

        $cola = ComprobanteCola::findOrFail($id);

        if ($cola->api_doc_id === null || !$cola->estaAceptado()) {
            return response()->json(['error' => 'Comprobante no disponible para descarga.'], 404);
        }

        $config = $cola->resolverConfig();

        if ($config === null || !$config->estaOperativa()) {
            return response()->json(['error' => 'Facturación no configurada o inactiva.'], 422);
        }

        $cliente = $this->cliente($config);

        if ($tipo === 'pdf') {
            $cliente->generarPdf($cola->tipo_comprobante, $cola->api_doc_id);
        }

        $respuesta = $cliente->descargar($cola->tipo_comprobante, $cola->api_doc_id, $tipo);

        if (!$respuesta->successful()) {
            return response()->json(['error' => 'No se pudo obtener el archivo desde la API de facturación.'], 502);
        }

        $extension = $tipo === 'cdr' ? 'zip' : $tipo;
        $nombre    = ($cola->numero_completo ?? ('comprobante_'.$cola->getKey())).'.'.$extension;

        return response($respuesta->body(), 200, [
            'Content-Type'        => $respuesta->header('Content-Type') ?: $formatos[$tipo],
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ]);
    }

    /**
     * Genera el link público firmado + texto de WhatsApp.
     *
     * Port de `reportes/ajax_link_cpe.php`. Solo un comprobante con desenlace
     * fiscal (ACEPTADO o ANULADO) tiene un CPE que mostrar/descargar en público:
     * uno PENDIENTE/ERROR aún no existe ante SUNAT, y compartirlo prematuramente
     * confundiría al cliente con un link que nunca va a resolver.
     */
    public function link(Request $request, int $id, CpeLinkService $links): JsonResponse
    {
        $cola = ComprobanteCola::findOrFail($id);
        $this->autorizarTienda($request, $cola->tienda_id);

        if (!in_array($cola->estado, [ComprobanteCola::ESTADO_ACEPTADO, ComprobanteCola::ESTADO_ANULADO], true)) {
            return response()->json(['error' => 'El comprobante aún no tiene un CPE emitido para compartir.'], 422);
        }

        $enlace = $links->generarLink($cola->getKey());

        $tipoLabel = $cola->tipo_comprobante === ComprobanteCola::TIPO_FACTURA ? 'Factura' : 'Boleta';

        $texto = sprintf(
            'Hola%s, aquí tienes tu %s %s por S/ %s: %s',
            $cola->razon_social ? ' '.$cola->razon_social : '',
            $tipoLabel,
            $cola->numero_completo ?? '',
            number_format((float) $cola->total, 2),
            $enlace['url'],
        );

        return response()->json([
            'url'          => $enlace['url'],
            'exp'          => $enlace['exp'],
            'whatsapp_url' => 'https://wa.me/?text='.rawurlencode($texto),
        ] + $this->identidad($cola));
    }

    /** Punto de extensión para los tests: un cliente por emisor, no un singleton. */
    protected function cliente(FacturacionConfig $config): FacturacionApiClient
    {
        return new FacturacionApiClient($config);
    }

    /** @return array<string, mixed> */
    private function identidad(ComprobanteCola $cola): array
    {
        return [
            'cola_id'         => $cola->getKey(),
            'estado'          => $cola->estado,
            'api_doc_id'      => $cola->api_doc_id,
            'serie'           => $cola->serie,
            'correlativo'     => $cola->correlativo,
            'numero_completo' => $cola->numero_completo,
        ];
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
            $cola = ComprobanteCola::findOrFail($datos['cola_id']);
            $this->autorizarTienda($request, $cola->tienda_id);

            return $cola;
        }

        $this->autorizarTienda($request, $datos['tienda_id'] ?? null);

        // Encolar primero y emitir después no es una formalidad: si el proceso muere
        // entre ambos pasos, la fila ya existe y el cron la recoge.
        return ComprobanteCola::encolar(Arr::except($datos, ['cola_id']));
    }

    private function autorizarTienda(Request $request, ?string $tiendaId): void
    {
        $usuario = $request->user();
        $veTodasLasTiendas = $usuario instanceof Usuario
            && $usuario->tieneAlgunRol(Usuario::ROL_ADMINISTRADOR, Usuario::ROL_GERENTE);

        abort_if(
            TiendaGuard::bloqueaAcceso($veTodasLasTiendas, $usuario?->tienda_id, $tiendaId),
            403,
            'No tienes permisos sobre los comprobantes de esta tienda.'
        );
    }

    /** @return array<string, mixed> */
    private function respuesta(ResultadoProceso $resultado): array
    {
        $identidad = $this->identidad($resultado->cola);

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
