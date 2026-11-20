<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodShipping\Core\Address;

/**
 * «Einstellungen › Versand»: sender address, confirmation e-mail, printer queue. Carrier keys stay in each carrier's
 * own tab (DHL …), never here.
 *
 * @see docs/konzept.md
 */
final class SettingsPage
{
    public const SAVE = 'taxship_settings';

    public static function url(): string
    {
        return Plugin::moduleUrl(Plugin::SETTINGS);
    }

    public function render(): string
    {
        if (! current_user_can(\Taxmod\WordPress\Plugin::CAPABILITY)) {
            return '';
        }

        $settings = new Settings();
        $sender = $settings->sender();
        $input = static fn (string $name, string $value, int $max, string $type = 'text'): string => '<input type="' . esc_attr($type) . '" id="taxship-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" maxlength="' . $max . '" style="width:20em">';
        $row = static fn (string $label, string $name, string $value, int $max, string $hint = '', string $type = 'text'): array => ['label' => $label, 'control' => $input($name, $value, $max, $type), 'for' => 'taxship-' . $name, 'hint' => $hint];

        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::SAVE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">'
            . ListPage::form([
                $row(__('Absender', 'taxmod-shipping'), 'sender_name', $sender->name, 35),
                $row(__('Zusatz', 'taxmod-shipping'), 'sender_addition', $sender->addition, 35),
                $row(__('Straße', 'taxmod-shipping'), 'sender_street', $sender->street, 35),
                $row(__('Hausnummer', 'taxmod-shipping'), 'sender_number', $sender->number, 10),
                $row(__('PLZ', 'taxmod-shipping'), 'sender_postal_code', $sender->postalCode, 17),
                $row(__('Ort', 'taxmod-shipping'), 'sender_city', $sender->city, 35),
                $row(__('Land', 'taxmod-shipping'), 'sender_country', $sender->country, 3, __('ISO-Kürzel, z. B. DE.', 'taxmod-shipping')),
                $row(__('Bestätigung an', 'taxmod-shipping'), 'email', $settings->email(), 70, __('E-Mail für die Kaufbestätigung des Dienstes.', 'taxmod-shipping'), 'email'),
                $row(__('Drucker', 'taxmod-shipping'), 'queue', $settings->queue(), 64, __('Druckerwarteschlange der Druck-App für Versandetiketten, z. B. QL-810W_take. Gedruckt wird nur auf Knopfdruck.', 'taxmod-shipping')),
            ], '<button type="submit" class="button button-primary">' . ListPage::icon('saved', __('Speichern', 'taxmod-shipping')) . ' ' . esc_html__('Speichern', 'taxmod-shipping') . '</button>')
            . '</form>';

        return ListPage::page(__('Versand', 'taxmod-shipping'), [], [['html' => $form]], Plugin::VERSION, 'Taxmod Versand');
    }

    /** admin-post: capability → nonce → sanitize → save. */
    public function save(): string
    {
        if (! current_user_can(\Taxmod\WordPress\Plugin::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-shipping'), self::url());
        }

        ListPage::nonce(self::SAVE);
        $text = static fn (string $name): string => sanitize_text_field(wp_unslash((string) ($_POST[$name] ?? '')));
        $sender = new Address($text('sender_name'), $text('sender_street'), $text('sender_number'), $text('sender_postal_code'), $text('sender_city'), strtoupper($text('sender_country')) ?: 'DE', '', $text('sender_addition'));
        (new Settings())->save($sender, sanitize_email($text('email')), (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $text('queue')));

        return self::url();
    }
}
