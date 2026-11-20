<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use TaxmodShipping\Core\Purchase;

/**
 * The labels bought here, newest first, the last 200.
 * ⚠️ *INFERRED: a bridge until the model has the node «Sendungen» (D-1497, Stefan's card). Then each purchase becomes a
 * record there and this option is read once into it and deleted — it never holds what the model holds.*
 */
final class PurchaseStore
{
    public const OPTION = 'taxmod_shipping_purchases';
    private const KEEP = 200;

    /** @return list<Purchase> */
    public function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_values(array_filter(array_map(static fn (mixed $one): ?Purchase => is_array($one) ? Purchase::fromArray($one) : null, is_array($stored) ? $stored : [])));
    }

    public function find(string $id): ?Purchase
    {
        foreach ($this->all() as $purchase) {
            if ($purchase->id === $id) {
                return $purchase;
            }
        }

        return null;
    }

    public function put(Purchase $purchase): void
    {
        $others = array_filter($this->all(), static fn (Purchase $one): bool => $one->id !== $purchase->id);
        $this->write([$purchase, ...$others]);
    }

    public function remove(string $id): void
    {
        $this->write(array_filter($this->all(), static fn (Purchase $one): bool => $one->id !== $id));
    }

    /** @param iterable<Purchase> $purchases */
    private function write(iterable $purchases): void
    {
        $rows = [];
        foreach ($purchases as $purchase) {
            $rows[] = $purchase->toArray();
        }

        update_option(self::OPTION, array_slice($rows, 0, self::KEEP), false);
    }
}
