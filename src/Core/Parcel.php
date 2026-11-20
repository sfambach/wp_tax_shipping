<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * What every carrier is asked about: weight in grams, the three edges in centimetres, the destination country (ISO
 * alpha-2 as contacts hold it). Its array is the contract of the filter `taxmod_shipping_rates` (D-1496).
 *
 * @see docs/konzept.md
 */
final class Parcel
{
    public function __construct(
        public readonly int $weightGrams,
        public readonly int $lengthCm,
        public readonly int $widthCm,
        public readonly int $heightCm,
        public readonly string $country = 'DE',
    ) {
        if ($weightGrams <= 0 || $lengthCm <= 0 || $widthCm <= 0 || $heightCm <= 0) {
            throw new ShippingError('Weight and all three edges must be above 0.');
        }

        if (preg_match('/^[A-Z]{2,3}$/', $country) !== 1) {
            throw new ShippingError('The country is an ISO code such as DE.');
        }
    }

    /** @return array{weight_g: int, length_cm: int, width_cm: int, height_cm: int, country: string} */
    public function toArray(): array
    {
        return ['weight_g' => $this->weightGrams, 'length_cm' => $this->lengthCm, 'width_cm' => $this->widthCm, 'height_cm' => $this->heightCm, 'country' => $this->country];
    }
}
