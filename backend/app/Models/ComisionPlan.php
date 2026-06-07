<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComisionPlan extends Model
{
    protected $table = 'comisiones_planes';

    public $timestamps = false;

    protected $fillable = [
        'tipo_servicio', 'nombre_plan', 'tipo_alta',
        'fee_monto', 'comision_dni_n', 'comision_dni_n3',
        'comision_ext_n', 'comision_ext_n3',
    ];

    protected $casts = [
        'fee_monto'       => 'decimal:2',
        'comision_dni_n'  => 'decimal:2',
        'comision_dni_n3' => 'decimal:2',
        'comision_ext_n'  => 'decimal:2',
        'comision_ext_n3' => 'decimal:2',
    ];
}
