<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class InsufficientStock extends RuntimeException
{
    public static function make(int $listingId, int $requested, int $available): self
    {
        return new self(
            "Insufficient stock on listing {$listingId}: requested {$requested}, available {$available}."
        );
    }
}
