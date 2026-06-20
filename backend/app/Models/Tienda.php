<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    protected $table = 'tiendas';

    // La tabla real no tiene columna `id`: la clave primaria es `codigo` (string).
    protected $primaryKey = 'codigo';
    public $incrementing  = false;
    protected $keyType    = 'string';

    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'direccion', 'telefono',
        'activo', 'cuenta_bipay_id',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
