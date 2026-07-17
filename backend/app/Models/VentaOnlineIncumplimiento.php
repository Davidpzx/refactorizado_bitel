<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VentaOnlineIncumplimiento extends Model
{
    protected $table = 'ventas_online_incumplimientos';

    public $timestamps = false;

    protected $fillable = [
        'agente_ref',
        'tienda_codigo',
        'detectado_en',
        'detalle',
    ];

    protected $casts = [
        'detectado_en' => 'datetime',
    ];
}
