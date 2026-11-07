<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class AccountNotFound extends RuntimeException
{
    public static function make(string $ownerType, ?int $ownerId, string $code, string $currency): self
    {
        return new self(
            "Ledger account not found: {$ownerType}#{$ownerId} {$code} {$currency}."
        );
    }
}
