<?php

namespace Database\Seeders;

use App\Models\FacturacionConfig;
use Illuminate\Database\Seeder;

/**
 * Siembra la fila global de facturación (inactiva). Las filas por tienda se
 * crean desde la UI admin; aquí solo se garantiza que exista el fallback.
 *
 * Idempotente: `updateOrCreate` sobre la global sostiene la invariante de que
 * hay exactamente una fila con `tienda_id NULL`.
 */
class FacturacionConfigSeeder extends Seeder
{
    public function run(): void
    {
        $global = FacturacionConfig::globalConfig();

        $atributos = [
            'company_id'          => 1,
            'branch_id'           => 1,
            'base_url'            => env('FACTURACION_BASE_URL', ''),
            'api_token'           => env('FACTURACION_API_TOKEN', ''),
            'ruc'                 => env('SUNAT_RUC', ''),
            'razon_social_emisor' => env('SUNAT_RAZON_SOCIAL', ''),
            'modo'                => env('SUNAT_ENV', 'beta'),
            'serie_boleta'        => 'B001',
            'serie_factura'       => 'F001',
            'serie_nota_credito'  => 'FC01',
            'igv_porcentaje'      => 18.00,
            // Se activa a mano tras cargar credenciales reales.
            'activo'              => false,
        ];

        if ($global !== null) {
            $global->fill($atributos)->save();

            return;
        }

        FacturacionConfig::create($atributos + ['tienda_id' => null]);
    }
}
