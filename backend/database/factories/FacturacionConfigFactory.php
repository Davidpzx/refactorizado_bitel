<?php

namespace Database\Factories;

use App\Models\FacturacionConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FacturacionConfig>
 */
class FacturacionConfigFactory extends Factory
{
    protected $model = FacturacionConfig::class;

    public function definition(): array
    {
        return [
            'tienda_id'           => null,
            'company_id'          => 1,
            'branch_id'           => 1,
            'base_url'            => 'https://facturacion.example.test',
            'api_token'           => fake()->sha256(),
            'ruc'                 => (string) fake()->numerify('20#########'),
            'razon_social_emisor' => fake()->company(),
            'emisor_ubigeo'       => '150101',
            'emisor_distrito'     => 'Lima',
            'emisor_provincia'    => 'Lima',
            'emisor_departamento' => 'Lima',
            'modo'                => 'beta',
            'serie_boleta'        => 'B001',
            'serie_factura'       => 'F001',
            'serie_nota_credito'  => 'FC01',
            'igv_porcentaje'      => 18.00,
            'activo'              => true,
        ];
    }

    /** Configuración global (fallback). */
    public function global(): static
    {
        return $this->state(['tienda_id' => null]);
    }

    /** Configuración propia de una tienda, por código. */
    public function deTienda(string $codigoTienda): static
    {
        return $this->state(['tienda_id' => $codigoTienda]);
    }

    public function produccion(): static
    {
        return $this->state(['modo' => 'produccion']);
    }

    public function inactiva(): static
    {
        return $this->state(['activo' => false]);
    }
}
