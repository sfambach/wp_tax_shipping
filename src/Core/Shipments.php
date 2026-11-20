<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The records of «Sendungen» read back as purchases, newest first. The carrier code comes from the firm the record
 * names, through the owner's mapping carrier → firm; a record whose firm has no carrier is shown but cannot be asked.
 *
 * @see docs/konzept.md
 */
final class Shipments
{
    /**
     * @param array<int, array<int, list<string>>> $records record id → relation id → values (the node «Sendungen»)
     * @param array<int, array<int, list<string>>> $parts   the price parts those records point at
     * @param array<int, string> $receivers contact id → name
     * @param array<int, string> $carriers firm id → carrier code
     * @return array<int, Purchase> record id → purchase, newest first
     */
    public static function of(ShipmentRoles $r, array $records, array $parts, array $receivers, array $carriers): array
    {
        $first = static fn (array $values, string $role): string => (string) (($values[$r->field($role)] ?? [])[0] ?? '');
        $out = [];

        foreach ($records as $id => $values) {
            $price = $parts[(int) $first($values, 'shipment_price')] ?? [];
            $cents = (int) round(((float) (($price[$r->field('money_amount')] ?? [])[0] ?? 0)) * 100);
            $carrier = $carriers[(int) $first($values, 'shipment_carrier')] ?? '';
            $product = $first($values, 'shipment_product');
            $answer = ['reference' => $first($values, 'shipment_reference'), 'pay_url' => $first($values, 'shipment_pay_url'),
                'numbers' => $values[$r->field('shipment_number')] ?? [],
                'state' => $first($values, 'shipment_state') === (string) $r->value('shipment_awaiting_payment') ? Purchase::AWAITING : Purchase::PAID];

            if ($answer['reference'] === '') {
                continue;
            }

            $out[(int) $id] = Purchase::bought((string) $id, new Rate($carrier === '' ? '?' : $carrier, $product === '' ? '?' : $product, $product, $cents, true, 0),
                $receivers[(int) $first($values, 'shipment_receiver')] ?? '', $answer, $first($values, 'shipment_bought_at'));
        }

        uasort($out, static fn (Purchase $a, Purchase $b): int => [$b->createdAt, (int) $b->id] <=> [$a->createdAt, (int) $a->id]);

        return $out;
    }
}
