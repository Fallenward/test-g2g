<?php

namespace App\Domain;

enum OrderStatus: string
{
    case Pending = 'pending';
    case EscrowLocked = 'escrow_locked';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Disputed = 'disputed';
    case Refunded = 'refunded';
    case Cancelled = 'cancelled';

    /**
     * Terminal states have no outgoing transitions.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Refunded,
            self::Cancelled,
        ], true);
    }
}
