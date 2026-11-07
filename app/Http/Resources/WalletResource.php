<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // $this is the array returned by Ledger::walletFor()
        return [
            'currency'  => $this['currency'],
            'available' => $this['available'],
            'escrow'    => $this['escrow'],
        ];
    }
}
