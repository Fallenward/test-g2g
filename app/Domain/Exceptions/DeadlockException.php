<?php

namespace App\Domain\Exceptions;

use RuntimeException;

class DeadlockException extends RuntimeException
{
    public static function from(\Throwable $previous): self
    {
        return new self('Transaction deadlock, retry.', 0, $previous);
    }
}
