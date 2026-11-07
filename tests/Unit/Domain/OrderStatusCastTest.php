<?php

namespace Tests\Unit\Domain;

use App\Domain\OrderStatus;
use App\Models\Order;
use PHPUnit\Framework\TestCase;

class OrderStatusCastTest extends TestCase
{
    public function test_status_is_enum(): void
    {
        $order = new Order(['status' => OrderStatus::EscrowLocked]);

        $this->assertInstanceOf(OrderStatus::class, $order->status);
        $this->assertSame(OrderStatus::EscrowLocked, $order->status);
    }

    public function test_terminal_states(): void
    {
        $this->assertTrue(OrderStatus::Completed->isTerminal());
        $this->assertTrue(OrderStatus::Refunded->isTerminal());
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());

        $this->assertFalse(OrderStatus::Pending->isTerminal());
        $this->assertFalse(OrderStatus::EscrowLocked->isTerminal());
        $this->assertFalse(OrderStatus::Delivered->isTerminal());
        $this->assertFalse(OrderStatus::Disputed->isTerminal());
    }
}
