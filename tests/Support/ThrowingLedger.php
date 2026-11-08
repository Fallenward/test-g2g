<?php

namespace Tests\Support;

use App\Domain\Ledger\LedgerContract;
use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use Illuminate\Support\Collection;
use RuntimeException;

class ThrowingLedger implements LedgerContract
{
    public function __construct(
        private readonly LedgerContract $inner,
        private readonly string $when = 'any',
    ) {}

    public function account(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount {
        return $this->inner->account($ownerType, $ownerId, $code, $currency);
    }

    public function findAccount(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount {
        return $this->inner->findAccount($ownerType, $ownerId, $code, $currency);
    }

    public function post(
        string $type,
        array $lines,
        string $idempotencyKey,
        ?int $orderId = null
    ): LedgerJournal {
        if ($this->when === 'any' || $this->when === $type) {
            throw new RuntimeException("Simulated ledger failure posting type {$type}.");
        }

        return $this->inner->post($type, $lines, $idempotencyKey, $orderId);
    }

    public function balance(LedgerAccount $account): int
    {
        return $this->inner->balance($account);
    }

    public function walletFor(int $userId, string $currency): array
    {
        return $this->inner->walletFor($userId, $currency);
    }

    public function lockAccounts(array $accounts): Collection
    {
        return $this->inner->lockAccounts($accounts);
    }
}
