<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class UnbalancedJournal extends RuntimeException
{
    public static function debitsCredits(int $debits, int $credits): self
    {
        return new self(
            "Journal is unbalanced: debits {$debits} != credits {$credits}."
        );
    }

    public static function emptyJournal(): self
    {
        return new self('A journal must contain at least one debit and one credit.');
    }

    public static function nonPositiveAmount(int $amount): self
    {
        return new self("Entry amount must be positive, got {$amount}.");
    }

    public static function mixedCurrencies(): self
    {
        return new self('All entries in a journal must share one currency.');
    }
}
