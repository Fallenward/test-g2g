<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class InsufficientFunds extends RuntimeException
{
    public static function make(string $currency, int $required, int $available): self
    {
        return new self(
            "Insufficient funds: required {$required} {$currency}, available {$available} {$currency}."
        );
    }
}
