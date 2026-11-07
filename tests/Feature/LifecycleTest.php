<?php

namespace Tests\Feature;

use App\Domain\Actions\Lifecycle\CancelOrder;
use App\Domain\Actions\Lifecycle\CompleteOrder;
use App\Domain\Actions\Lifecycle\MarkDelivered;
use App\Domain\Actions\Lifecycle\OpenDispute;
use App\Domain\Actions\Lifecycle\RefundOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrderData;
use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\Exceptions\IllegalTransition;
use App\Domain\Ledger\Ledger;
use App\Domain\OrderStatus;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FundsWallet;
use Tests\TestCase;

class LifecycleTest extends TestCase
{
    use RefreshDatabase, FundsWallet;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(Ledger::class);
    }

    /** Helper: create funded buyer, seller, listing, and locked order. */
    private function makeLockedOrder(int $qty = 2, int $unitPrice = 2_000): Order
    {
        $seller = User::factory()->create();
        $buyer  = User::factory()->create();
        $this->fundWallet($buyer, 100_000);

        $listing = Listing::factory()->create([
            'seller_id' => $seller->id,
            'unit_price_minor' => $unitPrice,
            'quantity_available' => 5,
            'currency' => 'USD',
        ]);

        return app(PurchaseOrder::class)->execute(new PurchaseOrderData(
            buyerId: $buyer->id,
            listingId: $listing->id,
            quantity: $qty,
            idempotencyKey: 'k-'.uniqid(),
        ));
    }

    // ---- MarkDelivered ----

    public function test_deliver_by_seller_transitions_to_delivered(): void
    {
        $order = $this->makeLockedOrder();
        $order = app(MarkDelivered::class)->execute($order->id, $order->seller_id);

        $this->assertSame(OrderStatus::Delivered, $order->status);
    }

    public function test_deliver_by_wrong_actor_forbidden(): void
    {
        $order = $this->makeLockedOrder();

        $this->expectException(ForbiddenActor::class);
        app(MarkDelivered::class)->execute($order->id, $order->buyer_id);
    }

    // ---- OpenDispute ----

    public function test_dispute_by_buyer_transitions_to_disputed(): void
    {
        $order = $this->makeLockedOrder();
        $order = app(OpenDispute::class)->execute($order->id, $order->buyer_id);

        $this->assertSame(OrderStatus::Disputed, $order->status);
    }

    // ---- CompleteOrder (settlement) ----

public function test_complete_settles_with_fee(): void
{
    $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000); // gross 4000
    app(MarkDelivered::class)->execute($order->id, $order->seller_id);

    $feeBps = 500; // 5%
    $order  = app(CompleteOrder::class)->execute($order->id, $order->buyer_id, $feeBps);

    $this->assertSame(OrderStatus::Completed, $order->status);
    $this->assertSame(200, $order->fee_minor);   // intdiv(4000 * 500, 10000)
    $this->assertSame(3_800, $order->net_minor);

    $wallet = $this->ledger->walletFor($order->buyer_id, 'USD');
    $this->assertSame(0, $wallet['escrow']);     // escrow released

    $sellerWallet = $this->ledger->walletFor($order->seller_id, 'USD');
    $this->assertSame(3_800, $sellerWallet['available']);

    // Revenue balance is now POSITIVE 200 under our convention
    // (balance = debits − credits; fee is booked as a debit to revenue).
    $rev = $this->ledger->account('platform', null, 'revenue', 'USD');
    $this->assertSame(200, $this->ledger->balance($rev));
}

    public function test_complete_with_zero_fee_posts_two_line_journal(): void
    {
        $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000);
        app(MarkDelivered::class)->execute($order->id, $order->seller_id);

        $order = app(CompleteOrder::class)->execute($order->id, $order->buyer_id, 0);

        $this->assertSame(0, $order->fee_minor);
        $this->assertSame(4_000, $order->net_minor);

        // Exactly 2 entries on the settlement journal.
        $journal = \App\Models\LedgerJournal::where('type', 'settlement')
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->assertSame(2, $journal->entries()->count());
    }

    // ---- RefundOrder ----

    public function test_refund_after_dispute_reverses_and_restores_stock(): void
    {
        $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000);
        $listingId = $order->listing_id;
        $stockBefore = Listing::find($listingId)->quantity_available; // 3

        app(OpenDispute::class)->execute($order->id, $order->buyer_id);
        $order = app(RefundOrder::class)->execute($order->id, $order->seller_id);

        $this->assertSame(OrderStatus::Refunded, $order->status);

        $wallet = $this->ledger->walletFor($order->buyer_id, 'USD');
        $this->assertSame(100_000, $wallet['available']);
        $this->assertSame(0, $wallet['escrow']);

        $this->assertSame($stockBefore + 2, Listing::find($listingId)->quantity_available);
    }

    // ---- CancelOrder ----

    public function test_cancel_after_escrow_lock_reverses_and_restores_stock(): void
    {
        $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000);
        $listingId = $order->listing_id;
        $stockBefore = Listing::find($listingId)->quantity_available;

        $order = app(CancelOrder::class)->execute($order->id, $order->buyer_id);

        $this->assertSame(OrderStatus::Cancelled, $order->status);

        $wallet = $this->ledger->walletFor($order->buyer_id, 'USD');
        $this->assertSame(100_000, $wallet['available']);
        $this->assertSame(0, $wallet['escrow']);

        $this->assertSame($stockBefore + 2, Listing::find($listingId)->quantity_available);
    }

    public function test_cancel_after_dispute_reverses_and_restores_stock(): void
    {
        $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000);
        $listingId = $order->listing_id;
        $stockBefore = Listing::find($listingId)->quantity_available;

        app(OpenDispute::class)->execute($order->id, $order->buyer_id);
        $order = app(CancelOrder::class)->execute($order->id, $order->buyer_id);

        $this->assertSame(OrderStatus::Cancelled, $order->status);

        $wallet = $this->ledger->walletFor($order->buyer_id, 'USD');
        $this->assertSame(100_000, $wallet['available']);
        $this->assertSame(0, $wallet['escrow']);

        $this->assertSame($stockBefore + 2, Listing::find($listingId)->quantity_available);
    }

    // ---- Illegal transition leaves everything unchanged ----

    public function test_complete_on_escrow_locked_changes_nothing(): void
    {
        $order = $this->makeLockedOrder(qty: 2, unitPrice: 2_000);

        $walletBefore = $this->ledger->walletFor($order->buyer_id, 'USD');
        $journalsBefore = \App\Models\LedgerJournal::count();

        try {
            app(CompleteOrder::class)->execute($order->id, $order->buyer_id, 500);
            $this->fail('expected IllegalTransition');
        } catch (IllegalTransition) {
        }

        $this->assertSame(OrderStatus::EscrowLocked, $order->fresh()->status);
        $this->assertSame($walletBefore, $this->ledger->walletFor($order->buyer_id, 'USD'));
        $this->assertSame($journalsBefore, \App\Models\LedgerJournal::count());
    }

    // ---- Invariant: every journal balances ----

    public function test_every_journal_balances_after_full_lifecycle(): void
    {
        $order = $this->makeLockedOrder(qty: 1, unitPrice: 1_000);
        app(MarkDelivered::class)->execute($order->id, $order->seller_id);
        app(CompleteOrder::class)->execute($order->id, $order->buyer_id, 500);

        foreach (\App\Models\LedgerJournal::with('entries')->get() as $journal) {
            $debits  = $journal->entries->where('type', 'debit')->sum('amount_minor');
            $credits = $journal->entries->where('type', 'credit')->sum('amount_minor');

            $this->assertSame($debits, $credits, "Journal {$journal->id} unbalanced");
            $this->assertGreaterThan(0, $debits);
        }
    }
}
