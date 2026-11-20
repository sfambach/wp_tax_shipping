<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * One offer a carrier made for a parcel: its product, the gross price in cents, whether it is tracked.
 *
 * @see docs/konzept.md
 */
final class Rate
{
    public function __construct(
        public readonly string $carrier,
        public readonly string $product,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly bool $tracked,
        public readonly int $liabilityEuro,
        /** D-1506: bought in the carrier's own shop; this module links there and records the purchase afterwards. */
        public readonly string $shopUrl = '',
    ) {
    }

    /** The answer of a carrier module, or null when the entry is not a usable offer. @param array<string, mixed> $entry */
    public static function fromArray(array $entry): ?self
    {
        $carrier = is_string($entry['carrier'] ?? null) ? trim($entry['carrier']) : '';
        $product = is_scalar($entry['product'] ?? null) ? trim((string) $entry['product']) : '';

        if ($carrier === '' || $product === '' || ! is_numeric($entry['price_cents'] ?? null) || (int) $entry['price_cents'] < 0) {
            return null;
        }

        return new self(
            $carrier,
            $product,
            is_string($entry['name'] ?? null) && trim($entry['name']) !== '' ? trim($entry['name']) : $product,
            (int) $entry['price_cents'],
            (bool) ($entry['tracked'] ?? false),
            is_numeric($entry['liability_eur'] ?? null) ? (int) $entry['liability_eur'] : 0,
            is_string($entry['shop_url'] ?? null) && str_starts_with($entry['shop_url'], 'https://') ? $entry['shop_url'] : '',
        );
    }

    /** @return array{carrier: string, product: string, name: string, price_cents: int, tracked: bool, liability_eur: int} */
    public function toArray(): array
    {
        return ['carrier' => $this->carrier, 'product' => $this->product, 'name' => $this->name, 'price_cents' => $this->priceCents, 'tracked' => $this->tracked, 'liability_eur' => $this->liabilityEuro];
    }

    public function key(): string
    {
        return $this->carrier . ':' . $this->product;
    }
}
