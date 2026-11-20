<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * Sorts what the carriers answered: usable offers cheapest first (a tracked offer before an untracked one of the same
 * price), the carriers that failed apart with their reason. Nothing is chosen here; the owner clicks.
 *
 * ```mermaid
 * flowchart LR
 *   A["answers of taxmod_shipping_rates"] --> E{"error?"}
 *   E -- yes --> F["failures: carrier + reason"]
 *   E -- no --> O["offers, cheapest first"]
 * ```
 *
 * @see docs/konzept.md
 */
final class RateComparison
{
    /**
     * @param list<Rate> $offers
     * @param array<string, string> $failures carrier → reason
     */
    private function __construct(public readonly array $offers, public readonly array $failures)
    {
    }

    /** @param iterable<mixed> $answers `mixed`: whatever the carrier modules put into the filter */
    public static function of(iterable $answers): self
    {
        $offers = [];
        $failures = [];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            if (is_string($answer['error'] ?? null)) {
                $carrier = is_string($answer['carrier'] ?? null) ? $answer['carrier'] : '?';
                $failures[$carrier] = $answer['error'];

                continue;
            }

            $rate = Rate::fromArray($answer);

            if ($rate !== null) {
                $offers[$rate->key()] = $rate;
            }
        }

        $offers = array_values($offers);
        usort($offers, static fn (Rate $a, Rate $b): int => [$a->priceCents, ! $a->tracked, $a->key()] <=> [$b->priceCents, ! $b->tracked, $b->key()]);

        return new self($offers, $failures);
    }

    public function cheapest(): ?Rate
    {
        return $this->offers[0] ?? null;
    }

    public function find(string $carrier, string $product): ?Rate
    {
        foreach ($this->offers as $offer) {
            if ($offer->carrier === $carrier && $offer->product === $product) {
                return $offer;
            }
        }

        return null;
    }
}
