<?php

namespace Tests\Feature\Api;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_listing_requires_auth(): void
    {
        $this->postJson('/api/listings', [])->assertStatus(401);
    }

    public function test_create_listing_success(): void
    {
        $seller = User::factory()->create();
        Sanctum::actingAs($seller);

        $response = $this->postJson('/api/listings', [
            'title' => 'Sword',
            'unit_price_minor' => 500,
            'currency' => 'usd',
            'quantity' => 10,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Sword')
            ->assertJsonPath('data.unit_price_minor', 500)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.quantity_available', 10)
            ->assertJsonPath('data.seller_id', $seller->id);

        $this->assertDatabaseHas('listings', [
            'seller_id' => $seller->id,
            'title' => 'Sword',
        ]);
    }

    public function test_create_listing_validation(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/listings', [
            'title' => '',
            'unit_price_minor' => 0,
            'currency' => 'us',
            'quantity' => 0,
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['title', 'unit_price_minor', 'currency', 'quantity']);
    }

    public function test_show_listing_any_auth_user(): void
    {
        $listing = Listing::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/listings/{$listing->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $listing->id)
            ->assertJsonStructure(['data' => [
                'id', 'seller_id', 'title', 'unit_price_minor',
                'currency', 'quantity_available', 'status',
            ]]);
    }
}
