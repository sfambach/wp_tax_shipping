<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * D-1515: where a label came from, read from the shipment's reference and nowhere else — no field of its own. A label from a
 * mail is «mail-<cart>» (D-1511), an uploaded stamp «upload-…», one recorded by hand after buying in the Post shop «shop-…»
 * (before D-1515 uploads were recorded so too); anything else is the carrier's own reference.
 *
 * @see docs/konzept.md
 */
final class LabelOrigin
{
    public const MAIL = 'mail';

    public const UPLOAD = 'upload';

    public const SHOP = 'shop';

    public const CARRIER = 'carrier';

    /** reference prefix → origin */
    public const PREFIXES = [MailLabels::REFERENCE => self::MAIL, 'upload-' => self::UPLOAD, 'shop-' => self::SHOP];

    public static function of(string $reference): string
    {
        foreach (self::PREFIXES as $prefix => $origin) {
            if (str_starts_with($reference, $prefix)) {
                return $origin;
            }
        }

        return self::CARRIER;
    }

    /** The reference a new label of this origin gets; a mail's label is known by its cart. */
    public static function reference(string $origin, string $suffix): string
    {
        return (string) array_search($origin, self::PREFIXES, true) . $suffix;
    }
}
