<?php

namespace App\Services\Facturacion;

use Illuminate\Support\Facades\DB;

/**
 * Links públicos firmados HMAC para comprobantes (WhatsApp sin sesión).
 *
 * Port de `config/cpe_links.php` (legacy): el secreto vive en `sys_config`
 * (clave `cpe_link_secret`), se autogenera en el primer uso y el token es un
 * HMAC-SHA256 truncado sobre `cpe:{colaId}:{exp}`. `exp` viaja en claro junto
 * al token porque forma parte del mensaje firmado: cambiarlo invalida la firma.
 */
class CpeLinkService
{
    private const CLAVE_SECRET = 'cpe_link_secret';

    public const HORAS_VALIDEZ_DEFECTO = 48;

    public function secret(): string
    {
        $valor = DB::table('sys_config')->where('clave', self::CLAVE_SECRET)->value('valor');

        if ($valor !== null && $valor !== '') {
            return $valor;
        }

        $nuevo = bin2hex(random_bytes(32));

        DB::table('sys_config')->updateOrInsert(
            ['clave' => self::CLAVE_SECRET],
            ['valor' => $nuevo, 'descripcion' => 'Secreto HMAC para links públicos de comprobantes (CPE)'],
        );

        return $nuevo;
    }

    public function token(int $colaId, int $exp): string
    {
        return substr(hash_hmac('sha256', "cpe:{$colaId}:{$exp}", $this->secret()), 0, 32);
    }

    /**
     * URL pública firmada hacia la SPA (`/cpe/{id}`), no hacia la API.
     *
     * @return array{url: string, exp: int, firma: string}
     */
    public function generarLink(int $colaId, int $horasValidez = self::HORAS_VALIDEZ_DEFECTO): array
    {
        $exp   = now()->addHours($horasValidez)->getTimestamp();
        $firma = $this->token($colaId, $exp);

        $base = rtrim((string) config('app.url'), '/');

        return [
            'exp'   => $exp,
            'firma' => $firma,
            'url'   => "{$base}/cpe/{$colaId}?exp={$exp}&firma={$firma}",
        ];
    }

    /** `hash_equals` contra el token recalculado; `false` si ya expiró. */
    public function verificar(int $colaId, ?int $exp, ?string $firma): bool
    {
        if ($exp === null || $firma === null || $firma === '') {
            return false;
        }

        if (now()->getTimestamp() > $exp) {
            return false;
        }

        return hash_equals($this->token($colaId, $exp), $firma);
    }
}
