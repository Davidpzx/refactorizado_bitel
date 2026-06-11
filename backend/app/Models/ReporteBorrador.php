<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReporteBorrador extends Model
{
    protected $table = 'reportes_borradores';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'agente_id',
        'tienda_id',
        'fecha',
        'datos_json',
    ];

    protected $casts = [
        'fecha' => 'date',
        'datos_json' => 'array',
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
    ];
}
