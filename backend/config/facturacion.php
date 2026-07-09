<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ruta de emisión activa
    |--------------------------------------------------------------------------
    | DECISIÓN-001: los CPE se emiten contra la API Laravel externa, igual que el
    | legacy, drenando `comprobantes_cola` con `facturacion:procesar-cola`.
    |
    | La ruta antigua (Greenter directo contra SUNAT: `GreenterService`, el job
    | `EnviarComprobanteSunat` y la tabla `comprobantes`) queda apagada tras esta
    | bandera. El código sigue en el repositorio —no se borra en este ticket— pero
    | los endpoints que lo disparaban responden 410 mientras esté en `false`.
    |
    | Encenderla vuelve a habilitar `POST /comprobantes` y `POST
    | /comprobantes/{id}/reenviar`. Encender ambas rutas a la vez emitiría el
    | mismo comprobante dos veces ante SUNAT.
    */
    'greenter_activo' => filter_var(env('FACTURACION_GREENTER_ACTIVO', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Cola de comprobantes
    |--------------------------------------------------------------------------
    | Filas que `facturacion:procesar-cola` toma por corrida cuando no se le pasa
    | `--limit`. El comando corre cada minuto: 20 filas/minuto es el ritmo del
    | legacy y deja margen de sobra frente al timeout de 25 s por comprobante.
    */
    'cola_limite' => (int) env('FACTURACION_COLA_LIMITE', 20),
];
