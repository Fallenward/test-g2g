<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Listing;
use App\Models\Order;

final class CompleteOrder
{
    public function __construct(
        private readonly LedgerContract $ledger,
        private readonly TransactionRetry $transactions,
    ) {}

    public function execute(int $orderId, int $actorId, int $feeBps): Order
    {
        return $this->transactions->run(function () use ($orderId, $actorId, $feeBps) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            $order->transitionTo(OrderStatus::Completed);

            if ($order->buyer_id !== $actorId) {
                throw ForbiddenActor::make('buyer', $actorId, $order->id);
            }

            Listing::whereKey($order->listing_id)->lockForUpdate()->firstOrFail();

            $currency = $order->currency;
            $gross = $order->gross_minor;
            $fee = intdiv($gross * $feeBps, 10_000);
            $net = $gross - $fee;

            $buyerEscrow = $this->ledger->account('user', $order->buyer_id, 'escrow', $currency);
            $sellerAvail = $this->ledger->account('user', $order->seller_id, 'available', $currency);
            $platformRev = $this->ledger->account('platform', null, 'revenue', $currency);

            $this->ledger->lockAccounts([$buyerEscrow, $sellerAvail, $platformRev]);

            $lines = [
                LedgerLine::credit($buyerEscrow, Money::of($gross, $currency)),
                LedgerLine::debit($sellerAvail, Money::of($net, $currency)),
            ];

            if ($fee > 0) {
                $lines[] = LedgerLine::debit($platformRev, Money::of($fee, $currency));
            }

            $this->ledger->post(
                type: 'settlement',
                lines: $lines,
                idempotencyKey: "settlement:{$order->id}",
                orderId: $order->id,
            );

            $order->fee_minor = $fee;
            $order->net_minor = $net;
            $order->save();

            return $order;
        });
    }
}
