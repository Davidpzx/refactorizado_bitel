<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre'    => fake()->name(),
            'email'     => fake()->unique()->safeEmail(),
            'password'  => Hash::make('password'),
            'rol'       => 'admin',
            'tienda_id' => null,
            'activo'    => true,
            'tiene_bcp' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(['rol' => 'admin']);
    }

    public function vendedor(string $tiendaId = 'PUNDA50'): static
    {
        return $this->state(['rol' => 'vendedor', 'tienda_id' => $tiendaId]);
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }
}
