<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * A shipping template (D-1505): a material of the group «Versandverpackungen» with its edges and weights. Chosen in the
 * parcel form, it fills the edges and the weight — its maximum less one gram, so the parcel stays below that limit.
 *
 * @see docs/konzept.md
 */
final class Packaging
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $lengthCm,
        public readonly int $widthCm,
        public readonly int $heightCm,
        public readonly int $emptyGrams,
        public readonly int $maxGrams,
    ) {
    }

    /** The weight the form starts with: the maximum less a gram; without a maximum, the empty weight. */
    public function startWeight(): int
    {
        return $this->maxGrams > 1 ? $this->maxGrams - 1 : $this->emptyGrams;
    }

    /** @return array{weight_g: string, length_cm: string, width_cm: string, height_cm: string} only what the template knows */
    public function formValues(): array
    {
        $text = static fn (int $value): string => $value > 0 ? (string) $value : '';

        return ['weight_g' => $text($this->startWeight()), 'length_cm' => $text($this->lengthCm), 'width_cm' => $text($this->widthCm), 'height_cm' => $text($this->heightCm)];
    }
}
