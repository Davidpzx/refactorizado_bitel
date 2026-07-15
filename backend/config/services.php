<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Integrador Bitel (agente extractor local). La API key debe coincidir con
    // la del config.php de los agentes desplegados en las tiendas.
    'integrador' => [
        // Sin default: el secreto vive en la env var INTEGRADOR_API_KEY (.env del VPS).
        // En producción, si falta, AppServiceProvider::boot() aborta el arranque.
        'api_key' => env('INTEGRADOR_API_KEY'),
    ],

    'evolution' => [
        'base_url' => env('EVOLUTION_API_BASE_URL'),
        'api_key' => env('EVOLUTION_API_KEY'),
    ],
];
