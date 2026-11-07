<?php

namespace App\Domain\Ledger;

use InvalidArgumentException;

final class Money
{
    private function __construct(
        public readonly string $currency,
        public readonly int $amountMinor,
    ) {}

    public static function of(int $amountMinor, string $currency): self
    {
        if ($amountMinor < 0) {
            throw new InvalidArgumentException('Money cannot be negative.');
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter code.');
        }

        return new self(strtoupper($currency), $amountMinor);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->currency, $this->amountMinor + $other->amountMinor);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amountMinor > $this->amountMinor) {
            throw new InvalidArgumentException('Result would be negative.');
        }

        return new self($this->currency, $this->amountMinor - $other->amountMinor);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException('Factor cannot be negative.');
        }

        return new self($this->currency, $this->amountMinor * $factor);
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->amountMinor === $other->amountMinor;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}."
            );
        }
    }
}
