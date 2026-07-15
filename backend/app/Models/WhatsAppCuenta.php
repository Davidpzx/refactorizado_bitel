<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCuenta extends Model
{
    protected $table = 'whatsapp_cuentas';

    protected $fillable = ['nombre', 'numero', 'instancia', 'provider', 'tienda_id', 'estado', 'bot_activo'];

    protected $casts = ['bot_activo' => 'boolean'];

    public function chats(): HasMany
    {
        return $this->hasMany(WhatsAppChat::class, 'cuenta_id');
    }
}