<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tienda extends Model
{
    protected $table = 'tiendas';

    // Confirmado contra la BD real (information_schema): `id` SÍ es la PK autoincremental.
    // `codigo` es solo un campo único, no la primary key.
    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'direccion', 'telefono',
        'activo', 'cuenta_bipay_id', 'latitud', 'longitud', 'radio_permitido',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
