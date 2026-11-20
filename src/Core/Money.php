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
}
