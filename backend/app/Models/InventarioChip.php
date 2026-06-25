<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventarioChip extends Model
{
    protected $table = 'inventario_chips';

    // La tabla real solo tiene updated_at, no created_at
    const CREATED_AT = null;

    protected $fillable = [
        'tienda_id',
        'tienda_origen',
        'tipo_chip',
        'stock_actual',
        'series_info',
    ];

    protected $casts = [
        'stock_actual' => 'integer',
        'tienda_id'    => 'integer',
        'series_info'  => 'array',
    ];

    public function tienda()
    {
        return $this->belongsTo(Tienda::class, 'tienda_id', 'id');
    }

    public function traslados()
    {
        return $this->hasMany(TrasladoChip::class, 'chip_id_origen');
    }
}
