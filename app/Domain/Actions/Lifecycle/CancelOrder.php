<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStateMachine;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Listing;
use App\Models\Order;

final class CancelOrder
{
    public function __construct(private readonly PostReversal $reversal) {}

    public function execute(int $orderId, int $actorId): Order
    {
        return TransactionRetry::run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            OrderStateMachine::assertCanTransition($order->status, OrderStatus::Cancelled);

            if ($order->buyer_id !== $actorId) {
                throw ForbiddenActor::make('buyer', $actorId, $order->id);
            }

            // Money was locked for escrow_locked and disputed. Cancel from
            // those states must reverse funds and restore stock.
            $moneyWasLocked = in_array($order->status, [
                OrderStatus::EscrowLocked,
                OrderStatus::Disputed,
            ], true);

            if ($moneyWasLocked) {
                Listing::whereKey($order->listing_id)->lockForUpdate()->firstOrFail();

                $this->reversal->execute($order, "cancel_refund:{$order->id}");
            }

            // pending → cancelled: no money moved, no stock consumed, nothing
            // to restore. Just flip the status.

            $order->status = OrderStatus::Cancelled;
            $order->save();

            return $order;
        });
    }
}
