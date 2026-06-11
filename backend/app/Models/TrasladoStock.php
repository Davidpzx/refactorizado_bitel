<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrasladoStock extends Model
{
    protected $table = 'traslados_stock';

    protected $fillable = [
        'producto_id',
        'tienda_origen',
        'tienda_destino',
        'cantidad',
        'estado',
        'creado_por',
        'notas',
        'codigo_lote',
        'enviado_por_id',
        'enviado_dni',
        'confirmado_por_id',
        'confirmado_dni',
        'observacion_recepcion',
        'producto_nombre_snap',
        'imei_serial_snap',
        'fecha_confirmacion',
    ];

    protected $casts = [
        'cantidad'            => 'integer',
        'fecha_confirmacion'  => 'datetime',
    ];

    public function producto()
    {
        return $this->belongsTo(InventarioTienda::class, 'producto_id');
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }

    public function enviadoPor()
    {
        return $this->belongsTo(Agente::class, 'enviado_por_id');
    }

    public function confirmadoPor()
    {
        return $this->belongsTo(Agente::class, 'confirmado_por_id');
    }
}
