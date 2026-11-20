<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * Letter postage from the price list in the model (D-1506): every product whose limits the parcel keeps becomes an offer
 * of the carrier `post`. It is bought in the Post's own shop — this module only links there and records the purchase
 * afterwards, no money passes through it.
 *
 * @see docs/konzept.md
 */
final class LetterRates
{
    public const CARRIER = 'post';

    /**
     * @param array<int, array<int, list<string>>> $records product id → relation id → values
     * @param array<int, array<int, list<string>>> $parts   the unit values those records point at
     * @return list<array<string, mixed>> offers in the shape of `taxmod_shipping_rates`
     */
    public static function of(ShipmentRoles $r, array $records, array $parts, Parcel $parcel): array
    {
        $offers = [];

        foreach ($records as $id => $values) {
            $text = static fn (string $role): string => trim((string) (($values[$r->optional($role)] ?? [])[0] ?? ''));
            $part = static fn (string $role): array => $parts[(int) (($values[$r->optional($role)] ?? [])[0] ?? 0)] ?? [];
            $price = $part('letter_price');
            $cents = (int) round(((float) str_replace(',', '.', (string) (($price[$r->field('money_amount')] ?? [])[0] ?? '0'))) * 100);
            $limits = [Packagings::scaled($r, $part('letter_max_length'), -3), Packagings::scaled($r, $part('letter_max_width'), -3), Packagings::scaled($r, $part('letter_max_height'), -3)];
            $shop = $text('letter_shop_url');

            if ($cents <= 0 || $text('letter_name') === '' || ! str_starts_with($shop, 'https://') || ! self::fits($parcel, $limits, Packagings::scaled($r, $part('letter_max_weight'), 0))) {
                continue;
            }

            $offers[] = ['carrier' => self::CARRIER, 'product' => $text('letter_code') !== '' ? $text('letter_code') : 'letter-' . $id, 'name' => $text('letter_name'),
                'price_cents' => $cents, 'tracked' => false, 'liability_eur' => 0, 'shop_url' => $shop];
        }

        return $offers;
    }

    /**
     * The edges compared longest with longest; a limit of 0 is no limit.
     *
     * @param array{0: int, 1: int, 2: int} $limitsMm
     */
    public static function fits(Parcel $parcel, array $limitsMm, int $maxGrams): bool
    {
        $edges = [$parcel->lengthCm * 10, $parcel->widthCm * 10, $parcel->heightCm * 10];
        rsort($edges);
        rsort($limitsMm);

        foreach ($edges as $i => $edge) {
            if ($limitsMm[$i] > 0 && $edge > $limitsMm[$i]) {
                return false;
            }
        }

        return $maxGrams === 0 || $parcel->weightGrams <= $maxGrams;
    }
}
