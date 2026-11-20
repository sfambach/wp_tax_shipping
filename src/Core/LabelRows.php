<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * D-1515 (Stefan 20.11. 16:26 «Eigentlich könnten wir ja auch eine Liste draus machen»): the shipments and the labels from mails
 * that no shipment holds yet, as one list, newest first. A mail's label turns into its shipment by the reference «mail-<cart>»,
 * so it is never listed twice.
 *
 * @see docs/konzept.md
 */
final class LabelRows
{
    /**
     * @param array<int|string, Purchase> $purchases
     * @param mixed $mailLabels what `taxmail_shipping_labels` answered; `mixed`: a filter carries anything
     * @return list<array{origin: string, date: string, purchase: ?Purchase, mail: ?array{cart: string, date: string, carrier: string, link: string}}>
     */
    public static function of(array $purchases, mixed $mailLabels): array
    {
        $rows = [];

        foreach ($purchases as $purchase) {
            $rows[] = ['origin' => LabelOrigin::of($purchase->reference), 'date' => $purchase->createdAt, 'purchase' => $purchase, 'mail' => null];
        }

        $references = array_map(static fn (Purchase $purchase): string => $purchase->reference, array_values($purchases));

        foreach (MailLabels::open($mailLabels, $references) as $label) {
            $rows[] = ['origin' => LabelOrigin::MAIL, 'date' => self::sortable($label['date']), 'purchase' => null, 'mail' => $label];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));

        return $rows;
    }

    /** A mail's date (ATOM, in the mail's own zone, which is the site's) in the shipments' form («Y-m-d H:i:s»), so both sort together. */
    private static function sortable(string $date): string
    {
        try {
            return (new \DateTimeImmutable($date))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $date;
        }
    }
}
