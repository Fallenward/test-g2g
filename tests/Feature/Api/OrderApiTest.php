<?php

namespace Tests\Feature\Api;

use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FundsWallet;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase, FundsWallet;

    public function test_purchase_creates_escrow_locked_order(): void
    {
        $buyer = User::factory()->create();
        $this->fundWallet($buyer, 10_000);
        Sanctum::actingAs($buyer);

        $listing = Listing::factory()->create([
            'unit_price_minor' => 2_000,
            'quantity_available' => 5,
        ]);

        $response = $this->postJson('/api/orders', [
            'listing_id' => $listing->id,
            'quantity' => 2,
            'idempotency_key' => 'test-key-1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'escrow_locked')
            ->assertJsonPath('data.gross_minor', 4_000);
    }

    public function test_purchase_insufficient_stock_returns_409(): void
    {
        $buyer = User::factory()->create();
        $this->fundWallet($buyer, 100_000);
        Sanctum::actingAs($buyer);

        $listing = Listing::factory()->create([
            'unit_price_minor' => 1_000,
            'quantity_available' => 1,
        ]);

        $this->postJson('/api/orders', [
            'listing_id' => $listing->id,
            'quantity' => 5,
            'idempotency_key' => 'test-key-2',
        ])->assertStatus(409);
    }

    public function test_purchase_insufficient_funds_returns_409(): void
    {
        $buyer = User::factory()->create();
        $this->fundWallet($buyer, 500);
        Sanctum::actingAs($buyer);

        $listing = Listing::factory()->create([
            'unit_price_minor' => 1_000,
            'quantity_available' => 5,
        ]);

        $this->postJson('/api/orders', [
            'listing_id' => $listing->id,
            'quantity' => 1,
            'idempotency_key' => 'test-key-3',
        ])->assertStatus(409);
    }

    public function test_purchase_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/orders', [
            'listing_id' => 999,
            'quantity' => 0,
            'idempotency_key' => '',
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['listing_id', 'quantity', 'idempotency_key']);
    }

    public function test_illegal_transition_returns_409(): void
    {
        [$order, $buyer] = $this->createLockedOrder();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/orders/{$order->id}/complete")
            ->assertStatus(409);
    }

    public function test_wrong_actor_returns_403(): void
    {
        [$order, , $seller, $stranger] = $this->createLockedOrder();
        Sanctum::actingAs($stranger);

        $this->postJson("/api/orders/{$order->id}/deliver")
            ->assertStatus(403);
    }

public function test_full_happy_lifecycle(): void
{
    [$order, $buyer, $seller] = $this->createLockedOrder();

    Sanctum::actingAs($seller);
    $this->postJson("/api/orders/{$order->id}/deliver")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'delivered');

    Sanctum::actingAs($buyer);
    $this->postJson("/api/orders/{$order->id}/complete")
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.fee_minor', 200);
}

    public function test_show_order_only_buyer_or_seller(): void
    {
        [$order, $buyer, $seller, $stranger] = $this->createLockedOrder();

        Sanctum::actingAs($buyer);
        $this->getJson("/api/orders/{$order->id}")->assertStatus(200);

        Sanctum::actingAs($seller);
        $this->getJson("/api/orders/{$order->id}")->assertStatus(200);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/orders/{$order->id}")->assertStatus(403);
    }

    /**
     * Helper: buyer with funds, seller, listing, and one locked order.
     *
     * @return array{0: Order, 1: User, 2: User, 3: User}
     */
    private function createLockedOrder(): array
    {
        $seller   = User::factory()->create();
        $buyer    = User::factory()->create();
        $stranger = User::factory()->create();
        $this->fundWallet($buyer, 10_000);

        $listing = Listing::factory()->create([
            'seller_id' => $seller->id,
            'unit_price_minor' => 2_000,
            'quantity_available' => 5,
        ]);

        Sanctum::actingAs($buyer);
        $response = $this->postJson('/api/orders', [
            'listing_id' => $listing->id,
            'quantity' => 2,
            'idempotency_key' => 'ord-'.uniqid(),
        ]);

        $order = Order::findOrFail($response->json('data.id'));

        return [$order, $buyer, $seller, $stranger];
    }
}
