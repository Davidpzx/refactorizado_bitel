<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaLinea extends Model
{
    protected $table = 'venta_lineas';

    public $timestamps = false;

    protected $fillable = [
        'venta_id', 'plan_nombre_snap', 'tipo_alta',
        'cantidad', 'cobrado_unitario', 'comision_unitaria', 'es_esim',
    ];

    protected $casts = [
        'cobrado_unitario'  => 'decimal:2',
        'comision_unitaria' => 'decimal:2',
        'es_esim'           => 'boolean',
        'cantidad'          => 'integer',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
