<?php

namespace Database\Factories;

use App\Models\PrecoReferencia;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrecoReferencia>
 */
class PrecoReferenciaFactory extends Factory
{
    protected $model = PrecoReferencia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'produto_id' => Produto::factory(),
            'data_preco' => fake()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'preco_fechamento' => fake()->randomFloat(2, 10, 2000),
            'cambio_brl' => fake()->randomFloat(4, 4, 6),
        ];
    }
}
