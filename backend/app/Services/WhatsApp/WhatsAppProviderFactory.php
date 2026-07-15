<?php

namespace App\Services\WhatsApp;

class WhatsAppProviderFactory
{
    public static function make(string $provider): WhatsAppProvider
    {
        return match ($provider) {
            'evolution' => new EvolutionProvider(),
            // 'watchimp' => new WatchimpProvider(), // F5
            default => throw new \InvalidArgumentException("Proveedor de WhatsApp desconocido: {$provider}"),
        };
    }
}