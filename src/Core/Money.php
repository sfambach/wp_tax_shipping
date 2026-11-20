<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * Cents as the stored decimal the modeller's number formatter shows («5.49»); the locale is the boundary's.
 *
 * @see docs/konzept.md
 */
final class Money
{
    public static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** «3,75», «3.75» or «3» as cents; anything else is no price (D-1507: read from a stamp or typed by the owner). */
    public static function cents(string $text): int
    {
        if (preg_match('/^\s*(\d{1,5})(?:[.,](\d{1,2}))?\s*(?:€|EUR)?\s*$/u', $text, $m) !== 1) {
            throw new ShippingError('Not a price: ' . $text);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }
}
