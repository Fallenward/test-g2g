<?php

namespace App\Domain\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerJournal;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function openOrFetch(
        string $ownerType,
        ?int $ownerId,
        string $code,
        string $currency
    ): LedgerAccount {
        return LedgerAccount::firstOrCreate([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'code' => $code,
            'currency' => $currency,
        ]);
    }

    public function postOpeningBalance(
        LedgerAccount $account,
        int $amountMinor,
        string $idempotencyKey
    ): void {
        DB::transaction(function () use ($account, $amountMinor, $idempotencyKey) {
            $journal = LedgerJournal::create([
                'type' => 'opening',
                'order_id' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Debit the account (increase available balance)
            LedgerEntry::create([
                'journal_id' => $journal->id,
                'account_id' => $account->id,
                'type' => 'debit',
                'amount_minor' => $amountMinor,
            ]);

            // Credit a platform "equity" or "opening" account to balance
            $offset = $this->openOrFetch('platform', null, 'opening', $account->currency);
            LedgerEntry::create([
                'journal_id' => $journal->id,
                'account_id' => $offset->id,
                'type' => 'credit',
                'amount_minor' => $amountMinor,
            ]);
        });
    }
}
