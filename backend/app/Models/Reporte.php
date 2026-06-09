<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reporte extends Model
{
    protected $table = 'reportes';

    protected $fillable = [
        'agente_id', 'tienda_id', 'usuario_id', 'fecha',
        'total_dia', 'total_calculado',
        'yape', 'bipay', 'recarga_bipay', 'pago_servicio', 'pago_krece',
        'tickets_tusamy', 'retiro_bipay', 'transferencia',
        'caja_inicial', 'efectivo_entregado', 'total_salidas',
        'total_restantes', 'efectivo_esperado', 'diferencia',
        'estado', 'requiere_aprobacion', 'aprobado_por', 'fecha_aprobacion',
        'nombre_cubre', 'observaciones', 'obs_dia',
        'destino_efectivo', 'estado_edicion', 'motivo_edicion',
    ];

    protected $casts = [
        'fecha'               => 'date',
        'fecha_aprobacion'    => 'datetime',
        'total_dia'           => 'decimal:2',
        'total_calculado'     => 'decimal:2',
        'yape'                => 'decimal:2',
        'bipay'               => 'decimal:2',
        'recarga_bipay'       => 'decimal:2',
        'pago_servicio'       => 'decimal:2',
        'pago_krece'          => 'decimal:2',
        'tickets_tusamy'      => 'decimal:2',
        'retiro_bipay'        => 'decimal:2',
        'transferencia'       => 'decimal:2',
        'caja_inicial'        => 'decimal:2',
        'efectivo_entregado'  => 'decimal:2',
        'total_salidas'       => 'decimal:2',
        'total_restantes'     => 'decimal:2',
        'efectivo_esperado'   => 'decimal:2',
        'diferencia'          => 'decimal:2',
        'requiere_aprobacion' => 'boolean',
    ];

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class);
    }

    public function agente(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }
}
