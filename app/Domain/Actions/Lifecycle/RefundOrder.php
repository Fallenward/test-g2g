<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Listing;
use App\Models\Order;

final class RefundOrder
{
    public function __construct(
        private readonly PostReversal $reversal,
        private readonly TransactionRetry $transactions,
    ) {}

    public function execute(int $orderId, int $actorId): Order
    {
        return $this->transactions->run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            $order->transitionTo(OrderStatus::Refunded);

            if ($order->seller_id !== $actorId) {
                throw ForbiddenActor::make('seller', $actorId, $order->id);
            }

            Listing::whereKey($order->listing_id)->lockForUpdate()->firstOrFail();

            $this->reversal->execute($order, "refund:{$order->id}");

            $order->save();

            return $order;
        });
    }
}
