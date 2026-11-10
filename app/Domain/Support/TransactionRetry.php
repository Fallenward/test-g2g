<?php

namespace App\Domain\Support;

use App\Domain\Exceptions\DeadlockException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TransactionRetry
{
    /**
     * Run $work inside DB::transaction(), retrying the whole transaction
     * on deadlock (SQLSTATE 40001 / MySQL 1213) up to $attempts times.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work, int $attempts = 3): mixed
    {
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return DB::transaction($work);
            } catch (Throwable $e) {
                if (! $this->isDeadlock($e)) {
                    throw $e;
                }

                $last = $e;

                // Small jittered backoff so racing workers don't line up again.
                usleep(random_int(10_000, 50_000) * $i);
            }
        }

        throw DeadlockException::from($last);
    }

    private function isDeadlock(Throwable $e): bool
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        // SQLSTATE 40001 = serialization failure / deadlock
        if ($code === '40001' || $code === 40001) {
            return true;
        }

        // MySQL deadlock
        if (str_contains($message, '1213')) {
            return true;
        }

        // SQLite "database is locked" — race test with processes will hit this
        if (str_contains($message, 'database is locked')) {
            return true;
        }

        // PostgreSQL
        if (str_contains($message, 'deadlock detected')) {
            return true;
        }

        return false;
    }
}
