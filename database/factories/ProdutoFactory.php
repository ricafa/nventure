<?php

namespace Database\Factories;

use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produto>
 */
class ProdutoFactory extends Factory
{
    protected $model = Produto::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nome' => ucwords(fake()->unique()->words(2, true)),
            'unidade' => fake()->randomElement(['sc 60kg', 'ton', 'bushel', 'arroba']),
            'bolsa_ref' => fake()->randomElement(['CBOT', 'B3', 'ICE']),
            'moeda_cotacao' => fake()->randomElement(['USD', 'BRL']),
            'ativo' => true,
        ];
    }

    public function inativo(): static
    {
        return $this->state(['ativo' => false]);
    }
}
