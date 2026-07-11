<?php

return [
    // Versión semver por defecto — solo se usa si nunca se subió ningún APK
    // (una vez que `subir` persiste una fila en `app_terminal_version`, esa
    // fila manda).
    'version' => env('APP_TERMINAL_VERSION', '1.0.0'),

    // Ruta relativa dentro de storage/app donde vive el APK vigente. Se
    // sobrescribe en cada subida — solo existe una versión "actual" a la vez.
    'apk_path' => env('APP_TERMINAL_APK_PATH', 'app-terminal/asistencia-latest.apk'),

    // Tamaño máximo de subida en KB (para la regla de validación `max`).
    'max_upload_kb' => env('APP_TERMINAL_MAX_UPLOAD_KB', 204800), // 200MB
];
