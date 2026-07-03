<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Venta extends Model
{
    protected $table = 'ventas';

    const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    protected $fillable = [
        'reporte_id', 'vendedor_id', 'cliente_id',
        'tipo_venta', 'subtipo', 'cross_selling', 'tienda_destino',
        'monto_total', 'efectivo_inicial', 'comision_generada',
        'comision_estado', 'es_remate', 'es_extranjero', 'chips_descontados',
        'desembolso_confirmado_por', 'desembolso_confirmado_en',
    ];

    protected $casts = [
        'monto_total'       => 'decimal:2',
        'efectivo_inicial'  => 'decimal:2',
        'comision_generada' => 'decimal:2',
        'cross_selling'     => 'boolean',
        'es_remate'         => 'boolean',
        'es_extranjero'     => 'boolean',
        'desembolso_confirmado_en' => 'datetime',
    ];

    /**
     * El ENUM de la BD no incluye 'APOYO'.
     * Lo almacenamos como OTROS_FLUJO + subtipo='APOYO' y aquí lo revertimos al leer,
     * para que el frontend siempre vea 'APOYO' como tipo_venta.
     */
    protected function tipoVenta(): Attribute
    {
        return Attribute::make(
            get: fn (string $value, array $attributes) =>
                ($value === 'OTROS_FLUJO' && ($attributes['subtipo'] ?? '') === 'APOYO')
                    ? 'APOYO'
                    : $value,
        );
    }

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(Reporte::class);
    }

    public function equipo(): HasOne
    {
        return $this->hasOne(VentaEquipo::class);
    }

    public function linea(): HasOne
    {
        return $this->hasOne(VentaLinea::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
