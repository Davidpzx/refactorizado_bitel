<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:3000')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 86400,
    // La SPA envía withCredentials:true (frontend/src/services/api.ts), por lo que el
    // navegador exige Access-Control-Allow-Credentials:true. Se mantiene en true.
    // Con credenciales NO se puede usar '*' en allowed_origins: por eso la lista es
    // explícita vía CORS_ALLOWED_ORIGINS.
    'supports_credentials' => true,
];
