<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\ShippingError;

/**
 * The sender — a contact record (D-1497), its address read from the model — the confirmation e-mail and the printer
 * queue for labels.
 */
final class Settings
{
    public const OPTION = 'taxmod_shipping_settings';

    public function senderContact(): int
    {
        return (int) ($this->all()['sender_contact'] ?? 0);
    }

    /** @throws ShippingError when no contact is chosen or its address is incomplete */
    public function sender(): Address
    {
        if ($this->senderContact() === 0) {
            throw new ShippingError(__('Absender fehlt: unter Einstellungen › Versand einen Kontakt wählen.', 'taxmod-shipping'));
        }

        return (new Contacts())->address($this->senderContact());
    }

    public function email(): string
    {
        return (string) ($this->all()['email'] ?? '');
    }

    public function queue(): string
    {
        return (string) ($this->all()['queue'] ?? '');
    }

    public function save(int $senderContact, string $email, string $queue): void
    {
        update_option(self::OPTION, ['sender_contact' => $senderContact, 'email' => $email, 'queue' => $queue], false);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
