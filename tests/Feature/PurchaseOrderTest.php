<?php

namespace Tests\Feature;

use App\Domain\Actions\PurchaseOrder\PurchaseOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrderData;
use App\Domain\Exceptions\InsufficientFunds;
use App\Domain\Exceptions\InsufficientStock;
use App\Domain\Ledger\Ledger;
use App\Domain\OrderStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FundsWallet;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase, FundsWallet;

    private PurchaseOrder $action;
    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(PurchaseOrder::class);
        $this->ledger = app(Ledger::class);
    }

    public function test_happy_path_locks_escrow(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();
        $this->fundWallet($buyer, 10_000);

        $listing = Listing::factory()->create([
            'seller_id' => $seller->id,
            'unit_price_minor' => 2_000,
            'quantity_available' => 5,
            'currency' => 'USD',
        ]);

        $order = $this->action->execute(new PurchaseOrderData(
            buyerId: $buyer->id,
            listingId: $listing->id,
            quantity: 3,
            idempotencyKey: 'req-abc',
        ));

        $this->assertSame(OrderStatus::EscrowLocked, $order->status);
        $this->assertSame(6_000, $order->gross_minor);
        $this->assertSame(0, $order->fee_minor);
        $this->assertSame(0, $order->net_minor);

        $this->assertSame(2, $listing->fresh()->quantity_available);

        $wallet = $this->ledger->walletFor($buyer->id, 'USD');
        $this->assertSame(4_000, $wallet['available']);
        $this->assertSame(6_000, $wallet['escrow']);
    }

public function test_insufficient_stock_aborts_and_moves_nothing(): void
{
    $seller = User::factory()->create();
    $buyer  = User::factory()->create();
    $this->fundWallet($buyer, 10_000);

    $listing = Listing::factory()->create([
        'seller_id' => $seller->id,
        'unit_price_minor' => 2_000,
        'quantity_available' => 2,
        'currency' => 'USD',
    ]);

    $walletBefore = $this->ledger->walletFor($buyer->id, 'USD');

    try {
        $this->action->execute(new PurchaseOrderData(
            buyerId: $buyer->id,
            listingId: $listing->id,
            quantity: 3,
            idempotencyKey: 'req-x',
        ));
        $this->fail('expected InsufficientStock');
    } catch (InsufficientStock) {
    }

    // Stock untouched
    $this->assertSame(2, $listing->fresh()->quantity_available);

    // No order row
    $this->assertSame(0, \App\Models\Order::count());

    // No purchase journal (the funding journal is fine and expected)
    $this->assertSame(
        0,
        \App\Models\LedgerJournal::where('type', 'escrow_lock')->count()
    );

    // Wallet unchanged from before the attempt
    $this->assertSame($walletBefore, $this->ledger->walletFor($buyer->id, 'USD'));
}

public function test_insufficient_funds_aborts_and_moves_nothing(): void
{
    $seller = User::factory()->create();
    $buyer  = User::factory()->create();
    $this->fundWallet($buyer, 1_000);

    $listing = Listing::factory()->create([
        'seller_id' => $seller->id,
        'unit_price_minor' => 2_000,
        'quantity_available' => 5,
        'currency' => 'USD',
    ]);

    $walletBefore = $this->ledger->walletFor($buyer->id, 'USD');

    try {
        $this->action->execute(new PurchaseOrderData(
            buyerId: $buyer->id,
            listingId: $listing->id,
            quantity: 1,
            idempotencyKey: 'req-y',
        ));
        $this->fail('expected InsufficientFunds');
    } catch (InsufficientFunds) {
    }

    $this->assertSame(5, $listing->fresh()->quantity_available);
    $this->assertSame(0, \App\Models\Order::count());
    $this->assertSame(
        0,
        \App\Models\LedgerJournal::where('type', 'escrow_lock')->count()
    );

    $this->assertSame($walletBefore, $this->ledger->walletFor($buyer->id, 'USD'));
} 

    public function test_idempotent_replay_returns_same_order(): void
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();
        $this->fundWallet($buyer, 10_000);

        $listing = Listing::factory()->create([
            'seller_id' => $seller->id,
            'unit_price_minor' => 2_000,
            'quantity_available' => 5,
            'currency' => 'USD',
        ]);

        $data = new PurchaseOrderData(
            buyerId: $buyer->id,
            listingId: $listing->id,
            quantity: 2,
            idempotencyKey: 'same-key',
        );

        $first  = $this->action->execute($data);
        $second = $this->action->execute($data);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\Order::count());
        $this->assertSame(1, \App\Models\LedgerJournal::where('type', 'escrow_lock')->count());

        // Stock moved once, wallet moved once.
        $this->assertSame(3, $listing->fresh()->quantity_available);
        $wallet = $this->ledger->walletFor($buyer->id, 'USD');
        $this->assertSame(6_000, $wallet['available']);
        $this->assertSame(4_000, $wallet['escrow']);
    }
}
