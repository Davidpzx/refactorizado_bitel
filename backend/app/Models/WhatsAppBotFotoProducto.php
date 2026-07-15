<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotFotoProducto extends Model
{
    protected $table = 'whatsapp_bot_fotos_producto';

    public $timestamps = false;

    protected $fillable = ['producto_nombre', 'foto_base64'];
}
