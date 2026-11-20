<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The materials of «Versandverpackungen» read as templates (D-1505). Edges and weights are unit values (D-1497): an
 * amount with an optional prefix; the field says the unit (centimetre, gram), the prefix scales it.
 *
 * @see docs/konzept.md
 */
final class Packagings
{
    /**
     * @param array<int, array<int, list<string>>> $records material id → relation id → values
     * @param array<int, array<int, list<string>>> $parts   the unit values those records point at
     * @param array<int, string>                   $names   material id → its word
     * @return array<int, Packaging> material id → template, by name
     */
    public static function of(ShipmentRoles $r, array $records, array $parts, array $names): array
    {
        $out = [];

        foreach ($records as $id => $values) {
            $part = static fn (string $role): array => $parts[(int) (($values[$r->optional($role)] ?? [])[0] ?? 0)] ?? [];
            $out[(int) $id] = new Packaging(
                (int) $id,
                $names[(int) $id] ?? ('#' . $id),
                self::scaled($r, $part('packaging_length'), -2),
                self::scaled($r, $part('packaging_width'), -2),
                self::scaled($r, $part('packaging_height'), -2),
                self::scaled($r, $part('packaging_empty_weight'), 0),
                self::scaled($r, $part('packaging_max_weight'), 0),
            );
        }

        uasort($out, static fn (Packaging $a, Packaging $b): int => strnatcasecmp($a->name, $b->name));

        return $out;
    }

    /**
     * An amount brought to the field's own scale, rounded up: «2 dm» in a centimetre field is 20, «1 kg» in a gram field 1000.
     *
     * @param array<int, list<string>> $part
     * @param int $base the power of ten the module counts in: 0 gram, -2 centimetre, -3 millimetre
     */
    public static function scaled(ShipmentRoles $r, array $part, int $base): int
    {
        $amount = (float) str_replace(',', '.', (string) (($part[$r->field('money_amount')] ?? [])[0] ?? '0'));
        $prefix = (int) (($part[$r->field('unit_prefix')] ?? [])[0] ?? 0);
        $exponent = match (true) {
            $prefix === 0                            => 0,
            $prefix === $r->optional('prefix_kilo')  => 3,
            $prefix === $r->optional('prefix_centi') => -2,
            $prefix === $r->optional('prefix_milli') => -3,
            $prefix === $r->optional('prefix_deci')  => -1,
            default                                  => null,
        };

        if ($exponent === null || $amount <= 0) {
            return 0;
        }

        return (int) ceil(round($amount * 10 ** ($exponent - $base), 6));
    }
}
