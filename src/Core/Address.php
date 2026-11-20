<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * A postal address as the carrier modules take it (filter `taxmod_shipping_buy`). The carrier checks its own lengths.
 *
 * @see docs/konzept.md
 */
final class Address
{
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $number,
        public readonly string $postalCode,
        public readonly string $city,
        public readonly string $country = 'DE',
        public readonly string $email = '',
        public readonly string $addition = '',
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $text = static fn (string $key): string => is_scalar($values[$key] ?? null) ? trim((string) $values[$key]) : '';

        return new self($text('name'), $text('street'), $text('number'), $text('postal_code'), $text('city'), strtoupper($text('country')) ?: 'DE', $text('email'), $text('addition'));
    }

    public function isComplete(): bool
    {
        return $this->name !== '' && $this->street !== '' && $this->postalCode !== '' && $this->city !== '' && $this->country !== '';
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'street' => $this->street, 'number' => $this->number, 'postal_code' => $this->postalCode, 'city' => $this->city, 'country' => $this->country, 'email' => $this->email, 'addition' => $this->addition];
    }
}
