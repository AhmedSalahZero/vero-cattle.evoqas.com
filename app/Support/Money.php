<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  VERO — Money helper
//
//  📌 METHODOLOGY REFERENCE: BcmathMonetaryPrecision (#38)
//
//  WHY THIS EXISTS
//  PHP's bcadd($x, '0', 2) does NOT round — it cuts (truncates) the extra
//  digits. So 99.999 became 99.99 instead of 100.00, and a repeating
//  33.3333… × 30 became 999.99 instead of 1000.00. Every cent lost this way
//  is a cent that is missing from the books.
//
//  Money::round() rounds "half away from zero" (the normal accounting
//  rounding: 0.005 → 0.01, -0.005 → -0.01) using bcmath only — no floats.
//
//  Formula:   round(x, s) = trunc( x + sign(x) · 0.5 · 10^(−s), s )
//
//  Edge cases: float input is converted without scientific notation;
//              a result of "-0.00" is normalised to "0.00".
// ══════════════════════════════════════════════════════════════════

class Money
{
    /**
     * Round a numeric value to $scale decimals, half away from zero.
     * Returns a bcmath-style numeric string.
     */
    public static function round(string|int|float|null $value, int $scale = 2): string
    {
        $v = self::toString($value);

        // Offset = 0.5 × 10^(-scale)  →  '0.5', '0.05', '0.005', …
        $offset = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';

        $result = bccomp($v, '0', $scale + 6) >= 0
            ? bcadd($v, $offset, $scale)
            : bcsub($v, $offset, $scale);

        // Never return "-0.00"
        if (bccomp($result, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }

        return $result;
    }

    /** Same as round() but returns a float — for model columns / JSON output. */
    public static function roundFloat(string|int|float|null $value, int $scale = 2): float
    {
        return (float) self::round($value, $scale);
    }

    /** Convert any numeric to a plain decimal string (no "1.0E-5" notation). */
    private static function toString(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            $s = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
            return $s === '' || $s === '-' ? '0' : $s;
        }

        return (string) $value;
    }
}
