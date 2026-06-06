<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Usuario extends Authenticatable
{
    protected $table = 'usuarios';

    protected $fillable = [
        'nombre', 'email', 'password', 'rol', 'tienda_id', 'activo',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'activo'     => 'boolean',
        'tiene_bcp'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function scopeActivos($query)
    {
        return $query->where('activo', 1);
    }

    public function scopePorRol($query, string $rol)
    {
        return $query->where('rol', $rol);
    }
}
