<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStateMachine;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Order;

final class MarkDelivered
{
    public function execute(int $orderId, int $actorId): Order
    {
        return TransactionRetry::run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            OrderStateMachine::assertCanTransition($order->status, OrderStatus::Delivered);

            if ($order->seller_id !== $actorId) {
                throw ForbiddenActor::make('seller', $actorId, $order->id);
            }

            $order->status = OrderStatus::Delivered;
            $order->save();

            return $order;
        });
    }
}
