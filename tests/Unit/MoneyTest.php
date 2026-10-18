<?php

namespace Tests\Unit;

// ══════════════════════════════════════════════════════════════════
//  MoneyTest — the rounding helper used by all money calculations.
//  Guards the bug where amounts were cut off (99.995 → 99.99) instead
//  of rounded (→ 100.00), which lost cents on every transaction.
//
//  @layer Test
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_rounds_half_up_instead_of_truncating(): void
    {
        $this->assertSame('100.00', Money::round('99.995'));
        $this->assertSame('2000.00', Money::round('1999.995'));
        $this->assertSame('0.01', Money::round('0.005'));
    }

    public function test_rounds_down_below_half(): void
    {
        $this->assertSame('99.99', Money::round('99.994'));
        $this->assertSame('0.00', Money::round('0.004'));
    }

    public function test_negative_numbers_round_away_from_zero(): void
    {
        $this->assertSame('-100.00', Money::round('-99.995'));
        $this->assertSame('-99.99', Money::round('-99.994'));
    }

    public function test_negative_zero_is_shown_as_zero(): void
    {
        $this->assertSame('0.00', Money::round('-0.001'));
    }

    public function test_always_returns_the_requested_decimal_places(): void
    {
        $this->assertSame('5.00', Money::round('5'));
        $this->assertSame('5.0000', Money::round('5', 4));
        $this->assertSame('12.3457', Money::round('12.34565', 4));
    }

    public function test_accepts_floats_ints_and_null(): void
    {
        $this->assertSame('10.50', Money::round(10.5));
        $this->assertSame('7.00', Money::round(7));
        $this->assertSame('0.00', Money::round(null));
    }

    public function test_float_output_variant(): void
    {
        $this->assertSame(100.0, Money::roundFloat('99.995'));
    }

    public function test_large_amounts_keep_exact_cents(): void
    {
        $this->assertSame('123456789.13', Money::round('123456789.125'));
    }
}
