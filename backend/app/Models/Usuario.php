<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';
    public $timestamps = false;

    protected $fillable = [
        'nombre', 'email', 'password', 'rol', 'tienda_id', 'agente_id', 'activo', 'formato_ticket',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'activo'     => 'boolean',
        'tiene_bcp'  => 'boolean',
        'created_at' => 'datetime',
    ];

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function scopePorRol(Builder $query, string $rol): Builder
    {
        return $query->where('rol', $rol);
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }
}
