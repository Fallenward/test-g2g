<?php

namespace Tests\Feature;

use App\Domain\Exceptions\InsufficientStock;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Models\Listing;
use App\Models\LedgerJournal;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PurchaseRaceMysqlTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_unit_only_sells_once_across_processes(): void
    {
        $connection = env('RACE_DB_CONNECTION');

        if (! $connection) {
            $this->markTestSkipped('RACE_DB_CONNECTION is not set; skipping MySQL race test.');
        }

        config(['database.default' => $connection]);
        DB::purge();
        DB::reconnect();

        $this->artisan('migrate:fresh', ['--force' => true]);

        /** @var LedgerContract $ledger */
        $ledger = app(LedgerContract::class);

        $seller = User::factory()->create();
        $buyerIds = [];

        foreach (range(1, 3) as $_) {
            $buyer = User::factory()->create();
            $buyerIds[] = $buyer->id;

            $available = $ledger->account('user', $buyer->id, 'available', 'USD');
            $opening   = $ledger->account('platform', null, 'opening', 'USD');

            $ledger->post('opening', [
                LedgerLine::debit($available, Money::of(10_000, 'USD')),
                LedgerLine::credit($opening,  Money::of(10_000, 'USD')),
            ], 'race-fund:'.$buyer->id.':'.uniqid());
        }

        $listing = Listing::factory()->create([
            'seller_id' => $seller->id,
            'unit_price_minor' => 5_000,
            'quantity_available' => 1,
            'currency' => 'USD',
        ]);

        $processes = [];

        foreach ($buyerIds as $buyerId) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/race_purchase.php'),
                (string) $buyerId,
                (string) $listing->id,
                '1',
                'race-'.$listing->id.'-'.$buyerId,
            ]);
            $process->setEnv([
                'RACE_DB_CONNECTION' => $connection,
                'APP_ENV'            => 'testing',
            ]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        foreach ($processes as $p) {
            $p->wait();
        }

        $results = array_map(fn (Process $p) => trim($p->getOutput()), $processes);

        foreach ($results as $line) {
            $this->assertMatchesRegularExpression('/^(OK|FAIL)/', $line, "Unexpected child output: {$line}");
        }

        $successes = array_filter($results, fn (string $l) => str_starts_with($l, 'OK'));
        $failures  = array_filter($results, fn (string $l) => str_starts_with($l, 'FAIL'));

        $this->assertCount(1, $successes, 'Expected exactly one successful purchase. Output: '.implode(' | ', $results));
        $this->assertCount(2, $failures, 'Expected exactly two rejections. Output: '.implode(' | ', $results));

        foreach ($failures as $line) {
            $this->assertStringContainsString(
                InsufficientStock::class,
                $line,
                "Loser failed for wrong reason: {$line}",
            );
        }

        $this->assertSame(0, Listing::find($listing->id)->quantity_available);

        $this->assertSame(
            1,
            Order::where('listing_id', $listing->id)->where('status', 'escrow_locked')->count()
        );

        $this->assertSame(
            1,
            LedgerJournal::where('type', 'escrow_lock')
                ->whereIn('order_id', Order::where('listing_id', $listing->id)->pluck('id'))
                ->count()
        );

        foreach ($buyerIds as $buyerId) {
            $wallet = $ledger->walletFor($buyerId, 'USD');
            $this->assertGreaterThanOrEqual(0, $wallet['available'], "Buyer {$buyerId} available went negative.");
            $this->assertGreaterThanOrEqual(0, $wallet['escrow'], "Buyer {$buyerId} escrow went negative.");
        }
    }
}
