<?php

namespace App\Models;

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
        'comision_estado', 'es_remate', 'es_extranjero',
    ];

    protected $casts = [
        'monto_total'       => 'decimal:2',
        'efectivo_inicial'  => 'decimal:2',
        'comision_generada' => 'decimal:2',
        'cross_selling'     => 'boolean',
        'es_remate'         => 'boolean',
        'es_extranjero'     => 'boolean',
    ];

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
