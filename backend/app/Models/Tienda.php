<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    protected $table = 'tiendas';

    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'direccion', 'telefono',
        'activo', 'cuenta_bipay_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
