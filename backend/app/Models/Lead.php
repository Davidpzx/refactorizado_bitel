<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $table = 'leads';

    const CREATED_AT = 'creado_en';
    public const UPDATED_AT = null;

    protected $fillable = [
        'cliente_id', 'agente_id', 'tienda_id',
        'estado', 'fuente', 'notas',
    ];

    protected $casts = [
        'creado_en' => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function interacciones(): HasMany
    {
        return $this->hasMany(InteraccionCrm::class);
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopePorAgente($query, int $agenteId)
    {
        return $query->where('agente_id', $agenteId);
    }
}
