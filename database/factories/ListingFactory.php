<?php

namespace Database\Factories;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seller_id' => User::factory(),
            'title' => fake()->sentence(3),
            'unit_price_minor' => fake()->numberBetween(100, 100_000),
            'currency' => 'USD',
            'quantity_available' => fake()->numberBetween(1, 10),
            'status' => 'active',
        ];

    }
}
