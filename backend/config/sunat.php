<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Entorno SUNAT
    |--------------------------------------------------------------------------
    | 'beta'      → endpoints de prueba (e-beta.sunat.gob.pe)
    | 'produccion'→ endpoints reales   (e-invoice.sunat.gob.pe)
    */
    'env' => env('SUNAT_ENV', 'beta'),

    /*
    |--------------------------------------------------------------------------
    | Credenciales SOL del emisor
    |--------------------------------------------------------------------------
    | Almacenadas en .env (nunca en código). En producción el VPS debe tener:
    |   SUNAT_RUC, SUNAT_USUARIO_SOL, SUNAT_CLAVE_SOL
    */
    'ruc'          => env('SUNAT_RUC', ''),
    'usuario_sol'  => env('SUNAT_USUARIO_SOL', ''),
    'clave_sol'    => env('SUNAT_CLAVE_SOL', ''),

    /*
    |--------------------------------------------------------------------------
    | Certificado digital
    |--------------------------------------------------------------------------
    | Acepta .pem (PEM plano) o .pfx (se convierte al vuelo con openssl).
    | La ruta por defecto es storage/app/sunat/certificado.pem en el VPS.
    */
    'cert_path'     => env('SUNAT_CERT_PATH', ''),
    'cert_password' => env('SUNAT_CERT_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Datos del emisor (empresa)
    |--------------------------------------------------------------------------
    */
    'razon_social'     => env('SUNAT_RAZON_SOCIAL', 'EMPRESA SAC'),
    'nombre_comercial' => env('SUNAT_NOMBRE_COMERCIAL', ''),
    'direccion'        => env('SUNAT_DIRECCION', ''),
    'ubigeo'           => env('SUNAT_UBIGEO', '150101'),   // Lima por defecto

    /*
    |--------------------------------------------------------------------------
    | Series por tipo de comprobante
    |--------------------------------------------------------------------------
    | B001 → boletas (tipo 03)
    | F001 → facturas (tipo 01)
    | T001 → tickets internos (no SUNAT)
    */
    'series' => [
        'boleta'  => env('SUNAT_SERIE_BOLETA',  'B001'),
        'factura' => env('SUNAT_SERIE_FACTURA', 'F001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | En producción cambiar QUEUE_CONNECTION=redis en el .env del VPS y levantar:
    |   php artisan queue:work redis --queue=sunat --tries=10
    */
    'queue'   => env('SUNAT_QUEUE', 'sunat'),
    'timeout' => (int) env('SUNAT_JOB_TIMEOUT', 60),
];
