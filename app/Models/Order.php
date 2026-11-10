<?php

namespace App\Models;

use App\Domain\OrderStateMachine;
use App\Domain\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'buyer_id', 'seller_id', 'listing_id', 'quantity',
        'unit_price_minor', 'gross_minor', 'fee_minor', 'net_minor',
        'currency', 'status', 'idempotency_key',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'gross_minor' => 'integer',
        'fee_minor' => 'integer',
        'net_minor' => 'integer',
        'status' => OrderStatus::class,

    ];

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Move the order to $to when the state machine allows it.
     * A saving hook checks the same rule, so a direct status write cannot skip it.
     */
    public function transitionTo(OrderStatus $to): void
    {
        $from = $this->status instanceof OrderStatus
            ? $this->status
            : OrderStatus::from((string) $this->status);

        OrderStateMachine::assertCanTransition($from, $to);

        $this->status = $to;
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order): void {
            if (! $order->isDirty('status')) {
                return;
            }

            $to = $order->status instanceof OrderStatus
                ? $order->status
                : OrderStatus::from((string) $order->status);

            if (! $order->exists) {
                $from = OrderStatus::Pending;
            } else {
                $original = $order->getOriginal('status');
                $from = $original instanceof OrderStatus
                    ? $original
                    : OrderStatus::from((string) $original);
            }

            if ($from === $to) {
                return;
            }

            OrderStateMachine::assertCanTransition($from, $to);
        });
    }
}
