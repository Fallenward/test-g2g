<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => 'user',
            'owner_id' => User::factory(),
            'code' => 'available',
            'currency' => 'USD',
        ];
    }
}
