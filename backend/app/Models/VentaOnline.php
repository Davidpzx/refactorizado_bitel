<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaOnline extends Model
{
    protected $table = 'ventas_online';

    protected $fillable = [
        'agente_ref',
        'tienda_codigo',
        'dni',
        'nombres',
        'telefono',
        'operador_origen',
        'tipo',
        'plan_ofrecido',
        'notas',
        'estado',
        'motivo_falla',
        'crm_cliente_id',
        'origen',
    ];

    protected $casts = [
        'crm_cliente_id' => 'integer',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
