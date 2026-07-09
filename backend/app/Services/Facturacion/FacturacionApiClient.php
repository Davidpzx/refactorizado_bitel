<?php

namespace App\Services\Facturacion;

use App\Models\FacturacionConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la API Laravel externa de facturación electrónica.
 *
 * Dos familias de operaciones:
 *  - Configuración del emisor: port de `_api_get()` / `_api_multipart()` del legacy
 *    (`gerencia/ajax_configurar_sunat.php`), con los mismos timeouts.
 *  - Emisión de CPE en 2 pasos: port de `FacturacionClient::emitir()` del legacy
 *    (`config/facturacion_client.php`).
 *
 * Toda la configuración —URL, token, company/branch, series, IGV— entra inyectada
 * como `FacturacionConfig` (multi-emisor, TICKET-001). Nunca se lee del `.env`
 * global: dos tiendas pueden emitir contra RUCs distintos.
 *
 * Ningún log de esta clase incluye el `api_token` ni el cuerpo de las peticiones.
 */
class FacturacionApiClient
{
    private const CONNECT_TIMEOUT = 8;
    private const TIMEOUT = 45;

    /** La emisión usa el timeout corto del legacy: el cron reintenta, no espera. */
    private const TIMEOUT_EMISION = 25;

    /** Series por tipo si el emisor no define una. Iguales a las del legacy. */
    private const SERIES_DEFECTO = [
        'boleta'       => 'B001',
        'factura'      => 'F001',
        'nota_credito' => 'FC01',
    ];

    public function __construct(private readonly FacturacionConfig $config)
    {
    }

    // ── Emisión de CPE (2 pasos) ─────────────────────────────────────────────

    /**
     * Emite un comprobante: 1) POST al endpoint del tipo → crea el documento,
     * 2) POST `{id}/send-sunat` → lo envía a SUNAT.
     *
     * `$apiDocId` reanuda un envío: si el paso 1 ya creó el documento en una
     * corrida anterior y solo falló el paso 2, se salta la creación. El legacy no
     * lo hace y por eso cada reintento deja un documento huérfano en la API —y,
     * si el fallo fue al enviar, puede llegar a emitir el CPE dos veces.
     *
     * @param  string  $tipoComprobante  `BOLETA` | `FACTURA` | `NOTA_CREDITO` (o en minúsculas).
     * @param  array<string, mixed>  $doc  Snapshot fiscal + datos del emisor.
     */
    public function emitir(string $tipoComprobante, array $doc, ?int $apiDocId = null): ResultadoEmision
    {
        $tipo     = $this->tipoNormalizado($tipoComprobante);
        $endpoint = $this->endpointDe($tipo);

        if ($endpoint === null) {
            return ResultadoEmision::rechazado("Endpoint no configurado para tipo_comprobante '{$tipoComprobante}'.");
        }

        try {
            if ($apiDocId === null) {
                $creacion = $this->crear($endpoint, $tipo, $doc);

                if ($creacion instanceof ResultadoEmision) {
                    return $creacion;
                }

                $apiDocId = $creacion;
            }

            return $this->enviarASunat($endpoint, $apiDocId);
        } catch (ConnectionException $e) {
            return ResultadoEmision::reintentable(
                'Error de red al emitir: '.$e->getMessage(),
                campos: $apiDocId !== null ? ['api_doc_id' => $apiDocId] : [],
            );
        } catch (\Throwable $e) {
            return ResultadoEmision::reintentable(
                'Excepción al emitir: '.$e->getMessage(),
                campos: $apiDocId !== null ? ['api_doc_id' => $apiDocId] : [],
            );
        }
    }

    // ── Nota de crédito / anulación / descarga (TICKET-007) ────────────────────

    /**
     * POST `{endpoint}/{apiDocId}/anular` — comunicación de baja de una boleta.
     *
     * Solo boletas: una factura ACEPTADA se afecta con nota de crédito, nunca con
     * baja (regla del ticket: la anulación fiscal va por NC/baja según el tipo).
     */
    public function anular(string $tipoComprobante, int $apiDocId): Response
    {
        $endpoint = $this->endpointDeOFallar($tipoComprobante);

        return $this->peticion()->asJson()->post($this->urlBase().$endpoint.'/'.$apiDocId.'/anular', []);
    }

    /**
     * POST `{endpoint}/{apiDocId}/generate-pdf` — el PDF no se genera solo al
     * emitir el CPE, hay que pedirlo antes de poder descargarlo.
     */
    public function generarPdf(string $tipoComprobante, int $apiDocId): Response
    {
        $endpoint = $this->endpointDeOFallar($tipoComprobante);

        return $this->peticion()->asJson()->post($this->urlBase().$endpoint.'/'.$apiDocId.'/generate-pdf', []);
    }

    /**
     * GET `{endpoint}/{apiDocId}/download-{formato}` (pdf|xml|cdr). El cuerpo de
     * la respuesta es binario: el controlador lo reenvía tal cual, sin persistirlo.
     */
    public function descargar(string $tipoComprobante, int $apiDocId, string $formato): Response
    {
        $endpoint = $this->endpointDeOFallar($tipoComprobante);

        return Http::withToken((string) $this->config->api_token)
            ->accept('*/*')
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->get($this->urlBase().$endpoint.'/'.$apiDocId.'/download-'.$formato);
    }

    private function endpointDeOFallar(string $tipoComprobante): string
    {
        $endpoint = $this->endpointDe($this->tipoNormalizado($tipoComprobante));

        if ($endpoint === null) {
            throw new \InvalidArgumentException("Endpoint no configurado para tipo_comprobante '{$tipoComprobante}'.");
        }

        return $endpoint;
    }

    private function tipoNormalizado(string $tipoComprobante): string
    {
        return strtolower(trim(str_replace('-', '_', $tipoComprobante)));
    }

    private function endpointDe(string $tipoNormalizado): ?string
    {
        return $this->config->endpoints()[$tipoNormalizado] ?? null;
    }

    /**
     * Paso 1. Devuelve el id del documento creado, o el fallo que impidió crearlo.
     *
     * @param  array<string, mixed>  $doc
     */
    private function crear(string $endpoint, string $tipo, array $doc): int|ResultadoEmision
    {
        $respuesta = $this->peticion(self::TIMEOUT_EMISION)
            ->asJson()
            ->post($this->urlBase().$endpoint, $this->construirPayload($tipo, $doc));

        if ($fallo = $this->falloDe($respuesta, 'crear comprobante', $tipo)) {
            return $fallo;
        }

        $id = self::valorDe($respuesta->json(), ['id']);

        if ($id === null) {
            return ResultadoEmision::reintentable(
                'La API no devolvió el id del comprobante creado.',
                $respuesta->status(),
            );
        }

        return (int) $id;
    }

    /** Paso 2. `estado_sunat` de la respuesta decide el desenlace. */
    private function enviarASunat(string $endpoint, int $apiDocId): ResultadoEmision
    {
        $respuesta = $this->peticion(self::TIMEOUT_EMISION)
            ->asJson()
            ->post($this->urlBase().$endpoint.'/'.$apiDocId.'/send-sunat', []);

        $campos = ['api_doc_id' => $apiDocId];

        if ($fallo = $this->falloDe($respuesta, 'enviar a SUNAT', $endpoint, $campos)) {
            return $fallo;
        }

        $cuerpo = $respuesta->json();

        if (!is_array($cuerpo)) {
            return ResultadoEmision::reintentable(
                'Respuesta no válida de la API al enviar a SUNAT.',
                $respuesta->status(),
                $campos,
            );
        }

        $campos     += self::mapearCampos($cuerpo);
        $mensaje     = self::mensajeDe($cuerpo);
        $estadoSunat = strtoupper((string) self::valorDe($cuerpo, ['estado_sunat']));

        return match ($estadoSunat) {
            'ACEPTADO'  => ResultadoEmision::aceptado($mensaje ?: 'Comprobante aceptado por SUNAT.', $campos, $respuesta->status()),
            'RECHAZADO' => ResultadoEmision::rechazado($mensaje ?: 'SUNAT rechazó el comprobante.', $respuesta->status(), $campos),
            default     => ResultadoEmision::reintentable(
                $mensaje ?: "Estado SUNAT no reconocido: '{$estadoSunat}'.",
                $respuesta->status(),
                $campos,
            ),
        };
    }

    /**
     * Traduce el código HTTP al eje retryable / no-retryable, o `null` si no hubo fallo.
     *
     * 5xx es del servidor y pasa: se reintenta. 4xx es de la petición —payload
     * inválido, token muerto, documento duplicado— y volver a mandarla no la
     * arregla: es terminal.
     *
     * @param  array<string, mixed>  $campos
     */
    private function falloDe(Response $respuesta, string $accion, string $contexto, array $campos = []): ?ResultadoEmision
    {
        if ($respuesta->successful()) {
            return null;
        }

        $mensaje = self::mensajeDe($respuesta->json());
        $estado  = $respuesta->status();

        Log::warning('[cola-cpe] la API de facturación falló', [
            'accion'   => $accion,
            'contexto' => $contexto,
            'http'     => $estado,
            'base_url' => $this->urlBase(),
        ]);

        if ($respuesta->serverError()) {
            return ResultadoEmision::reintentable(
                $mensaje ?: "Error del servidor al {$accion} (HTTP {$estado}).",
                $estado,
                $campos,
            );
        }

        return ResultadoEmision::rechazado(
            $mensaje ?: "Solicitud rechazada al {$accion} (HTTP {$estado}).",
            $estado,
            $campos,
        );
    }

    /**
     * Port de `FacturacionClient::construirPayload()`.
     *
     * Los precios del snapshot vienen CON IGV incluido; la API los quiere sin él.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function construirPayload(string $tipo, array $doc): array
    {
        $porcentajeIgv = (float) ($doc['igv_porcentaje'] ?? 18);

        $detalles = array_map(function (array $item) use ($porcentajeIgv): array {
            $precioFinal = (float) ($item['precio_unitario'] ?? 0);

            return [
                'codigo'                => (string) ($item['codigo'] ?? 'P001'),
                'descripcion'           => (string) ($item['descripcion'] ?? ''),
                'unidad'                => (string) ($item['unidad'] ?? 'NIU'),
                'cantidad'              => (float) ($item['cantidad'] ?? 1),
                'mto_valor_unitario'    => round($precioFinal / (1 + $porcentajeIgv / 100), 2),
                'porcentaje_igv'        => $porcentajeIgv,
                'tip_afe_igv'           => '10',
                'codigo_producto_sunat' => (string) ($item['codigo_producto_sunat'] ?? '81112200'),
            ];
        }, is_array($doc['items'] ?? null) ? $doc['items'] : []);

        $tipoDocCliente = (string) ($doc['tipo_doc_cliente'] ?? '1');
        $numDocCliente  = (string) ($doc['num_doc_cliente'] ?? '');
        $razonSocial    = (string) ($doc['razon_social'] ?? '');

        // Boleta a consumidor final sin documento: SUNAT exige el DNI de relleno.
        if ($tipo === 'boleta' && $tipoDocCliente === '0') {
            $numDocCliente = '00000000';
            $razonSocial   = $razonSocial !== '' ? $razonSocial : 'CLIENTE VARIOS';
        }

        $payload = [
            'company_id'      => (int) ($doc['company_id'] ?? 1),
            'branch_id'       => (int) ($doc['branch_id'] ?? 1),
            'serie'           => (string) ($doc['serie'] ?? (self::SERIES_DEFECTO[$tipo] ?? 'B001')),
            'fecha_emision'   => (string) ($doc['fecha_emision'] ?? now()->toDateString()),
            'moneda'          => (string) ($doc['moneda'] ?? 'PEN'),
            'tipo_operacion'  => '0101',
            'metodo_envio'    => 'individual',
            'forma_pago_tipo' => 'Contado',
            'client'          => [
                'tipo_documento'   => $tipoDocCliente,
                'numero_documento' => $numDocCliente,
                'razon_social'     => $razonSocial,
                'direccion'        => (string) ($doc['direccion_cliente'] ?? 'Lima'),
                'ubigeo'           => (string) ($doc['emisor_ubigeo'] ?? '150101'),
                'distrito'         => (string) ($doc['emisor_distrito'] ?? 'Lima'),
                'provincia'        => (string) ($doc['emisor_provincia'] ?? 'Lima'),
                'departamento'     => (string) ($doc['emisor_departamento'] ?? 'Lima'),
            ],
            'detalles'         => array_values($detalles),
            'usuario_creacion' => (string) ($doc['usuario_creacion'] ?? $doc['agente_id'] ?? 'sistema'),
        ];

        if ($tipo === 'nota_credito') {
            $payload['tipo_doc_afectado'] = (string) ($doc['tipo_doc_afectado'] ?? '03');
            $payload['num_doc_afectado']  = (string) ($doc['num_doc_afectado'] ?? '');
            $payload['cod_motivo']        = (string) ($doc['cod_motivo'] ?? '01');
            $payload['des_motivo']        = (string) ($doc['des_motivo'] ?? 'Anulacion');
        }

        return $payload;
    }

    /**
     * Columnas de `comprobantes_cola` que la API rellena al aceptar.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    private static function mapearCampos(array $cuerpo): array
    {
        $campos = [];

        // El número fiscal lo asigna la API, no el ERP: llega como `B001-00000123`.
        $numeroCompleto = (string) self::valorDe($cuerpo, ['numero_completo']);

        if (str_contains($numeroCompleto, '-')) {
            [$serie, $correlativo] = explode('-', $numeroCompleto, 2);
            $campos['serie']       = $serie;
            $campos['correlativo'] = (int) $correlativo;
        }

        $opcionales = [
            'sunat_ticket' => ['sunat_ticket', 'ticket'],
            'cdr_estado'   => ['estado_sunat', 'cdr_estado'],
            'cdr_hash'     => ['codigo_hash', 'cdr_hash'],
            'xml_path'     => ['xml_path'],
            'pdf_path'     => ['pdf_path', 'cdr_path'],
        ];

        foreach ($opcionales as $columna => $claves) {
            $valor = self::valorDe($cuerpo, $claves);

            if ($valor !== null) {
                $campos[$columna] = $valor;
            }
        }

        return $campos;
    }

    /** Primer valor no vacío entre las claves dadas, buscando también dentro de `data`. */
    private static function valorDe(mixed $cuerpo, array $claves): mixed
    {
        if (!is_array($cuerpo)) {
            return null;
        }

        $data = is_array($cuerpo['data'] ?? null) ? $cuerpo['data'] : [];

        foreach ($claves as $clave) {
            foreach ([$cuerpo, $data] as $fuente) {
                if (array_key_exists($clave, $fuente) && $fuente[$clave] !== null && $fuente[$clave] !== '') {
                    return $fuente[$clave];
                }
            }
        }

        return null;
    }

    /** El detalle de SUNAT gana al mensaje genérico: es el que el cajero necesita leer. */
    private static function mensajeDe(mixed $cuerpo): string
    {
        if (is_array($cuerpo)) {
            $data     = is_array($cuerpo['data'] ?? null) ? $cuerpo['data'] : $cuerpo;
            $respSunat = is_array($data['respuesta_sunat'] ?? null) ? $data['respuesta_sunat'] : null;

            $descripcion = $respSunat['description'] ?? $respSunat['notes'] ?? null;

            if ($descripcion !== null && $descripcion !== '') {
                return (string) $descripcion;
            }
        }

        $mensaje = self::valorDe($cuerpo, ['mensaje', 'message', 'msg', 'error']);

        return $mensaje !== null ? (string) $mensaje : '';
    }

    // ── Configuración del emisor ─────────────────────────────────────────────

    /** GET `/api/v1/companies/{id}` — la API exige reenviar estos datos en el update. */
    public function obtenerEmpresa(): Response
    {
        return $this->peticion()->get($this->url());
    }

    /** POST multipart `/api/v1/setup/configure-sunat` — sube el certificado y activa producción. */
    public function configurarSunat(string $pemPath, string $nombre, string $mime, string $password): Response
    {
        return $this->peticion()
            ->attach('certificate_file', (string) file_get_contents($pemPath), $nombre, ['Content-Type' => $mime])
            ->post($this->url('/api/v1/setup/configure-sunat', global: true), [
                'company_id'           => (string) $this->config->company_id,
                'environment'          => 'produccion',
                'certificate_password' => $password,
                'force_update'         => 'true',
            ]);
    }

    /**
     * PUT `/api/v1/companies/{id}` con las credenciales SOL.
     *
     * Se manda como POST + `_method=PUT` porque en Laravel los uploads enviados
     * por PUT no llegan a `$request->file()` (misma razón que en el legacy).
     *
     * @param  array<string, string>  $campos
     * @param  array{contents: string, filename: string, mime: string}|null  $logo
     */
    public function actualizarEmpresa(array $campos, ?array $logo = null): Response
    {
        $peticion = $this->peticion()->asMultipart();

        if ($logo !== null) {
            $peticion = $peticion->attach(
                'logo_path',
                $logo['contents'],
                $logo['filename'],
                ['Content-Type' => $logo['mime']],
            );
        }

        return $peticion->post($this->url(), $campos + ['_method' => 'PUT']);
    }

    /** POST multipart `/api/v1/companies/{id}/toggle-production`. */
    public function activarProduccion(): Response
    {
        return $this->peticion()
            ->asMultipart()
            ->post($this->url('/toggle-production'), ['modo_produccion' => '1']);
    }

    private function peticion(int $timeout = self::TIMEOUT): PendingRequest
    {
        return Http::withToken((string) $this->config->api_token)
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);
    }

    private function urlBase(): string
    {
        return rtrim((string) $this->config->base_url, '/');
    }

    /**
     * Sin argumentos: la URL de la empresa. Con `$sufijo`: se le concatena.
     * Con `$global`: el sufijo cuelga de la base, no de la empresa.
     */
    private function url(string $sufijo = '', bool $global = false): string
    {
        $base = $this->urlBase();

        if ($global) {
            return $base.$sufijo;
        }

        return $base.'/api/v1/companies/'.$this->config->company_id.$sufijo;
    }
}
