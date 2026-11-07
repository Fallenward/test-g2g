<?php

namespace App\Domain\Ledger;

use App\Domain\Exceptions\AccountNotFound;
use App\Domain\Exceptions\UnbalancedJournal;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Illuminate\Support\Collection;

final class Ledger implements LedgerContract
{
    public function account(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount {
        return LedgerAccount::firstOrCreate([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'code' => $code,
            'currency' => strtoupper($currency),
        ]);
    }

    public function findAccount(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount {
        $account = LedgerAccount::where([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'code' => $code,
            'currency' => strtoupper($currency),
        ])->first();

        if (! $account) {
            throw AccountNotFound::make($ownerType, $ownerId, $code, $currency);
        }

        return $account;
    }

    public function post(
        string $type,
        array $lines,
        string $idempotencyKey,
        ?int $orderId = null
    ): LedgerJournal {
        $this->assertBalanced($lines);

        $journal = LedgerJournal::create([
            'type' => $type,
            'order_id' => $orderId,
            'idempotency_key' => $idempotencyKey,
        ]);

        foreach ($lines as $line) {
            LedgerEntry::create([
                'journal_id' => $journal->id,
                'account_id' => $line->account->id,
                'type' => $line->type,
                'amount_minor' => $line->money->amountMinor,
            ]);
        }

        return $journal;
    }

    public function balance(LedgerAccount $account): int
    {
        $debits = (int) LedgerEntry::where('account_id', $account->id)
            ->where('type', 'debit')
            ->sum('amount_minor');

        $credits = (int) LedgerEntry::where('account_id', $account->id)
            ->where('type', 'credit')
            ->sum('amount_minor');

        return $debits - $credits;
    }

    public function walletFor(int $userId, string $currency): array
    {
        $currency = strtoupper($currency);

        $available = $this->balance(
            $this->account('user', $userId, 'available', $currency)
        );
        $escrow = $this->balance(
            $this->account('user', $userId, 'escrow', $currency)
        );

        return [
            'currency' => $currency,
            'available' => $available,
            'escrow' => $escrow,
        ];
    }

    public function lockAccounts(array $accounts): Collection
    {
        $ids = collect($accounts)->pluck('id')->sort()->values()->all();

        return LedgerAccount::whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function assertBalanced(array $lines): void
    {
        if (count($lines) < 2) {
            throw UnbalancedJournal::emptyJournal();
        }

        $debits = 0;
        $credits = 0;
        $currency = null;

        foreach ($lines as $line) {
            $amount = $line->money->amountMinor;

            if ($amount <= 0) {
                throw UnbalancedJournal::nonPositiveAmount($amount);
            }

            $currency ??= $line->money->currency;

            if ($line->money->currency !== $currency) {
                throw UnbalancedJournal::mixedCurrencies();
            }

            if ($line->type === 'debit') {
                $debits += $amount;
            } elseif ($line->type === 'credit') {
                $credits += $amount;
            } else {
                throw new \InvalidArgumentException("Unknown entry type: {$line->type}");
            }
        }

        if ($debits !== $credits) {
            throw UnbalancedJournal::debitsCredits($debits, $credits);
        }

        if ($debits === 0) {
            throw UnbalancedJournal::emptyJournal();
        }
    }
}
