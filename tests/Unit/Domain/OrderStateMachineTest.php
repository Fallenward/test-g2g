<?php

namespace Tests\Unit\Domain;

use App\Domain\Exceptions\IllegalTransition;
use App\Domain\OrderStateMachine;
use App\Domain\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderStateMachineTest extends TestCase
{
    #[DataProvider('allowedTransitions')]
    public function test_allowed(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertTrue(OrderStateMachine::canTransition($from, $to));
        OrderStateMachine::assertCanTransition($from, $to); // must not throw
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden(OrderStatus $from, OrderStatus $to): void
    {
        $this->assertFalse(OrderStateMachine::canTransition($from, $to));

        $this->expectException(IllegalTransition::class);
        OrderStateMachine::assertCanTransition($from, $to);
    }

    public static function allowedTransitions(): array
    {
        return [
            'pending->escrow_locked'   => [OrderStatus::Pending, OrderStatus::EscrowLocked],
            'pending->cancelled'       => [OrderStatus::Pending, OrderStatus::Cancelled],
            'escrow_locked->delivered' => [OrderStatus::EscrowLocked, OrderStatus::Delivered],
            'escrow_locked->disputed'  => [OrderStatus::EscrowLocked, OrderStatus::Disputed],
            'escrow_locked->cancelled' => [OrderStatus::EscrowLocked, OrderStatus::Cancelled],
            'delivered->completed'     => [OrderStatus::Delivered, OrderStatus::Completed],
            'disputed->refunded'       => [OrderStatus::Disputed, OrderStatus::Refunded],
            'disputed->cancelled'      => [OrderStatus::Disputed, OrderStatus::Cancelled],
        ];
    }

    public static function forbiddenTransitions(): array
    {
        return [
            // Skipping forward
            'pending->delivered'       => [OrderStatus::Pending, OrderStatus::Delivered],
            'pending->completed'       => [OrderStatus::Pending, OrderStatus::Completed],
            'pending->disputed'        => [OrderStatus::Pending, OrderStatus::Disputed],
            'escrow_locked->completed' => [OrderStatus::EscrowLocked, OrderStatus::Completed],

            // Backwards
            'delivered->escrow_locked' => [OrderStatus::Delivered, OrderStatus::EscrowLocked],
            'completed->delivered'     => [OrderStatus::Completed, OrderStatus::Delivered],

            // Terminal states
            'completed->cancelled' => [OrderStatus::Completed, OrderStatus::Cancelled],
            'refunded->refunded'   => [OrderStatus::Refunded, OrderStatus::Refunded],
            'cancelled->pending'   => [OrderStatus::Cancelled, OrderStatus::Pending],

            // Self-loops on intermediate states
            'escrow_locked->escrow_locked' => [OrderStatus::EscrowLocked, OrderStatus::EscrowLocked],
            'delivered->delivered'         => [OrderStatus::Delivered, OrderStatus::Delivered],
        ];
    }
}
