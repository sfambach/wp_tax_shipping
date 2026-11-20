<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The change lists for the modeller's `write-data` (D-1497): a bought label becomes one record of «Sendungen» — with a
 * new contact for a typed receiver in the same act, so one undo takes both back (D-1410) — and a status answer sets its
 * state and numbers.
 *
 * ```mermaid
 * flowchart LR
 *   B["Kaufen"] --> C{"receiver a contact?"}
 *   C -- no --> N["create_record contact + address parts"]
 *   C -- yes --> S
 *   N --> S["create_record Sendung: receiver, sender, carrier, product, price, sizes, state, reference, pay page, bought at"]
 * ```
 *
 * @see docs/konzept.md
 */
final class ShipmentChanges
{
    public function __construct(private readonly ShipmentRoles $roles)
    {
    }

    /**
     * @param int|Address $receiver a contact record, or an address typed in the dialog (becomes a contact)
     * @return list<array<string, mixed>>
     */
    public function bought(Purchase $purchase, ?Parcel $parcel, int|Address $receiver, int $sender, int $carrierFirm, int $sale = 0): array
    {
        $r = $this->roles;
        $changes = [];

        if ($receiver instanceof Address) {
            $changes[] = ['op' => 'create_record', 'ref' => 'receiver', 'node' => (string) $r->node('contact_person')];
            $changes[] = ['op' => 'set_values', 'record' => '@receiver', 'values' => $this->contactValues($receiver)];
            $receiverValue = '@receiver';
        } else {
            $receiverValue = (string) $receiver;
        }

        $values = array_filter([
            (string) $r->field('shipment_receiver')  => $receiverValue,
            (string) $r->field('shipment_sender')    => (string) $sender,
            (string) $r->field('shipment_carrier')   => $carrierFirm > 0 ? (string) $carrierFirm : '',
            (string) $r->field('shipment_product')   => $purchase->rate->name,
            (string) $r->field('shipment_price')     => ['neu' => $this->amount(Money::decimal($purchase->rate->priceCents), 'currency_default')],
            // A stamp bought in the Post shop comes without a measured parcel (D-1507): its sizes stay empty, never guessed.
            (string) $r->field('shipment_weight')    => $parcel === null ? '' : ['neu' => $this->amount((string) $parcel->weightGrams, 'unit_gram')],
            (string) $r->field('shipment_length')    => $parcel === null ? '' : ['neu' => $this->amount((string) $parcel->lengthCm, 'unit_metre', 'prefix_centi')],
            (string) $r->field('shipment_width')     => $parcel === null ? '' : ['neu' => $this->amount((string) $parcel->widthCm, 'unit_metre', 'prefix_centi')],
            (string) $r->field('shipment_height')    => $parcel === null ? '' : ['neu' => $this->amount((string) $parcel->heightCm, 'unit_metre', 'prefix_centi')],
            (string) $r->field('shipment_state')     => (string) $r->value($purchase->isPaid() ? 'shipment_paid' : 'shipment_awaiting_payment'),
            (string) $r->field('shipment_number')    => self::numbers($purchase),
            (string) $r->field('shipment_reference') => $purchase->reference,
            (string) $r->field('shipment_pay_url')   => $purchase->payUrl,
            (string) $r->field('shipment_bought_at') => $purchase->createdAt,
            (string) $r->field('shipment_sale')      => $sale > 0 ? (string) $sale : '',
        ], static fn (mixed $value): bool => $value !== '' && $value !== []);

        $changes[] = ['op' => 'create_record', 'ref' => 'shipment', 'node' => (string) $r->node('shipment')];
        $changes[] = ['op' => 'set_values', 'record' => '@shipment', 'values' => $values];

        return $changes;
    }

    /** @return list<array<string, mixed>> */
    public function status(int $shipment, Purchase $purchase): array
    {
        $r = $this->roles;

        return [['op' => 'set_values', 'record' => (string) $shipment, 'values' => array_filter([
            (string) $r->field('shipment_state')  => (string) $r->value($purchase->isPaid() ? 'shipment_paid' : 'shipment_awaiting_payment'),
            (string) $r->field('shipment_number') => self::numbers($purchase),
        ], static fn (mixed $value): bool => $value !== [])]];
    }

    /**
     * A field taking several values is written as {values: [...]}; a bare list would be dropped by the modeller.
     *
     * @return array{values: list<string>}|array{}
     */
    private static function numbers(Purchase $purchase): array
    {
        return $purchase->numbers === [] ? [] : ['values' => $purchase->numbers];
    }

    /** @return array<string, mixed> */
    private function contactValues(Address $address): array
    {
        $r = $this->roles;

        return array_filter([
            (string) $r->field('firm_name')       => $address->name,
            (string) $r->field('contact_email')   => $address->email,
            (string) $r->field('contact_address') => ['neu' => [
                (string) $r->field('address_street')   => ['neu' => [(string) $r->field('street_name') => $address->street, (string) $r->field('house_number') => $address->number]],
                (string) $r->field('address_zip_city') => ['neu' => [(string) $r->field('postal_code') => $address->postalCode, (string) $r->field('city_name') => $address->city]],
                (string) $r->field('address_country')  => $address->country,
            ]],
        ], static fn (mixed $value): bool => $value !== '');
    }

    /** @return array<string, string> a unit value part: amount, its unit and, for «cm», the prefix */
    private function amount(string $amount, string $unit, string $prefix = ''): array
    {
        $part = [(string) $this->roles->field('money_amount') => $amount, (string) $this->roles->field('money_unit') => (string) $this->roles->value($unit)];

        if ($prefix !== '') {
            $part[(string) $this->roles->field('unit_prefix')] = (string) $this->roles->value($prefix);
        }

        return $part;
    }
}
