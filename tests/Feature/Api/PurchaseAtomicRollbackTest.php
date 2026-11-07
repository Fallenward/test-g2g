<?php

namespace Tests\Feature;

use App\Domain\Actions\PurchaseOrder\PurchaseOrder;
use App\Domain\Actions\PurchaseOrder\PurchaseOrderData;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\LedgerContract;
use App\Models\Listing;
use App\Models\LedgerJournal;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FundsWallet;
use Tests\Support\ThrowingLedger;
use Tests\TestCase;

class PurchaseAtomicRollbackTest extends TestCase
{
    use RefreshDatabase, FundsWallet;

    public function test_purchase_rolls_back_when_journal_post_fails(): void
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

        $stockBefore    = $listing->fresh()->quantity_available;
        $ordersBefore   = Order::count();
        $journalsBefore = LedgerJournal::count();

        $this->app->instance(
            LedgerContract::class,
            new ThrowingLedger(
                inner: new Ledger(),
                when: 'escrow_lock',
            ),
        );

        $action = $this->app->make(PurchaseOrder::class);

        try {
            $action->execute(new PurchaseOrderData(
                buyerId: $buyer->id,
                listingId: $listing->id,
                quantity: 2,
                idempotencyKey: 'atomic-1',
            ));
            $this->fail('Expected Ledger::post to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated ledger failure', $e->getMessage());
        }

        $this->assertSame($stockBefore, $listing->fresh()->quantity_available,
            'Listing quantity must be restored after rollback.');
        $this->assertSame($ordersBefore, Order::count(),
            'No order row should be persisted after rollback.');
        $this->assertSame($journalsBefore, LedgerJournal::count(),
            'No journal should be persisted after rollback.');

        $wallet = (new Ledger())->walletFor($buyer->id, 'USD');
        $this->assertSame(10_000, $wallet['available']);
        $this->assertSame(0, $wallet['escrow']);
    }
}
