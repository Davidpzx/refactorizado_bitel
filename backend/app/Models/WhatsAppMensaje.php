<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMensaje extends Model
{
    protected $table = 'whatsapp_mensajes';

    protected $fillable = [
        'chat_id', 'direccion', 'tipo', 'contenido', 'media_url',
        'wa_message_id', 'enviado_por', 'timestamp',
    ];

    protected $casts = ['timestamp' => 'datetime'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(WhatsAppChat::class, 'chat_id');
    }
}