<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppBotPromocion extends Model
{
    protected $table = 'whatsapp_bot_promocion';

    public $timestamps = false;

    protected $fillable = ['texto', 'foto_base64'];
}
