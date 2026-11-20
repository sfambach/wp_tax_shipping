<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodShipping\Core\ShippingError;

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
        $chosen = $settings->senderContact();
        $options = '<option value="0">' . esc_html__('— wählen —', 'taxmod-shipping') . '</option>';
        foreach ((new Contacts())->names() as $id => $name) {
            $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $chosen, false) . '>' . esc_html($name) . '</option>';
        }
        $shown = '';
        if ($chosen > 0) {
            try {
                $address = $settings->sender();
                $shown = '<p class="description">' . esc_html($address->street . ' ' . $address->number . ', ' . $address->postalCode . ' ' . $address->city . ', ' . $address->country) . '</p>';
            } catch (ShippingError $failure) {
                $shown = '<p class="description" style="color:#b32d2e">' . esc_html($failure->getMessage()) . '</p>';
            }
        }
        $input = static fn (string $name, string $value, int $max, string $type = 'text'): string => '<input type="' . esc_attr($type) . '" id="taxship-' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" maxlength="' . $max . '" style="width:20em">';

        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::SAVE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">'
            . ListPage::form([
                ['label' => __('Absender', 'taxmod-shipping'), 'control' => '<select id="taxship-sender_contact" name="sender_contact" style="width:20em">' . $options . '</select>' . $shown, 'for' => 'taxship-sender_contact', 'hint' => __('Ein Kontakt aus den Stammdaten; seine Adresse steht dort.', 'taxmod-shipping')],
                ['label' => __('Bestätigung an', 'taxmod-shipping'), 'control' => $input('email', $settings->email(), 70, 'email'), 'for' => 'taxship-email', 'hint' => __('E-Mail für die Kaufbestätigung des Dienstes.', 'taxmod-shipping')],
                ['label' => __('Drucker', 'taxmod-shipping'), 'control' => $input('queue', $settings->queue(), 64), 'for' => 'taxship-queue', 'hint' => __('Druckerwarteschlange der Druck-App für Versandetiketten, z. B. QL-810W_take. Gedruckt wird nur auf Knopfdruck.', 'taxmod-shipping')],
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
        $contact = absint($_POST['sender_contact'] ?? 0);

        if ($contact > 0 && ! isset((new Contacts())->names()[$contact])) {
            throw new ShippingError(__('Diesen Kontakt gibt es nicht.', 'taxmod-shipping'));
        }

        (new Settings())->save($contact, sanitize_email($text('email')), (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $text('queue')));

        return self::url();
    }
}
