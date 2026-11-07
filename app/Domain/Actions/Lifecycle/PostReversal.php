<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Models\Listing;
use App\Models\Order;

final class PostReversal
{
    public function __construct(private readonly LedgerContract $ledger) {}

    public function execute(Order $order, string $journalIdempotencyKey): void
    {
        $currency = $order->currency;
        $gross    = $order->gross_minor;

        $buyerEscrow = $this->ledger->account('user', $order->buyer_id, 'escrow', $currency);
        $buyerAvail  = $this->ledger->account('user', $order->buyer_id, 'available', $currency);

        $this->ledger->lockAccounts([$buyerEscrow, $buyerAvail]);

        $this->ledger->post(
            type: 'refund',
            lines: [
                LedgerLine::debit($buyerAvail,  Money::of($gross, $currency)),
                LedgerLine::credit($buyerEscrow, Money::of($gross, $currency)),
            ],
            idempotencyKey: $journalIdempotencyKey,
            orderId: $order->id,
        );

        Listing::whereKey($order->listing_id)
            ->increment('quantity_available', $order->quantity);
    }
}
