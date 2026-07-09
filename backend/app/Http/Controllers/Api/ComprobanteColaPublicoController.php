<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComprobanteCola;
use App\Services\Facturacion\CpeLinkService;
use App\Services\Facturacion\FacturacionApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Vista pública (sin sesión) de un comprobante: el link que el cliente recibe
 * por WhatsApp. Port de `reportes/cpe_publico.php` + `reportes/imprimir_comprobante.php`
 * (legacy), unificados porque comparten la misma verificación de firma HMAC.
 *
 * Ningún método aquí requiere `auth:sanctum`: la autorización es la firma
 * (`exp` + `firma`) verificada en `autorizado()`, igual que el legacy validaba
 * `hash_equals` contra `cpe_token()`.
 */
class ComprobanteColaPublicoController extends Controller
{
    /** Los 4 formatos del legacy; cualquier otro valor cae a A4. */
    private const FORMATOS_IMPRESION = ['A4', 'a5', '80mm', 'ticket'];

    public function __construct(private readonly CpeLinkService $links)
    {
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $cola = $this->autorizado($request, $id);

        if ($cola instanceof JsonResponse) {
            return $cola;
        }

        return response()->json([
            'id'                => $cola->getKey(),
            'tipo_comprobante'  => $cola->tipo_comprobante,
            'estado'            => $cola->estado,
            'serie'             => $cola->serie,
            'correlativo'       => $cola->correlativo,
            'numero_completo'   => $cola->numero_completo,
            'moneda'            => $cola->moneda,
            'total'             => $cola->total,
            'razon_social'      => $cola->razon_social,
            'tipo_doc_cliente'  => $cola->tipo_doc_cliente,
            'num_doc_cliente'   => $cola->num_doc_cliente,
            'direccion_cliente' => $cola->direccion_cliente,
            'creado_en'         => $cola->creado_en,
            'anulado_en'        => $cola->anulado_en,
            'puede_descargar'   => $cola->api_doc_id !== null
                && in_array($cola->estado, [ComprobanteCola::ESTADO_ACEPTADO, ComprobanteCola::ESTADO_ANULADO], true),
        ]);
    }

    public function descargar(Request $request, int $id, string $tipo): Response|JsonResponse
    {
        $formatosMime = ['pdf' => 'application/pdf', 'xml' => 'application/xml', 'cdr' => 'application/zip'];

        if (!isset($formatosMime[$tipo])) {
            return response()->json(['error' => 'Formato no soportado.'], 404);
        }

        $cola = $this->autorizado($request, $id);

        if ($cola instanceof JsonResponse) {
            return $cola;
        }

        if ($cola->api_doc_id === null
            || !in_array($cola->estado, [ComprobanteCola::ESTADO_ACEPTADO, ComprobanteCola::ESTADO_ANULADO], true)
        ) {
            return response()->json(['error' => 'Comprobante no disponible para descarga.'], 404);
        }

        $config = $cola->resolverConfig();

        if ($config === null || !$config->estaOperativa()) {
            return response()->json(['error' => 'Facturación no configurada o inactiva.'], 422);
        }

        $cliente = new FacturacionApiClient($config);

        if ($tipo === 'pdf') {
            $formato = (string) $request->query('formato', 'A4');
            $formato = in_array($formato, self::FORMATOS_IMPRESION, true) ? $formato : 'A4';
            $cliente->generarPdf($cola->tipo_comprobante, $cola->api_doc_id, $formato);
        }

        $respuesta = $cliente->descargar($cola->tipo_comprobante, $cola->api_doc_id, $tipo);

        if (!$respuesta->successful()) {
            return response()->json(['error' => 'No se pudo obtener el archivo desde la API de facturación.'], 502);
        }

        $extension    = $tipo === 'cdr' ? 'zip' : $tipo;
        $nombre       = ($cola->numero_completo ?? ('comprobante_'.$cola->getKey())).'.'.$extension;
        // El pdf va inline: la página de impresión pública lo embebe en un <iframe>.
        $disposicion  = $tipo === 'pdf' ? 'inline' : 'attachment';

        return response($respuesta->body(), 200, [
            'Content-Type'        => $respuesta->header('Content-Type') ?: $formatosMime[$tipo],
            'Content-Disposition' => $disposicion.'; filename="'.$nombre.'"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }

    /** Verifica firma + expiración; devuelve la fila o un 403/404/422 ya armado. */
    private function autorizado(Request $request, int $id): ComprobanteCola|JsonResponse
    {
        $datos = $request->validate([
            'exp'   => ['required', 'integer'],
            'firma' => ['required', 'string'],
        ]);

        if (!$this->links->verificar($id, (int) $datos['exp'], (string) $datos['firma'])) {
            return response()->json(['error' => 'Enlace inválido o expirado.'], 403);
        }

        $cola = ComprobanteCola::find($id);

        if ($cola === null) {
            return response()->json(['error' => 'Comprobante no encontrado.'], 404);
        }

        return $cola;
    }
}
