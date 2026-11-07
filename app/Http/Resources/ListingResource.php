<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Listing */
class ListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'seller_id'           => $this->seller_id,
            'title'               => $this->title,
            'unit_price_minor'    => $this->unit_price_minor,
            'currency'            => $this->currency,
            'quantity_available'  => $this->quantity_available,
            'status'              => $this->status,
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
