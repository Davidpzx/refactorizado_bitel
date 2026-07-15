<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppChat extends Model
{
    protected $table = 'whatsapp_chats';

    protected $fillable = [
        'cuenta_id', 'jid', 'nombre_contacto', 'numero_contacto',
        'crm_cliente_id', 'ultimo_mensaje_at', 'no_leidos',
    ];

    protected $casts = ['ultimo_mensaje_at' => 'datetime'];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCuenta::class, 'cuenta_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsAppMensaje::class, 'chat_id');
    }
}