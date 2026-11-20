<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\Core\Description\InputKind;
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
        $names = (new Contacts())->names();
        $choice = static function (int $selected) use ($names): string {
            $html = '<option value="0">' . esc_html__('— wählen —', 'taxmod-shipping') . '</option>';
            foreach ($names as $id => $name) {
                $html .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $selected, false) . '>' . esc_html($name) . '</option>';
            }

            return $html;
        };
        $options = $choice($chosen);
        $firms = $settings->carrierFirms();
        $carrierRows = [];
        foreach (Plugin::carriers() as $code => $label) {
            $carrierRows[] = ['label' => sprintf(/* translators: %s: carrier */ __('Firma für %s', 'taxmod-shipping'), $label),
                'control' => '<select id="taxship-carrier-' . esc_attr($code) . '" name="carrier_firms[' . esc_attr($code) . ']" style="width:20em">' . $choice($firms[$code] ?? 0) . '</select>',
                'for' => 'taxship-carrier-' . $code, 'hint' => __('Der Kontakt, der in «Sendungen» als Dienst steht.', 'taxmod-shipping')];
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
        $input = static fn (string $name, string $value, InputKind $kind = InputKind::Line): string => ListPage::field($kind, $name, $value, ['id' => 'taxship-' . $name, 'size' => '30']);

        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::SAVE, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">'
            . ListPage::form([
                ['label' => __('Absender', 'taxmod-shipping'), 'control' => '<select id="taxship-sender_contact" name="sender_contact" style="width:20em">' . $options . '</select>' . $shown, 'for' => 'taxship-sender_contact', 'hint' => __('Ein Kontakt aus den Stammdaten; seine Adresse steht dort.', 'taxmod-shipping')],
                ['label' => __('Bestätigung an', 'taxmod-shipping'), 'control' => $input('email', $settings->email(), InputKind::Email), 'for' => 'taxship-email', 'hint' => __('E-Mail für die Kaufbestätigung des Dienstes.', 'taxmod-shipping')],
                ['label' => __('Drucker', 'taxmod-shipping'), 'control' => $input('queue', $settings->queue()), 'for' => 'taxship-queue', 'hint' => __('Druckerwarteschlange der Druck-App für Versandetiketten, z. B. QL-810W_take. Gedruckt wird nur auf Knopfdruck.', 'taxmod-shipping')],
                ...$carrierRows,
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
        $names = (new Contacts())->names();
        $contact = absint($_POST['sender_contact'] ?? 0);
        $firms = [];
        $posted = is_array($_POST['carrier_firms'] ?? null) ? $_POST['carrier_firms'] : [];
        foreach (array_keys(Plugin::carriers()) as $code) {
            $firms[$code] = absint($posted[$code] ?? 0);
        }

        foreach ([$contact, ...array_values($firms)] as $chosen) {
            if ($chosen > 0 && ! isset($names[$chosen])) {
                throw new ShippingError(__('Diesen Kontakt gibt es nicht.', 'taxmod-shipping'));
            }
        }

        (new Settings())->save($contact, sanitize_email($text('email')), (string) preg_replace('/[^A-Za-z0-9_.\-]/', '', $text('queue')), array_filter($firms));

        return self::url();
    }
}
