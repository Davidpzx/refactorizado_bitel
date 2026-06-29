<?php

namespace App\Services;

use App\Models\Comprobante;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GreenterService
{
    private See $see;
    private array $cfg;

    public function __construct()
    {
        $this->cfg = config('sunat');
        $this->see = $this->buildSee();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // API pública
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Flujo completo: carga la venta → genera XML → envía a SUNAT → persiste resultado.
     * Retorna array con los campos para actualizar el comprobante.
     */
    public function enviarComprobante(Comprobante $comprobante): array
    {
        $venta   = $comprobante->venta()->with(['cliente', 'equipo', 'linea'])->firstOrFail();
        $invoice = $this->buildInvoice($comprobante, $venta);

        $this->see->setService($this->endpoint($comprobante->tipo_comprobante));

        // Capturar XML firmado antes de enviar
        $xmlSigned = $this->see->getXmlSigned($invoice);
        $xmlPath   = "sunat/xml/{$comprobante->serie}-" . $this->numFmt($comprobante->numero) . '.xml';
        Storage::put($xmlPath, $xmlSigned);

        $result = $this->see->send($invoice);

        return $this->resolveResult($result, $xmlPath);
    }

    /**
     * Solo genera el XML firmado sin enviar a SUNAT (útil para preview/debug).
     */
    public function generarXml(Comprobante $comprobante): string
    {
        $venta   = $comprobante->venta()->with(['cliente', 'equipo', 'linea'])->firstOrFail();
        $invoice = $this->buildInvoice($comprobante, $venta);
        return $this->see->getXmlSigned($invoice);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Construcción de objetos Greenter
    // ──────────────────────────────────────────────────────────────────────────

    private function buildSee(): See
    {
        $certPath = $this->cfg['cert_path'] ?? '';

        $see = new See();

        if ($certPath && file_exists($certPath)) {
            $raw  = file_get_contents($certPath);
            $cert = strtolower(pathinfo($certPath, PATHINFO_EXTENSION)) === 'pfx'
                ? $this->pfxToPem($raw, $this->cfg['cert_password'] ?? '')
                : $raw;
            $see->setCertificate($cert);
        } else {
            Log::warning('GreenterService: certificado SUNAT no encontrado en: ' . $certPath);
        }

        $see->setClaveSOL(
            (string) ($this->cfg['ruc'] ?? ''),
            (string) ($this->cfg['usuario_sol'] ?? ''),
            (string) ($this->cfg['clave_sol'] ?? ''),
        );

        return $see;
    }

    private function buildInvoice(Comprobante $comprobante, $venta): Invoice
    {
        $mtoTotal    = (float) $venta->monto_total;
        $mtoOperGrav = round($mtoTotal / 1.18, 2);
        $mtoIGV      = round($mtoTotal - $mtoOperGrav, 2);

        return (new Invoice())
            ->setUbl25(true)
            ->setTipoOperacion('0101')
            ->setTipoDoc($comprobante->tipo_comprobante)
            ->setSerie($comprobante->serie)
            ->setCorrelativo((string) $comprobante->numero)
            ->setFechaEmision(new \DateTime())
            ->setTipoMoneda('PEN')
            ->setCompany($this->buildCompany())
            ->setClient($this->buildClient($venta->cliente, $comprobante->tipo_comprobante))
            ->setMtoOperGravadas($mtoOperGrav)
            ->setMtoIGV($mtoIGV)
            ->setTotalImpuestos($mtoIGV)
            ->setValorVenta($mtoOperGrav)
            ->setMtoImpVenta($mtoTotal)
            ->setDetails($this->buildDetails($venta, $mtoTotal, $mtoOperGrav, $mtoIGV))
            ->setLegends([$this->buildLegend($mtoTotal)]);
    }

    private function buildCompany(): Company
    {
        $address = (new Address())
            ->setUbigueo($this->cfg['ubigeo'] ?? '150101')
            ->setDepartamento('LIMA')
            ->setProvincia('LIMA')
            ->setDistrito('LIMA')
            ->setUrbanizacion('-')
            ->setDireccion($this->cfg['direccion'] ?: '-')
            ->setCodLocal('0000');

        return (new Company())
            ->setRuc((string) ($this->cfg['ruc'] ?? ''))
            ->setRazonSocial($this->cfg['razon_social'] ?? 'EMPRESA SAC')
            ->setNombreComercial($this->cfg['nombre_comercial'] ?: ($this->cfg['razon_social'] ?? 'EMPRESA SAC'))
            ->setAddress($address);
    }

    private function buildClient(?object $cliente, string $tipoDoc): Client
    {
        if (!$cliente) {
            return (new Client())
                ->setTipoDoc('-')
                ->setNumDoc('-')
                ->setRznSocial('CLIENTES VARIOS');
        }

        $tipoDocCode = match (strtoupper($cliente->tipo_documento ?? 'DNI')) {
            'RUC'  => '6',
            'CE'   => '4',
            'PASS' => '7',
            default => '1', // DNI
        };

        // Factura exige RUC del cliente
        if ($tipoDoc === '01' && $tipoDocCode !== '6') {
            $tipoDocCode = '6';
        }

        return (new Client())
            ->setTipoDoc($tipoDocCode)
            ->setNumDoc((string) ($cliente->dni_ruc ?? '00000000'))
            ->setRznSocial((string) ($cliente->nombre ?? 'CLIENTE'));
    }

    private function buildDetails($venta, float $total, float $base, float $igv): array
    {
        $descripcion = match ($venta->tipo_venta ?? '') {
            'POSTPAGO'  => 'ALTA DE PLAN POSTPAGO ' . trim((string) ($venta->linea?->plan_nombre_snap ?? '')),
            'PREPAGO'   => 'VENTA DE CHIP PREPAGO',
            'EQUIPO'    => 'VENTA DE EQUIPO ' . trim((string) ($venta->equipo?->producto_nombre_snap ?? '')),
            'ACCESORIO' => 'VENTA DE ACCESORIO ' . trim((string) ($venta->equipo?->producto_nombre_snap ?? '')),
            default     => trim((string) ($venta->subtipo ?? 'SERVICIO VARIOS')),
        };

        $detail = (new SaleDetail())
            ->setCodProducto('SRV-' . $venta->id)
            ->setUnidad('NIU')
            ->setCantidad(1.0)
            ->setMtoValorUnitario($base)
            ->setDescripcion(strtoupper($descripcion) ?: 'SERVICIO')
            ->setMtoBaseIgv($base)
            ->setPorcentajeIgv(18.0)
            ->setIgv($igv)
            ->setTipAfeIgv(10)
            ->setTotalImpuestos($igv)
            ->setMtoValorVenta($base)
            ->setMtoPrecioUnitario($total);

        return [$detail];
    }

    private function buildLegend(float $monto): Legend
    {
        // Catálogo 52 código 1000: monto en letras
        return (new Legend())
            ->setCode('1000')
            ->setValue('SON ' . $this->montoEnLetras($monto) . ' SOLES');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function endpoint(string $tipoDoc): string
    {
        $prod = ($this->cfg['env'] ?? 'beta') === 'produccion';
        return $tipoDoc === '03'
            ? ($prod ? SunatEndpoints::BOL_PRODUCCION : SunatEndpoints::BOL_BETA)
            : ($prod ? SunatEndpoints::FE_PRODUCCION  : SunatEndpoints::FE_BETA);
    }

    private function resolveResult($result, string $xmlPath): array
    {
        // BD enum: PENDIENTE | ENVIADO | ACEPTADO | RECHAZADO | ANULADO  (no existe ERROR ni ACEPTADO_OBS)
        $base = ['xml_path' => $xmlPath, 'cdr_path' => null, 'hash_cpe' => null,
                 'estado_sunat' => 'RECHAZADO', 'mensaje_sunat' => null];

        if (!$result) {
            return array_merge($base, ['mensaje_sunat' => 'Sin respuesta de SUNAT']);
        }

        if ($result->isAccepted()) {
            $cdrZip  = $result->getCdrZip();
            $cdrPath = str_replace('/xml/', '/cdr/', str_replace('.xml', '-cdr.zip', $xmlPath));
            if ($cdrZip) {
                Storage::put($cdrPath, $cdrZip);
            }
            return array_merge($base, [
                'cdr_path'      => $cdrZip ? $cdrPath : null,
                'hash_cpe'      => $result->getCdrResponse()?->getId(),
                'estado_sunat'  => 'ACEPTADO',
                'mensaje_sunat' => $result->getCdrResponse()?->getDescription(),
            ]);
        }

        // Aceptado con observaciones (código 0) → guardamos como ACEPTADO con nota en mensaje_sunat
        // (BD no tiene ACEPTADO_OBS en su ENUM)
        $cdrCode = $result->getCdrResponse()?->getCode();
        if ($cdrCode === 0) {
            return array_merge($base, [
                'estado_sunat'  => 'ACEPTADO',
                'mensaje_sunat' => '[Con observaciones] ' . $result->getCdrResponse()->getDescription(),
            ]);
        }

        $errors = $result->getErrors() ?? [];
        $msg    = $errors
            ? implode(' | ', array_map(fn($e) => "[{$e->getCode()}] {$e->getMessage()}", $errors))
            : ($result->getError()?->getMessage() ?? 'Error desconocido');

        return array_merge($base, ['mensaje_sunat' => substr($msg, 0, 500)]);
    }

    private function pfxToPem(string $pfx, string $password): string
    {
        $certs = [];
        if (!openssl_pkcs12_read($pfx, $certs, $password)) {
            throw new \RuntimeException('No se pudo leer el certificado .pfx — contraseña incorrecta o archivo dañado.');
        }
        return ($certs['pkey'] ?? '') . "\n" . ($certs['cert'] ?? '');
    }

    private function numFmt(int $n): string
    {
        return str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Monto en letras (español, soles peruanos)
    // ──────────────────────────────────────────────────────────────────────────

    private function montoEnLetras(float $monto): string
    {
        $entero  = (int) abs($monto);
        $decimal = (int) round((abs($monto) - $entero) * 100);
        return $this->enteroALetras($entero)
            . ' CON ' . str_pad((string) $decimal, 2, '0', STR_PAD_LEFT) . '/100';
    }

    private function enteroALetras(int $n): string
    {
        if ($n === 0) return 'CERO';
        if ($n < 0)   return 'MENOS ' . $this->enteroALetras(-$n);

        $unidades = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
            'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE',
            'DIECIOCHO', 'DIECINUEVE'];
        $decenas  = ['', '', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA',
            'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];
        $centenas = ['', 'CIEN', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
            'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

        if ($n < 20)    return $unidades[$n];
        if ($n === 20)  return 'VEINTE';
        if ($n < 30)    return 'VEINTI' . $unidades[$n % 10];
        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;
            return $decenas[$d] . ($u ? ' Y ' . $unidades[$u] : '');
        }
        if ($n < 1_000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            $base = ($c === 1 && $r > 0) ? 'CIENTO' : $centenas[$c];
            return $base . ($r ? ' ' . $this->enteroALetras($r) : '');
        }
        if ($n < 1_000_000) {
            $miles = intdiv($n, 1_000);
            $r     = $n % 1_000;
            $pre   = $miles === 1 ? 'MIL' : $this->enteroALetras($miles) . ' MIL';
            return $pre . ($r ? ' ' . $this->enteroALetras($r) : '');
        }
        $mills = intdiv($n, 1_000_000);
        $r     = $n % 1_000_000;
        $pre   = $mills === 1 ? 'UN MILLON' : $this->enteroALetras($mills) . ' MILLONES';
        return $pre . ($r ? ' ' . $this->enteroALetras($r) : '');
    }
}
