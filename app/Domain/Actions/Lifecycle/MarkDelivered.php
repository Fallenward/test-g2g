<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Order;

final class MarkDelivered
{
    public function __construct(private readonly TransactionRetry $transactions) {}

    public function execute(int $orderId, int $actorId): Order
    {
        return $this->transactions->run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            $order->transitionTo(OrderStatus::Delivered);

            if ($order->seller_id !== $actorId) {
                throw ForbiddenActor::make('seller', $actorId, $order->id);
            }

            $order->save();

            return $order;
        });
    }
}
