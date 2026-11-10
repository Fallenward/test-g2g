<?php

namespace Tests\Unit\Ledger;

use App\Domain\Exceptions\UnbalancedJournal;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(Ledger::class);
    }

    public function test_balanced_post(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $escrow = $this->ledger->account('user', $user->id, 'escrow', 'USD');

        $this->ledger->post('escrow_lock', [
            LedgerLine::debit($escrow, Money::of(1000, 'USD')),
            LedgerLine::credit($available, Money::of(1000, 'USD')),
        ], 'test-1');

        $this->assertSame(-1000, $this->ledger->balance($available));
        $this->assertSame(1000, $this->ledger->balance($escrow));
    }

    public function test_unbalanced_post_rejected(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');

        $this->expectException(UnbalancedJournal::class);

        $this->ledger->post('escrow_lock', [
            LedgerLine::debit($available, Money::of(1000, 'USD')),
            LedgerLine::credit($available, Money::of(500, 'USD')),
        ], 'test-2');
    }

    public function test_derived_available_and_escrow(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $escrow = $this->ledger->account('user', $user->id, 'escrow', 'USD');

        // Fund
        $this->ledger->post('opening', [
            LedgerLine::debit($available, Money::of(5000, 'USD')),
            LedgerLine::credit(
                $this->ledger->account('platform', null, 'opening', 'USD'),
                Money::of(5000, 'USD')
            ),
        ], 'fund-1');

        // Lock
        $this->ledger->post('escrow_lock', [
            LedgerLine::debit($escrow, Money::of(2000, 'USD')),
            LedgerLine::credit($available, Money::of(2000, 'USD')),
        ], 'lock-1');

        $wallet = $this->ledger->walletFor($user->id, 'USD');
        $this->assertSame(3000, $wallet['available']);
        $this->assertSame(2000, $wallet['escrow']);
    }

    public function test_entry_mutation_rejected(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $platform = $this->ledger->account('platform', null, 'opening', 'USD');

        $journal = $this->ledger->post('opening', [
            LedgerLine::debit($available, Money::of(1000, 'USD')),
            LedgerLine::credit($platform, Money::of(1000, 'USD')),
        ], 'mut-1');

        $entry = $journal->entries()->first();

        $this->expectException(\RuntimeException::class);
        $entry->update(['amount_minor' => 999]);
    }

    public function test_second_platform_account_with_null_owner_is_rejected(): void
    {
        LedgerAccount::create([
            'owner_type' => 'platform',
            'owner_id' => null,
            'code' => 'revenue',
            'currency' => 'USD',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        LedgerAccount::create([
            'owner_type' => 'platform',
            'owner_id' => null,
            'code' => 'revenue',
            'currency' => 'USD',
        ]);
    }

    public function test_entry_delete_rejected(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $platform = $this->ledger->account('platform', null, 'opening', 'USD');

        $journal = $this->ledger->post('opening', [
            LedgerLine::debit($available, Money::of(1000, 'USD')),
            LedgerLine::credit($platform, Money::of(1000, 'USD')),
        ], 'mut-2');

        $entry = $journal->entries()->first();

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }

    public function test_database_rejects_a_second_platform_account_with_the_same_identity(): void
    {
        $row = [
            'owner_type' => 'platform',
            'owner_id' => null,
            'code' => 'revenue',
            'currency' => 'USD',
            'owner_key' => 'platform:0:revenue:USD',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('ledger_accounts')->insert($row);

        $this->assertSame(
            'platform:0:revenue:USD',
            DB::table('ledger_accounts')->where('code', 'revenue')->where('currency', 'USD')->value('owner_key')
        );

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('ledger_accounts')->insert($row);
    }

    public function test_query_builder_cannot_update_a_ledger_entry(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $platform = $this->ledger->account('platform', null, 'opening', 'USD');

        $journal = $this->ledger->post('opening', [
            LedgerLine::debit($available, Money::of(1000, 'USD')),
            LedgerLine::credit($platform, Money::of(1000, 'USD')),
        ], 'mut-3');

        $entry = $journal->entries()->first();

        try {
            LedgerEntry::query()->whereKey($entry->id)->update(['amount_minor' => 1]);
            $this->fail('Expected the database to reject the update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        $this->assertSame(1000, $entry->fresh()->amount_minor);
    }

    public function test_database_rejects_a_non_positive_ledger_entry(): void
    {
        $user = User::factory()->create();
        $available = $this->ledger->account('user', $user->id, 'available', 'USD');
        $platform = $this->ledger->account('platform', null, 'opening', 'USD');

        $journal = $this->ledger->post('opening', [
            LedgerLine::debit($available, Money::of(1000, 'USD')),
            LedgerLine::credit($platform, Money::of(1000, 'USD')),
        ], 'mut-4');

        $this->expectException(QueryException::class);

        DB::table('ledger_entries')->insert([
            'journal_id' => $journal->id,
            'account_id' => $available->id,
            'type' => 'debit',
            'amount_minor' => 0,
        ]);
    }
}
