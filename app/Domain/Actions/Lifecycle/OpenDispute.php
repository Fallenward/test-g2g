<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStateMachine;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Order;

final class OpenDispute
{
    public function execute(int $orderId, int $actorId): Order
    {
        return TransactionRetry::run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            OrderStateMachine::assertCanTransition($order->status, OrderStatus::Disputed);

            if ($order->buyer_id !== $actorId) {
                throw ForbiddenActor::make('buyer', $actorId, $order->id);
            }

            $order->status = OrderStatus::Disputed;
            $order->save();

            return $order;
        });
    }
}
