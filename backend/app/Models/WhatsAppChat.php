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
        'interes_score', 'bot_silenciado_hasta',
    ];

    protected $casts = [
        'ultimo_mensaje_at' => 'datetime',
        'bot_silenciado_hasta' => 'datetime',
    ];

    public static function normalizarJid(string $telefono): string
    {
        $digitos = preg_replace('/\D/', '', $telefono) ?? '';
        if (strlen($digitos) === 9 && $digitos[0] === '9') {
            $digitos = '51' . $digitos;
        }

        return $digitos . '@s.whatsapp.net';
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCuenta::class, 'cuenta_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsAppMensaje::class, 'chat_id');
    }
}