<?php

namespace Tests\Feature;

use App\Domain\Exceptions\InsufficientStock;
use App\Domain\Ledger\LedgerContract;
use App\Domain\Ledger\LedgerLine;
use App\Domain\Ledger\Money;
use App\Models\LedgerJournal;
use App\Models\Listing;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PurchaseRaceMysqlTest extends TestCase
{
    public function test_last_unit_only_sells_once_across_processes(): void
    {
        $connection = $this->resolveRaceConnection();

        if (! $connection) {
            $this->markTestSkipped(
                'No race DB available. Set RACE_DB_CONNECTION, or start a local MySQL/Postgres '
                .'server reachable via the mysql_race/pgsql_race connections.'
            );
        }

        // Point the app at the race DB.
        config(['database.default' => $connection]);
        DB::purge($connection);
        DB::reconnect($connection);

        // Set up the schema on the race DB.
        Artisan::call('migrate:fresh', ['--force' => true]);

        /** @var LedgerContract $ledger */
        $ledger = app(LedgerContract::class);

        $seller = User::factory()->create();
        $buyerIds = [];

        foreach (range(1, 3) as $_) {
            $buyer = User::factory()->create();
            $buyerIds[] = $buyer->id;

            $available = $ledger->account('user', $buyer->id, 'available', 'USD');
            $opening = $ledger->account('platform', null, 'opening', 'USD');

            $ledger->post('opening', [
                LedgerLine::debit($available, Money::of(10_000, 'USD')),
                LedgerLine::credit($opening, Money::of(10_000, 'USD')),
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
                'APP_ENV' => 'testing',
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
        $failures = array_filter($results, fn (string $l) => str_starts_with($l, 'FAIL'));

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

    /**
     * Return a DB connection name safe to run the race test against, or null
     * if none is available (in which case the test skips).
     *
     * Resolution order:
     *   1. RACE_DB_CONNECTION env var (explicit opt-in).
     *   2. Any of the *_race connections whose server is reachable.
     *
     * If the target database is missing but the server is reachable, we try
     * to create it (MySQL/Postgres) so local devs don't need a manual setup
     * step. If creation isn't possible (no permissions), we fall through to
     * skip.
     */
    private function resolveRaceConnection(): ?string
    {
        if ($explicit = env('RACE_DB_CONNECTION')) {
            return $this->ensureConnectionUsable($explicit) ? $explicit : null;
        }

        foreach (['mysql_race', 'pgsql_race'] as $candidate) {
            if (! config("database.connections.{$candidate}")) {
                continue;
            }
            if ($this->ensureConnectionUsable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * True if the connection can reach its database, possibly after auto-creating it.
     */
    private function ensureConnectionUsable(string $connection): bool
    {
        try {
            DB::connection($connection)->getPdo();

            return true;
        } catch (\Throwable $e) {
            if ($this->isUnknownDatabase($e) && $this->tryCreateDatabase($connection)) {
                try {
                    DB::purge($connection);
                    DB::connection($connection)->getPdo();

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            }

            return false;
        }
    }

    private function isUnknownDatabase(\Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'Unknown database')
            || str_contains($msg, 'does not exist')
            || str_contains($msg, 'database "');  // postgres phrasing
    }

    private function tryCreateDatabase(string $connection): bool
    {
        $config = config("database.connections.{$connection}");
        if (! $config || empty($config['database'])) {
            return false;
        }

        $driver = $config['driver'] ?? null;
        $dbName = $config['database'];

        try {
            if ($driver === 'mysql') {
                $dsn = "mysql:host={$config['host']};port={$config['port']}";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_TIMEOUT => 2]);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                return true;
            }

            if ($driver === 'pgsql') {
                $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname=postgres";
                $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_TIMEOUT => 2]);
                $pdo->exec("CREATE DATABASE \"{$dbName}\"");

                return true;
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }
}
