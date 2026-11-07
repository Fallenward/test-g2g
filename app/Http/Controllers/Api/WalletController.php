<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\LedgerContract;
use App\Http\Controllers\Controller;
use App\Http\Resources\WalletResource;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private readonly LedgerContract $ledger) {}

    public function show(Request $request): WalletResource
    {
        $currency = strtoupper((string) config('escrow.currency', 'USD'));

        return new WalletResource(
            $this->ledger->walletFor($request->user()->id, $currency)
        );
    }
}
