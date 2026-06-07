<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VentaEquipo extends Model
{
    protected $table = 'venta_equipos';

    public $timestamps = false;

    protected $fillable = [
        'venta_id', 'inventario_tienda_id',
        'producto_nombre_snap', 'imei_serial_snap',
        'tipo_item', 'tipo_pago', 'financiera',
        'precio_venta', 'costo_snap', 'ganancia_snap', 'por_cobrar_financiera',
    ];

    protected $casts = [
        'precio_venta'          => 'decimal:2',
        'costo_snap'            => 'decimal:2',
        'ganancia_snap'         => 'decimal:2',
        'por_cobrar_financiera' => 'decimal:2',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }
}
