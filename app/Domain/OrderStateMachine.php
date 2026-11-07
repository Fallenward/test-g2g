<?php

namespace App\Domain;

use App\Domain\Exceptions\IllegalTransition;

final class OrderStateMachine
{
    /**
     * The single source of truth for allowed transitions.
     *
     * Pending       -> Escrow_Locked
     * Pending       -> Cancelled
     * Escrow_Locked -> Delivered
     * Escrow_Locked -> Disputed
     * Escrow_Locked -> Cancelled
     * Delivered     -> Completed
     * Disputed      -> Refunded
     * Disputed      -> Cancelled
     */
    private const MAP = [
        'pending' => [
            'escrow_locked',
            'cancelled',
        ],
        'escrow_locked' => [
            'delivered',
            'disputed',
            'cancelled',
        ],
        'delivered' => [
            'completed',
        ],
        'disputed' => [
            'refunded',
            'cancelled',
        ],
        'completed' => [],
        'refunded' => [],
        'cancelled' => [],
    ];

    public static function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::MAP[$from->value], true);
    }

    /**
     * Throws IllegalTransition if the move is not permitted.
     * Call this before any write in a lifecycle action.
     */
    public static function assertCanTransition(OrderStatus $from, OrderStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw IllegalTransition::make($from, $to);
        }
    }
}
