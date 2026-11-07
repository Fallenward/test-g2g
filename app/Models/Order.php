<?php

namespace App\Models;

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

    protected $casts = [
        'quantity' => 'integer',
        'unit_price_minor' => 'integer',
        'gross_minor' => 'integer',
        'fee_minor' => 'integer',
        'net_minor' => 'integer',
        'status' => \App\Domain\OrderStatus::class,

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
}
