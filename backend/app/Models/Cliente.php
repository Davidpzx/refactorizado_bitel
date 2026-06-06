<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cliente extends Model
{
    protected $table = 'clientes';
    public $timestamps = false;

    protected $fillable = [
        'dni_ruc', 'nombre', 'telefono', 'correo', 'tipo_documento',
    ];

    protected $casts = [
        'creado_en' => 'datetime',
    ];

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function scopeBuscar($query, string $termino)
    {
        return $query->where('dni_ruc', 'like', "%{$termino}%")
                     ->orWhere('nombre', 'like', "%{$termino}%");
    }

    public function scopePorDni($query, string $dni)
    {
        return $query->where('dni_ruc', $dni);
    }
}
