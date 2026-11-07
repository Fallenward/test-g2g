<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'buyer_id'         => $this->buyer_id,
            'seller_id'        => $this->seller_id,
            'listing_id'       => $this->listing_id,
            'quantity'         => $this->quantity,
            'unit_price_minor' => $this->unit_price_minor,
            'gross_minor'      => $this->gross_minor,
            'fee_minor'        => $this->fee_minor,
            'net_minor'        => $this->net_minor,
            'currency'         => $this->currency,
            'status'           => $this->status->value,
            'created_at'       => $this->created_at?->toIso8601String(),
        ];
    }
}
