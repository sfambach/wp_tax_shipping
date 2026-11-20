<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * One bought label: what was chosen at which price, the carrier's reference, where it is paid, and — once paid — the
 * shipment numbers. Stages: awaiting_payment → paid (label available).
 *
 * @see docs/konzept.md
 */
final class Purchase
{
    public const AWAITING = 'awaiting_payment';
    public const PAID     = 'paid';

    /** @param list<string> $numbers */
    public function __construct(
        public readonly string $id,
        public readonly Rate $rate,
        public readonly string $receiver,
        public readonly string $reference,
        public readonly string $state,
        public readonly string $payUrl,
        public readonly array $numbers,
        public readonly string $createdAt,
    ) {
    }

    /**
     * The carrier's answer to `taxmod_shipping_buy`.
     *
     * @param array<string, mixed>|null $answer
     * @throws ShippingError when no carrier module answered or the carrier refused
     */
    public static function bought(string $id, Rate $rate, string $receiver, ?array $answer, string $now): self
    {
        if ($answer === null) {
            throw new ShippingError(sprintf('No module sells «%s» of %s.', $rate->name, $rate->carrier));
        }

        if (is_string($answer['error'] ?? null)) {
            throw new ShippingError($answer['error']);
        }

        $reference = is_scalar($answer['reference'] ?? null) ? (string) $answer['reference'] : '';

        if ($reference === '') {
            throw new ShippingError('The carrier answered without a reference.');
        }

        return new self($id, $rate, $receiver, $reference, self::state($answer['state'] ?? null), self::url($answer['pay_url'] ?? null), self::numbers($answer['numbers'] ?? null), $now);
    }

    /** @param array<string, mixed>|null $answer the answer to `taxmod_shipping_status` */
    public function withStatus(?array $answer): self
    {
        if ($answer === null) {
            return $this;
        }

        if (is_string($answer['error'] ?? null)) {
            throw new ShippingError($answer['error']);
        }

        $numbers = self::numbers($answer['numbers'] ?? null);

        return new self($this->id, $this->rate, $this->receiver, $this->reference, self::state($answer['state'] ?? $this->state), self::url($answer['pay_url'] ?? null) ?: $this->payUrl, $numbers === [] ? $this->numbers : $numbers, $this->createdAt);
    }

    public function isPaid(): bool
    {
        return $this->state === self::PAID;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'rate' => $this->rate->toArray(), 'receiver' => $this->receiver, 'reference' => $this->reference, 'state' => $this->state,
            'pay_url' => $this->payUrl, 'numbers' => $this->numbers, 'created_at' => $this->createdAt];
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored): ?self
    {
        $rate = is_array($stored['rate'] ?? null) ? Rate::fromArray($stored['rate']) : null;

        if ($rate === null || ! is_string($stored['id'] ?? null) || ! is_string($stored['reference'] ?? null)) {
            return null;
        }

        return new self($stored['id'], $rate, is_string($stored['receiver'] ?? null) ? $stored['receiver'] : '', $stored['reference'], self::state($stored['state'] ?? null),
            self::url($stored['pay_url'] ?? null), self::numbers($stored['numbers'] ?? null), is_string($stored['created_at'] ?? null) ? $stored['created_at'] : '');
    }

    private static function state(mixed $state): string
    {
        return $state === self::PAID ? self::PAID : self::AWAITING;
    }

    private static function url(mixed $url): string
    {
        return is_string($url) && str_starts_with($url, 'https://') ? $url : '';
    }

    /** @return list<string> */
    private static function numbers(mixed $numbers): array
    {
        return is_array($numbers) ? array_values(array_filter(array_map(static fn (mixed $n): string => is_scalar($n) ? preg_replace('/[^A-Za-z0-9]/', '', (string) $n) ?? '' : '', $numbers), static fn (string $n): bool => $n !== '')) : [];
    }
}
