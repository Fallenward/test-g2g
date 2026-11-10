<?php

namespace App\Domain\Actions\Lifecycle;

use App\Domain\Exceptions\ForbiddenActor;
use App\Domain\OrderStatus;
use App\Domain\Support\TransactionRetry;
use App\Models\Order;

final class OpenDispute
{
    public function __construct(private readonly TransactionRetry $transactions) {}

    public function execute(int $orderId, int $actorId): Order
    {
        return $this->transactions->run(function () use ($orderId, $actorId) {
            $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            $order->transitionTo(OrderStatus::Disputed);

            if ($order->buyer_id !== $actorId) {
                throw ForbiddenActor::make('buyer', $actorId, $order->id);
            }

            $order->save();

            return $order;
        });
    }
}
