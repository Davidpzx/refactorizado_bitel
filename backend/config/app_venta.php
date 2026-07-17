<?php

return [
    // Versión semver por defecto — solo se usa si nunca se subió ningún APK.
    'version' => env('APP_VENTA_VERSION', '1.0.0'),

    // Ruta relativa dentro de storage/app donde vive el APK vigente.
    'apk_path' => env('APP_VENTA_APK_PATH', 'app-venta/venta-latest.apk'),

    // Tamaño máximo de subida en KB.
    'max_upload_kb' => env('APP_VENTA_MAX_UPLOAD_KB', 204800), // 200MB
];
