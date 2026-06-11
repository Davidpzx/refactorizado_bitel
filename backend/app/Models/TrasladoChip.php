<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrasladoChip extends Model
{
    protected $table = 'traslados_chips';

    protected $fillable = [
        'chip_id_origen',
        'tienda_origen',
        'tienda_destino',
        'cantidad',
        'estado',
        'creado_por',
        'notas',
        'enviado_por_id',
        'enviado_dni',
        'confirmado_por_id',
        'confirmado_dni',
        'observacion_recepcion',
        'fecha_confirmacion',
    ];

    protected $casts = [
        'cantidad'           => 'integer',
        'fecha_confirmacion' => 'datetime',
    ];

    public function chipOrigen()
    {
        return $this->belongsTo(InventarioChip::class, 'chip_id_origen');
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
