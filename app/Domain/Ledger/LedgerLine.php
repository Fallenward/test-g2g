<?php

namespace App\Domain\Ledger;

use App\Models\LedgerAccount;

final class LedgerLine
{
    public function __construct(
        public readonly LedgerAccount $account,
        public readonly string $type, // 'debit' | 'credit'
        public readonly Money $money,
    ) {}

    public static function debit(LedgerAccount $account, Money $money): self
    {
        return new self($account, 'debit', $money);
    }

    public static function credit(LedgerAccount $account, Money $money): self
    {
        return new self($account, 'credit', $money);
    }
}
