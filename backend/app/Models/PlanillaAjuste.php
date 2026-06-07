<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanillaAjuste extends Model
{
    protected $table = 'planilla_ajustes';

    public $incrementing = false;
    public $timestamps   = false;

    protected $primaryKey = null;

    protected $fillable = [
        'agente_id', 'mes',
        'dias_trabajados',
        'comision_jefe', 'comision_equipo', 'comision_planes', 'comision_online',
        'retencion_uniforme', 'faltas_permisos', 'tardanzas', 'faltante_efectivo',
        'override_comisiones', 'notas',
    ];

    protected $casts = [
        'dias_trabajados'   => 'decimal:1',
        'comision_jefe'     => 'decimal:2',
        'comision_equipo'   => 'decimal:2',
        'comision_planes'   => 'decimal:2',
        'comision_online'   => 'decimal:2',
        'retencion_uniforme'=> 'decimal:2',
        'faltas_permisos'   => 'decimal:2',
        'tardanzas'         => 'decimal:2',
        'faltante_efectivo' => 'decimal:2',
        'override_comisiones' => 'boolean',
    ];

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }
}
