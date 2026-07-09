<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de la API externa de facturación electrónica, por tienda con
 * fallback global. Paridad de `config/facturacion_config.php` del legacy.
 *
 * `tienda_id` es el CÓDIGO de tienda (`tiendas.codigo`), no el id numérico —
 * igual que en el legacy y que el resto del esquema. `tienda_id NULL` = global.
 *
 * @property string|null $tienda_id
 * @property string|null $api_token  Cifrado en reposo.
 */
class FacturacionConfig extends Model
{
    use HasFactory;

    protected $table = 'facturacion_config';

    public const CREATED_AT = 'creado_en';
    public const UPDATED_AT = 'actualizado_en';

    /** Emisión en 2 pasos (crear + send-sunat) contra la API Laravel externa. */
    public const ENDPOINT_BOLETA       = '/api/v1/boletas';
    public const ENDPOINT_FACTURA      = '/api/v1/invoices';
    public const ENDPOINT_NOTA_CREDITO = '/api/v1/notas-credito';

    protected $fillable = [
        'tienda_id', 'company_id', 'branch_id',
        'base_url', 'api_token',
        'ruc', 'razon_social_emisor',
        'emisor_ubigeo', 'emisor_distrito', 'emisor_provincia', 'emisor_departamento',
        'modo', 'serie_boleta', 'serie_factura', 'serie_nota_credito',
        'igv_porcentaje', 'activo',
    ];

    /** El token nunca sale en JSON: es un secreto de la API externa. */
    protected $hidden = ['api_token'];

    protected $casts = [
        'company_id'     => 'integer',
        'branch_id'      => 'integer',
        'api_token'      => 'encrypted',
        'igv_porcentaje' => 'decimal:2',
        'activo'         => 'boolean',
        'creado_en'      => 'datetime',
        'actualizado_en' => 'datetime',
    ];

    /**
     * Resuelve la config de una tienda con fallback a la global.
     *
     * Misma semántica que el legacy: la fila de la tienda gana ENTERA (no hay
     * merge campo a campo con la global). Devuelve null si no hay ninguna config.
     */
    public static function paraTienda(?string $tiendaId = null): ?static
    {
        $tiendaId = $tiendaId === null ? null : trim($tiendaId);

        if ($tiendaId !== null && $tiendaId !== '') {
            $config = static::query()->where('tienda_id', $tiendaId)->first();

            if ($config !== null) {
                return $config;
            }
        }

        return static::globalConfig();
    }

    /** La fila global (fallback). */
    public static function globalConfig(): ?static
    {
        return static::query()->globales()->orderBy('id')->first();
    }

    /**
     * Filas globales. Acepta '' además de NULL por si la BD viene del legacy y
     * la normalización de la migración no corrió.
     */
    public function scopeGlobales(Builder $query): Builder
    {
        return $query->where(
            fn (Builder $q) => $q->whereNull('tienda_id')->orWhere('tienda_id', '')
        );
    }

    public function esGlobal(): bool
    {
        return $this->tienda_id === null || $this->tienda_id === '';
    }

    /** Config utilizable para emitir: activa y con destino/credencial. */
    public function estaOperativa(): bool
    {
        return $this->activo
            && trim((string) $this->base_url) !== ''
            && trim((string) $this->api_token) !== '';
    }

    /** @return array<string, string> */
    public function endpoints(): array
    {
        return [
            'boleta'       => self::ENDPOINT_BOLETA,
            'factura'      => self::ENDPOINT_FACTURA,
            'nota_credito' => self::ENDPOINT_NOTA_CREDITO,
        ];
    }
}
