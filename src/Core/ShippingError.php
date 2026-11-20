<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * A failure inside the shipping core; the boundary turns it into a message with a way back (D-1254).
 *
 * @see docs/konzept.md
 */
final class ShippingError extends \RuntimeException
{
}
