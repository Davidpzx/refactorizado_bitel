<?php

namespace Database\Factories;

use App\Models\ComprobanteCola;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ComprobanteCola>
 */
class ComprobanteColaFactory extends Factory
{
    protected $model = ComprobanteCola::class;

    public function definition(): array
    {
        return [
            'venta_id'           => null,
            'reporte_id'         => null,
            'ticket_id'          => null,
            'tienda_id'          => 'PUNDA50',
            'agente_id'          => 1001,
            'clave_idempotencia' => null,
            'tipo_comprobante'   => ComprobanteCola::TIPO_BOLETA,
            'tipo_doc_cliente'   => '1',
            'num_doc_cliente'    => (string) fake()->numerify('########'),
            'razon_social'       => fake()->name(),
            'direccion_cliente'  => fake()->address(),
            'email_cliente'      => fake()->safeEmail(),
            'moneda'             => 'PEN',
            'total'              => 118.00,
            'payload'            => [
                'items' => [
                    ['descripcion' => 'Chip Bitel', 'cantidad' => 1, 'precio_unitario' => 100.00],
                ],
                'igv'   => 18.00,
                'total' => 118.00,
            ],
            'estado'       => ComprobanteCola::ESTADO_PENDIENTE,
            'intentos'     => 0,
            'max_intentos' => 8,
        ];
    }

    public function factura(): static
    {
        return $this->state(['tipo_comprobante' => ComprobanteCola::TIPO_FACTURA, 'tipo_doc_cliente' => '6']);
    }

    public function notaCredito(): static
    {
        return $this->state(['tipo_comprobante' => ComprobanteCola::TIPO_NOTA_CREDITO]);
    }

    /** Falló de forma reintentable y aún no vence el backoff. */
    public function enBackoff(int $intentos = 2): static
    {
        return $this->state([
            'estado'             => ComprobanteCola::ESTADO_ERROR,
            'intentos'           => $intentos,
            'ultimo_error'       => 'API no disponible',
            'proximo_intento_at' => now()->addMinutes(30),
        ]);
    }

    public function agotada(): static
    {
        return $this->state([
            'estado'             => ComprobanteCola::ESTADO_ERROR,
            'intentos'           => 8,
            'max_intentos'       => 8,
            'proximo_intento_at' => now()->subMinute(),
        ]);
    }

    public function aceptada(): static
    {
        return $this->state([
            'estado'     => ComprobanteCola::ESTADO_ACEPTADO,
            'api_doc_id' => fake()->numberBetween(1, 9999),
        ]);
    }
}
