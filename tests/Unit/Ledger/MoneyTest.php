<?php

namespace Tests\Unit\Ledger;

use App\Domain\Ledger\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_add(): void
    {
        $sum = Money::of(100, 'USD')->add(Money::of(50, 'USD'));
        $this->assertSame(150, $sum->amountMinor);
        $this->assertSame('USD', $sum->currency);
    }

    public function test_subtract(): void
    {
        $diff = Money::of(100, 'USD')->subtract(Money::of(40, 'USD'));
        $this->assertSame(60, $diff->amountMinor);
    }

    public function test_subtract_rejects_negative_result(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(10, 'USD')->subtract(Money::of(20, 'USD'));
    }

    public function test_rejects_mixed_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(10, 'USD')->add(Money::of(10, 'EUR'));
    }

    public function test_rejects_negative_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(-1, 'USD');
    }
}
