<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * D-1538: the parcel as the modeller's own controls typed it — weight a unit value, the edges unit values inside one
 * «Abmessung» — brought to grams and centimetres for the carriers with the reading the templates already use
 * ({@see Packagings::scaled()}); no second conversion. A unit other than the field's (a length in gram) is refused, never
 * guessed.
 *
 * @see docs/konzept.md
 */
final class ParcelInput
{
    /**
     * @param array<int, mixed> $typed relation id → part key → inner relation → characters, as ModelDoor::typed answers
     * @return Parcel|null null when nothing was typed
     * @throws ShippingError
     */
    public static function of(ShipmentRoles $r, array $typed, string $country): ?Parcel
    {
        $weight = self::part($typed, $r->field('shipment_weight'));
        $dimension = self::part($typed, $r->field('shipment_dimension'));

        if ($weight === [] && $dimension === []) {
            return null;
        }

        $edge = static fn (string $role): array => self::part($dimension, $r->field($role));
        $grams = self::amount($r, $weight, 'unit_gram', 0);
        $edges = array_map(static fn (string $role): int => self::amount($r, $edge($role), 'unit_metre', -2), ['dimension_length', 'dimension_width', 'dimension_height']);

        return new Parcel($grams, $edges[0], $edges[1], $edges[2], $country);
    }

    /**
     * The other way: grams and centimetres (a template's) in the part form the controls take, so a template fills them.
     *
     * @return array<int, array<string, array<int, mixed>>> relation id → part key → inner relation → characters or parts
     */
    public static function parts(ShipmentRoles $r, int $grams, int $lengthCm, int $widthCm, int $heightCm): array
    {
        // The prefix is always named, empty for none: a template chosen after «kg» was picked must not stay in kilograms.
        $amount = static fn (int $value, string $unit, string $prefix = ''): array => [
            $r->field('money_amount') => (string) $value,
            $r->field('money_unit')   => (string) $r->value($unit),
            $r->field('unit_prefix')  => $prefix === '' ? '' : (string) $r->value($prefix),
        ];
        $edge = static fn (int $cm): array => $cm > 0 ? ['0' => $amount($cm, 'unit_metre', 'prefix_centi')] : [];

        return array_filter([
            $r->field('shipment_weight')    => $grams > 0 ? ['0' => $amount($grams, 'unit_gram')] : [],
            $r->field('shipment_dimension') => ($edges = array_filter([
                $r->field('dimension_length') => $edge($lengthCm),
                $r->field('dimension_width')  => $edge($widthCm),
                $r->field('dimension_height') => $edge($heightCm),
            ])) === [] ? [] : ['0' => $edges],
        ]);
    }

    /**
     * The same parts as the names a form posts them under, for the template fill in the browser.
     *
     * @param array<int|string, mixed> $parts
     * @return array<string, string> «prefix[1][0][4234]» → characters
     */
    public static function names(string $prefix, array $parts): array
    {
        $out = [];
        foreach ($parts as $key => $inner) {
            $name = $prefix . '[' . $key . ']';
            $out += is_array($inner) ? self::names($name, $inner) : [$name => (string) $inner];
        }

        return $out;
    }

    /**
     * The one part a field holds, whatever its key («0» for the held one, a word for a new one).
     *
     * @param array<int|string, mixed> $values
     * @return array<int|string, mixed>
     */
    private static function part(array $values, int $relation): array
    {
        $parts = $values[$relation] ?? [];
        $first = is_array($parts) ? reset($parts) : false;

        return is_array($first) ? $first : [];
    }

    /** @param array<int|string, mixed> $part inner relation → characters */
    private static function amount(ShipmentRoles $r, array $part, string $unit, int $base): int
    {
        $chosen = (int) ($part[$r->field('money_unit')] ?? 0);
        if ($chosen !== 0 && $chosen !== $r->value($unit)) {
            throw new ShippingError('The unit does not fit the field.');
        }

        return Packagings::scaled($r, array_map(static fn (mixed $one): array => [(string) $one], array_filter($part, 'is_scalar')), $base);
    }
}
