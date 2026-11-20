<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\RecordPick;
use TaxmodShipping\Core\ShippingError;

/**
 * «Einstellungen › Versand»: sender address, confirmation e-mail, carrier firms. The printer is the workplace's (D-1502). Carrier keys stay in each carrier's
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
        // D-1488: the modeller's chooser with search and tree, one read of the contacts for every chooser on the page.
        $tree = (new Contacts())->pickTree();
        $choice = static fn (string $name, int $selected): string => RecordPick::field($name, $tree, $selected > 0 ? $selected : null);
        $firms = $settings->carrierFirms();
        $carrierRows = [];
        foreach (Plugin::carriers() as $code => $label) {
            $carrierRows[] = ['label' => sprintf(/* translators: %s: carrier */ __('Firma für %s', 'taxmod-shipping'), $label),
                'control' => $choice('carrier_firms[' . $code . ']', $firms[$code] ?? 0),
                'hint' => __('Der Kontakt, der in «Sendungen» als Dienst steht.', 'taxmod-shipping')];
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
                ['label' => __('Absender', 'taxmod-shipping'), 'control' => $choice('sender_contact', $chosen) . $shown, 'hint' => __('Ein Kontakt aus den Stammdaten; seine Adresse steht dort.', 'taxmod-shipping')],
                ['label' => __('Bestätigung an', 'taxmod-shipping'), 'control' => $input('email', $settings->email(), InputKind::Email), 'for' => 'taxship-email', 'hint' => __('E-Mail für die Kaufbestätigung des Dienstes.', 'taxmod-shipping')],
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

        // A purchase finds its carrier through its firm; one firm for two carriers would mix them up.
        $taken = array_filter($firms);
        if (count($taken) !== count(array_unique($taken))) {
            throw new ShippingError(__('Jeder Dienst braucht eine eigene Firma, z. B. «DHL» und «Deutsche Post».', 'taxmod-shipping'));
        }

        (new Settings())->save($contact, sanitize_email($text('email')), array_filter($firms));

        return self::url();
    }
}
