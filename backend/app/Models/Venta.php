<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Venta extends Model
{
    protected $table = 'ventas';
    public $timestamps = false;

    protected $fillable = [
        'reporte_id', 'vendedor_id', 'cliente_id', 'tipo_venta',
        'subtipo', 'cross_selling', 'tienda_destino', 'monto_total',
        'efectivo_inicial', 'comision_generada', 'comision_estado',
        'es_remate', 'es_extranjero',
    ];

    protected $casts = [
        'monto_total'       => 'decimal:2',
        'efectivo_inicial'  => 'decimal:2',
        'comision_generada' => 'decimal:2',
        'cross_selling'     => 'boolean',
        'es_remate'         => 'boolean',
        'es_extranjero'     => 'boolean',
        'creado_en'         => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VentaItem::class);
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }

    public function scopePorTipo($query, string $tipo)
    {
        return $query->where('tipo_venta', $tipo);
    }

    public function scopePorReporte($query, int $reporteId)
    {
        return $query->where('reporte_id', $reporteId);
    }
}
