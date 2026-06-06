<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaItem extends Model
{
    protected $table = 'venta_items';
    public $timestamps = false;

    protected $fillable = [
        'venta_id', 'tipo_item', 'descripcion', 'imei_serial',
        'plan_nombre', 'tipo_alta', 'cantidad', 'precio_unitario',
        'comision_unitaria', 'financiera', 'detalle_extra',
    ];

    protected $casts = [
        'precio_unitario'  => 'decimal:2',
        'comision_unitaria'=> 'decimal:2',
        'cantidad'         => 'integer',
        'detalle_extra'    => 'array',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function subtotal(): float
    {
        return (float) $this->precio_unitario * $this->cantidad;
    }
}
