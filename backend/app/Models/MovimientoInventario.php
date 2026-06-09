<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoInventario extends Model
{
    protected $table      = 'historial_inventario';
    public    $timestamps = false;

    protected $fillable = [
        'tienda_id', 'agente_id', 'producto_id',
        'accion', 'cantidad', 'motivo', 'observacion', 'fecha_hora',
    ];

    protected $casts = [
        'fecha_hora' => 'datetime',
        'cantidad'   => 'integer',
    ];

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(InventarioTienda::class, 'producto_id');
    }
}
