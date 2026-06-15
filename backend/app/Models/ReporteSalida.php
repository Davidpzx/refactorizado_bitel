<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReporteSalida extends Model
{
    protected $table = 'reporte_salidas';

    public $timestamps = false;

    protected $fillable = [
        'reporte_id',
        'tipo',
        'monto',
        'observacion',
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function reporte(): BelongsTo
    {
        return $this->belongsTo(Reporte::class);
    }
}
