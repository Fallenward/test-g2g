<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class ForbiddenActor extends RuntimeException
{
    public static function make(string $expected, int $userId, int $orderId): self
    {
        return new self(
            "User {$userId} is not the {$expected} of order {$orderId}."
        );
    }
}
