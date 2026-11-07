<?php

namespace App\Domain\Exceptions;

use App\Domain\OrderStatus;
use RuntimeException;

class IllegalTransition extends RuntimeException
{
    public static function make(OrderStatus $from, OrderStatus $to): self
    {
        return new self(
            "Illegal order transition: {$from->value} -> {$to->value}."
        );
    }
}
