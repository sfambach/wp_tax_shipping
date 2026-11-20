<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\LetterRates;
use TaxmodShipping\Core\Parcel;

/**
 * The letter price list of the model (D-1506) as offers of `taxmod_shipping_rates`, read in two queries (CD-7). Without
 * the role «letter_product» it answers nothing.
 */
final class LetterPrices
{
    public static function register(): void
    {
        add_filter(Plugin::CARRIERS, static fn (mixed $carriers): array => (is_array($carriers) ? $carriers : [])
            + (ShipmentStore::roles()->optional('letter_product') === 0 ? [] : [LetterRates::CARRIER => __('Deutsche Post (Briefe)', 'taxmod-shipping')]));
        add_filter(Plugin::RATES, static function (mixed $rates, mixed $parcel = []): array {
            $rates = is_array($rates) ? array_values($rates) : [];
            $r = ShipmentStore::roles();
            $node = $r->optional('letter_product');

            if ($node === 0 || ! is_array($parcel)) {
                return $rates;
            }

            try {
                $parcel = new Parcel((int) ($parcel['weight_g'] ?? 0), (int) ($parcel['length_cm'] ?? 0), (int) ($parcel['width_cm'] ?? 0), (int) ($parcel['height_cm'] ?? 0), (string) ($parcel['country'] ?? 'DE'));
            } catch (\TaxmodShipping\Core\ShippingError) {
                return $rates;
            }

            if ($parcel->country !== 'DE') {
                return $rates;
            }

            return [...$rates, ...self::offers($parcel)];
        }, 10, 2);
    }

    /**
     * Every letter of the price list, for the stamp upload's product suggestion (D-1507): the smallest parcel fits them all.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return ShipmentStore::roles()->optional('letter_product') === 0 ? [] : self::offers(new Parcel(1, 1, 1, 1));
    }

    /** @return list<array<string, mixed>> the letters that take the parcel, read in two queries (CD-7) */
    private static function offers(Parcel $parcel): array
    {
        $r = ShipmentStore::roles();
        $door = new ModelDoor();
        $records = $door->records($r->optional('letter_product'));
        $parts = [];
        foreach ($records as $values) {
            foreach (['letter_price', 'letter_max_length', 'letter_max_width', 'letter_max_height', 'letter_max_weight'] as $role) {
                $parts[] = (int) (($values[$r->optional($role)] ?? [])[0] ?? 0);
            }
        }
        $parts = array_values(array_unique(array_filter($parts)));

        return LetterRates::of($r, $records, $parts === [] ? [] : $door->valuesOf($parts), $parcel);
    }
}
