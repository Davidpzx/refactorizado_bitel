<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistorialReporte extends Model
{
    protected $table = 'historial_reportes';

    protected $fillable = [
        'reporte_id', 'usuario_id', 'accion', 'detalle',
        'snapshot_antes', 'snapshot_despues',
    ];

    protected $casts = [
        'snapshot_antes'   => 'array',
        'snapshot_despues' => 'array',
    ];

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(Reporte::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class);
    }
}
