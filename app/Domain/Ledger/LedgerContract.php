<?php

namespace App\Domain\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use Illuminate\Support\Collection;

interface LedgerContract
{
    public function account(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount;

    public function findAccount(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount;

    /**
     * @param  LedgerLine[]  $lines
     */
    public function post(
        string $type,
        array $lines,
        string $idempotencyKey,
        ?int $orderId = null
    ): LedgerJournal;

    public function balance(LedgerAccount $account): int;

    public function walletFor(int $userId, string $currency): array;

    /**
     * @param  LedgerAccount[]  $accounts
     * @return Collection<int, LedgerAccount>
     */
    public function lockAccounts(array $accounts): Collection;
}
