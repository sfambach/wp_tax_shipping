<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * D-1511: the postage labels the mail module read from mails, as far as no shipment holds them yet. A shipment made from one carries
 * the reference «mail-<cart>», so a label is listed until it is recorded. Only carriers this module records are listed.
 *
 * @see docs/konzept.md
 */
final class MailLabels
{
    public const REFERENCE = 'mail-';

    public const CARRIERS = ['dhl', LetterRates::CARRIER];

    /**
     * @param mixed $labels what the filter `taxmail_shipping_labels` answered; `mixed`: a filter carries anything
     * @param list<string> $references the references of the recorded shipments
     * @return list<array{cart: string, date: string, carrier: string, link: string}> one row per PDF link, newest first as given
     */
    public static function open(mixed $labels, array $references): array
    {
        $rows = [];

        foreach (is_array($labels) ? $labels : [] as $label) {
            $cart = is_array($label) && is_scalar($label['cart'] ?? null) ? (string) $label['cart'] : '';
            $carrier = is_array($label) && is_string($label['carrier'] ?? null) ? $label['carrier'] : 'dhl';

            if ($cart === '' || ! in_array($carrier, self::CARRIERS, true) || in_array(self::REFERENCE . $cart, $references, true)) {
                continue;
            }

            foreach (array_values(array_filter((array) ($label['links'] ?? []), 'is_string')) as $link) {
                $rows[] = ['cart' => $cart, 'date' => is_scalar($label['date'] ?? null) ? (string) $label['date'] : '', 'carrier' => $carrier, 'link' => $link];
            }
        }

        return $rows;
    }

    /** The cart a reference was made from, or '' when the shipment did not come from a mail. */
    public static function cartOf(string $reference): string
    {
        return str_starts_with($reference, self::REFERENCE) ? substr($reference, strlen(self::REFERENCE)) : '';
    }
}
