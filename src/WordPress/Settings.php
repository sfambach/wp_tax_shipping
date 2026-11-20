<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use TaxmodShipping\Core\Address;

/**
 * The sender address, the confirmation e-mail and the printer queue for labels, kept in one option.
 * ⚠️ *INFERRED: until the model has «Sendungen» (D-1497) the sender is not a contact record; the address lives here.*
 */
final class Settings
{
    public const OPTION = 'taxmod_shipping_settings';

    public function sender(): Address
    {
        return Address::fromArray(is_array($this->all()['sender'] ?? null) ? $this->all()['sender'] : []);
    }

    public function email(): string
    {
        return (string) ($this->all()['email'] ?? '');
    }

    public function queue(): string
    {
        return (string) ($this->all()['queue'] ?? '');
    }

    public function save(Address $sender, string $email, string $queue): void
    {
        update_option(self::OPTION, ['sender' => $sender->toArray(), 'email' => $email, 'queue' => $queue], false);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
