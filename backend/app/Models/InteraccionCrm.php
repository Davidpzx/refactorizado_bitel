<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InteraccionCrm extends Model
{
    protected $table = 'interacciones_crm';

    const CREATED_AT = 'fecha';
    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id', 'cliente_id', 'agente_id',
        'tipo', 'detalle',
    ];

    protected $casts = [
        'fecha' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
