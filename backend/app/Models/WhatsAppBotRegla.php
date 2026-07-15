<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotRegla extends Model
{
    protected $table = 'whatsapp_bot_reglas';

    protected $fillable = [
        'cuenta_id', 'nombre', 'tipo', 'es_bienvenida', 'palabras_clave',
        'respuesta', 'menu_titulo', 'opciones', 'prioridad', 'activa',
    ];

    protected $casts = [
        'palabras_clave' => 'array',
        'opciones' => 'array',
        'es_bienvenida' => 'boolean',
        'activa' => 'boolean',
    ];
}
