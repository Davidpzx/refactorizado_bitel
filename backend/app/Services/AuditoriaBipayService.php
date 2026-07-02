<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Puerto de includes/auditoria_helper.php + includes/webhook_helper.php del legacy:
 * cruce Bipay declarado (reportes) vs real (bitel_movimientos_diarios) y
 * alertas Discord/Slack ante descuadres.
 */
class AuditoriaBipayService
{
    /** Ejecuta el cruce de saldos Bipay para una tienda y fecha específica. */
    public function ejecutarCruceTienda(string $tiendaCodigo, string $fecha, ?int $reporteId = null): array
    {
        try {
            // Respetar cierre humano: si el cuadre está bloqueado, no recalcular.
            $bloqueado = DB::table('auditoria_cierres')
                ->where('tienda_codigo', $tiendaCodigo)->where('fecha', $fecha)
                ->value('bloqueado');
            if ((int) $bloqueado === 1) {
                return ['status' => 'skipped', 'reason' => 'cierre bloqueado', 'tienda' => $tiendaCodigo, 'fecha' => $fecha];
            }

            // 1. Bipay real operado (inyección del agente local)
            $saldoBipayReal = (float) (DB::table('bitel_movimientos_diarios')
                ->where('tienda_codigo', $tiendaCodigo)->where('fecha', $fecha)
                ->value('total_neto') ?? 0);

            // 2. Declarado en reportes: bipay + recarga_bipay - retiro_bipay
            $ventasSistema = (float) (DB::table('reportes')
                ->where('tienda_id', $tiendaCodigo)->where('fecha', $fecha)
                ->selectRaw('SUM(IFNULL(bipay,0) + IFNULL(recarga_bipay,0) - IFNULL(retiro_bipay,0)) AS v')
                ->value('v') ?? 0);

            $diferencia = round($saldoBipayReal - $ventasSistema, 2);
            $estado     = $diferencia === 0.00 ? 'CUADRADO' : 'DESCUADRADO';

            // Estado previo para no spamear alertas
            $prev = DB::table('auditoria_cierres')
                ->where('tienda_codigo', $tiendaCodigo)->where('fecha', $fecha)
                ->first(['diferencia', 'estado']);

            DB::statement("
                INSERT INTO auditoria_cierres
                    (tienda_codigo, fecha, reporte_id, saldo_bipay_real, ventas_sistema, diferencia, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    reporte_id       = IF(VALUES(reporte_id) IS NOT NULL, VALUES(reporte_id), reporte_id),
                    saldo_bipay_real = VALUES(saldo_bipay_real),
                    ventas_sistema   = VALUES(ventas_sistema),
                    diferencia       = VALUES(diferencia),
                    estado           = IF(estado = 'AJUSTADO' AND VALUES(estado) = 'DESCUADRADO', 'AJUSTADO', VALUES(estado))
            ", [$tiendaCodigo, $fecha, $reporteId, $saldoBipayReal, $ventasSistema, $diferencia, $estado]);

            if ($estado === 'DESCUADRADO'
                && (($prev->estado ?? null) !== 'DESCUADRADO' || (float) ($prev->diferencia ?? 0) !== $diferencia)) {
                $tiendaNombre = DB::table('tiendas')->where('codigo', $tiendaCodigo)->value('nombre') ?: $tiendaCodigo;
                $this->enviarAlertaDescuadre($tiendaNombre, $fecha, $diferencia, $saldoBipayReal, $ventasSistema);
            }

            return [
                'status' => 'success', 'tienda' => $tiendaCodigo, 'fecha' => $fecha,
                'diferencia' => $diferencia, 'estado' => $estado,
            ];
        } catch (\Throwable $e) {
            Log::error("[AUDITORIA BIPAY] Error al cruzar {$tiendaCodigo} en {$fecha}: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Cruce para todas las tiendas que operaron en la fecha (ERP o logs Bipay). */
    public function ejecutarCruceDiario(string $fecha): array
    {
        $tiendas = collect(DB::select("
            SELECT DISTINCT tienda_codigo AS t FROM bitel_movimientos_diarios WHERE fecha = ?
            UNION
            SELECT DISTINCT tienda_id AS t FROM reportes WHERE fecha = ?
        ", [$fecha, $fecha]))->pluck('t');

        $resultados = [];
        foreach ($tiendas as $tienda) {
            $resultados[$tienda] = $this->ejecutarCruceTienda($tienda, $fecha);
        }
        return $resultados;
    }

    // ── Webhooks (Discord / Slack) ────────────────────────────────────────────

    public function webhookConfig(): array
    {
        return DB::table('sys_config')
            ->whereIn('clave', ['bipay_webhook_url', 'bipay_webhook_tipo', 'bipay_webhook_umbral'])
            ->pluck('valor', 'clave')->all();
    }

    public function guardarWebhookConfig(array $config): void
    {
        foreach (['bipay_webhook_url', 'bipay_webhook_tipo', 'bipay_webhook_umbral'] as $clave) {
            if (array_key_exists($clave, $config)) {
                DB::table('sys_config')->updateOrInsert(['clave' => $clave], ['valor' => (string) $config[$clave]]);
            }
        }
    }

    public function enviarAlertaDescuadre(string $tiendaNombre, string $fecha, float $diferencia, float $bipayReal, float $ventasSistema): bool
    {
        $config = $this->webhookConfig();
        $url    = $config['bipay_webhook_url'] ?? '';
        if (empty($url)) {
            return false;
        }

        $umbral = (float) ($config['bipay_webhook_umbral'] ?? 5.00);
        if (abs($diferencia) < $umbral) {
            return false;
        }

        $tipo     = strtolower($config['bipay_webhook_tipo'] ?? 'discord');
        $fechaFmt = date('d/m/Y', strtotime($fecha));

        if ($tipo === 'slack') {
            $payload = ['blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '🚨 Alerta de Descuadre Bipay', 'emoji' => true]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn',
                    'text' => "*Tienda:* {$tiendaNombre}\n*Fecha:* {$fechaFmt}\n*Diferencia:* `S/ " . number_format($diferencia, 2) . '`']],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Bitel Bipay Real (Scraping):*\nS/ " . number_format($bipayReal, 2)],
                    ['type' => 'mrkdwn', 'text' => "*Ventas Declaradas (ERP):*\nS/ " . number_format($ventasSistema, 2)],
                ]],
                ['type' => 'context', 'elements' => [
                    ['type' => 'mrkdwn', 'text' => 'Requiere visto bueno/justificación en el Panel Administrativo.'],
                ]],
            ]];
        } else {
            $payload = ['embeds' => [[
                'title' => '🚨 ALERTA DE DESCUADRE BIPAY',
                'color' => 15548997,
                'fields' => [
                    ['name' => '🏪 Tienda', 'value' => $tiendaNombre, 'inline' => true],
                    ['name' => '📅 Fecha', 'value' => $fechaFmt, 'inline' => true],
                    ['name' => '⚠️ Diferencia', 'value' => 'S/ ' . number_format($diferencia, 2), 'inline' => false],
                    ['name' => '🔵 Bipay Real (Scraped)', 'value' => 'S/ ' . number_format($bipayReal, 2), 'inline' => true],
                    ['name' => '🟣 Ventas Declaradas', 'value' => 'S/ ' . number_format($ventasSistema, 2), 'inline' => true],
                ],
                'footer'    => ['text' => 'SIS-KYRO • Alerta de Control Interno'],
                'timestamp' => now()->toIso8601String(),
            ]]];
        }

        try {
            $response = Http::timeout(3)->post($url, $payload);
            if (! $response->successful()) {
                Log::warning('[WEBHOOK BIPAY] HTTP ' . $response->status() . ': ' . $response->body());
            }
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('[WEBHOOK BIPAY] ' . $e->getMessage());
            return false;
        }
    }
}
