<?php

namespace Tests\Support;

use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Models\User;

trait FundsWallet
{
    protected function fundWallet(User $user, int $amountMinor, string $currency = 'USD'): void
    {
        /** @var LedgerContract $ledger */
        $ledger = app(LedgerContract::class);

        $available = $ledger->account('user', $user->id, 'available', $currency);
        $opening   = $ledger->account('platform', null, 'opening', $currency);

        $ledger->post('opening', [
            LedgerLine::debit($available, Money::of($amountMinor, $currency)),
            LedgerLine::credit($opening,  Money::of($amountMinor, $currency)),
        ], 'fund:'.$user->id.':'.$amountMinor.':'.uniqid('', true));
    }
}
