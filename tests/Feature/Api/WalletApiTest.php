<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FundsWallet;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase, FundsWallet;

    public function test_wallet_requires_auth(): void
    {
        $this->getJson('/api/wallet')->assertStatus(401);
    }

    public function test_wallet_returns_derived_balances(): void
    {
        $user = User::factory()->create();
        $this->fundWallet($user, 7_500);
        Sanctum::actingAs($user);

        $this->getJson('/api/wallet')
            ->assertStatus(200)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.available', 7_500)
            ->assertJsonPath('data.escrow', 0);
    }
}
