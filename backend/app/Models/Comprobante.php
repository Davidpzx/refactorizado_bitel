<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comprobante extends Model
{
    protected $table = 'comprobantes';
    public $timestamps = false;

    protected $fillable = [
        'venta_id', 'tipo_comprobante', 'serie', 'numero',
        'estado_sunat', 'xml_path', 'cdr_path', 'hash_cpe',
        'mensaje_sunat', 'intentos', 'proximo_intento',
    ];

    protected $casts = [
        'intentos'        => 'integer',
        'numero'          => 'integer',
        'proximo_intento' => 'datetime',
        'creado_en'       => 'datetime',
    ];

    public function venta(): BelongsTo
    {
        return $this->belongsTo(Venta::class);
    }

    public function getNombreCompletoAttribute(): string
    {
        return "{$this->serie}-" . str_pad($this->numero, 8, '0', STR_PAD_LEFT);
    }

    public function estaAceptado(): bool
    {
        return $this->estado_sunat === 'ACEPTADO';
    }

    public function scopePendientes($query)
    {
        return $query->where('estado_sunat', 'PENDIENTE');
    }
}
