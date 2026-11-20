<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\Money;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\Purchase;
use TaxmodShipping\Core\Rate;
use TaxmodShipping\Core\RateComparison;
use TaxmodShipping\Core\ShippingError;

/**
 * «Warenausgang › Versand»: the parcel, the offers of every carrier cheapest first, and the labels bought. Money moves
 * only on «Kaufen» with the price shown, a label prints only on «Drucken» (D-1496).
 *
 * @see docs/konzept.md
 */
final class ShippingPage
{
    public const BUY      = 'taxship_buy';
    public const STATUS   = 'taxship_status';
    public const PRINT    = 'taxship_print';
    public const DOWNLOAD = 'taxship_download';
    public const REMOVE   = 'taxship_remove';

    /** admin-post action → method */
    public const ACTS = [self::BUY => 'buy', self::STATUS => 'status', self::PRINT => 'printLabel', self::DOWNLOAD => 'download', self::REMOVE => 'remove'];

    private const PARCEL_FIELDS = ['weight_g', 'length_cm', 'width_cm', 'height_cm'];

    public static function url(array $query = []): string
    {
        return Plugin::moduleUrl(Plugin::SLUG, $query);
    }

    public function render(): string
    {
        if (! current_user_can(\Taxmod\WordPress\Plugin::CAPABILITY)) {
            return '';
        }

        $parcel = $this->parcelFromRequest($_GET);
        $sections = [['title' => __('Sendung', 'taxmod-shipping'), 'html' => $this->parcelForm($parcel)]];

        if ($parcel instanceof Parcel) {
            $sections[] = ['title' => __('Tarife', 'taxmod-shipping'), 'html' => $this->offers($parcel)];
        } elseif (is_string($parcel)) {
            $sections[] = ['html' => '<div class="notice notice-warning inline"><p>' . esc_html($parcel) . '</p></div>'];
        }

        $sections[] = ['title' => __('Gekaufte Etiketten', 'taxmod-shipping'), 'html' => $this->purchases()];

        return ListPage::page(__('Versand', 'taxmod-shipping'), [], $sections, Plugin::VERSION, 'Taxmod Versand');
    }

    /**
     * @param array<string, mixed> $source $_GET or $_POST
     * @return Parcel|string|null null when nothing was asked yet, a string saying what is wrong
     */
    private function parcelFromRequest(array $source): Parcel|string|null
    {
        $values = [];
        foreach (self::PARCEL_FIELDS as $field) {
            $raw = isset($source[$field]) ? sanitize_text_field(wp_unslash((string) $source[$field])) : '';
            $values[$field] = $raw === '' ? null : (is_numeric(str_replace(',', '.', $raw)) ? (int) ceil((float) str_replace(',', '.', $raw)) : -1);
        }

        if (array_filter($values, static fn (?int $v): bool => $v !== null) === []) {
            return null;
        }

        $country = isset($source['country']) ? strtoupper(sanitize_key(wp_unslash((string) $source['country']))) : 'DE';

        try {
            return new Parcel((int) $values['weight_g'], (int) $values['length_cm'], (int) $values['width_cm'], (int) $values['height_cm'], $country === '' ? 'DE' : $country);
        } catch (ShippingError) {
            return __('Gewicht und alle drei Maße müssen über 0 liegen; das Land ist ein Kürzel wie DE.', 'taxmod-shipping');
        }
    }

    private function parcelForm(Parcel|string|null $parcel): string
    {
        $value = static fn (string $field): string => isset($_GET[$field]) ? sanitize_text_field(wp_unslash((string) $_GET[$field])) : '';
        $number = static fn (string $field, string $unit): string => '<input type="number" min="1" step="1" required id="taxship-' . esc_attr($field) . '" name="' . esc_attr($field) . '" value="' . esc_attr($value($field)) . '" style="width:8em"> ' . esc_html($unit);
        $country = $value('country') === '' ? 'DE' : $value('country');

        return '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="taxmod-lager-modul"><input type="hidden" name="module" value="' . esc_attr(Plugin::SLUG) . '">'
            . ListPage::form([
                ['label' => __('Gewicht', 'taxmod-shipping'), 'control' => $number('weight_g', 'g'), 'for' => 'taxship-weight_g', 'hint' => __('Mit Verpackung.', 'taxmod-shipping')],
                ['label' => __('Länge', 'taxmod-shipping'), 'control' => $number('length_cm', 'cm'), 'for' => 'taxship-length_cm'],
                ['label' => __('Breite', 'taxmod-shipping'), 'control' => $number('width_cm', 'cm'), 'for' => 'taxship-width_cm'],
                ['label' => __('Höhe', 'taxmod-shipping'), 'control' => $number('height_cm', 'cm'), 'for' => 'taxship-height_cm'],
                ['label' => __('Zielland', 'taxmod-shipping'), 'control' => '<input type="text" id="taxship-country" name="country" maxlength="3" value="' . esc_attr($country) . '" style="width:5em">', 'for' => 'taxship-country', 'hint' => __('ISO-Kürzel, z. B. DE, AT, NL.', 'taxmod-shipping')],
            ], '<button type="submit" class="button button-primary">' . ListPage::icon('search', __('Tarife vergleichen', 'taxmod-shipping')) . ' ' . esc_html__('Tarife vergleichen', 'taxmod-shipping') . '</button>')
            . '</form>';
    }

    private function compare(Parcel $parcel): RateComparison
    {
        return RateComparison::of((array) apply_filters(Plugin::RATES, [], $parcel->toArray()));
    }

    private function offers(Parcel $parcel): string
    {
        $comparison = $this->compare($parcel);
        $cheapest = $comparison->cheapest();
        $rows = [];

        foreach ($comparison->offers as $i => $rate) {
            $rows[] = [
                esc_html(strtoupper($rate->carrier)),
                esc_html($rate->name) . ($rate === $cheapest ? ' <strong>(' . esc_html__('günstigster', 'taxmod-shipping') . ')</strong>' : ''),
                esc_html(self::euro($rate->priceCents)),
                $rate->tracked ? esc_html__('ja', 'taxmod-shipping') : esc_html__('nein', 'taxmod-shipping'),
                $rate->liabilityEuro > 0 ? esc_html(self::euro($rate->liabilityEuro * 100)) : '–',
                $this->buyDialog('taxship-buy-' . $i, $rate, $parcel),
            ];
        }

        $failed = '';
        foreach ($comparison->failures as $carrier => $reason) {
            $failed .= '<li><strong>' . esc_html(strtoupper($carrier)) . ':</strong> ' . esc_html($reason) . '</li>';
        }

        return ListPage::grid(
            [[__('Dienst', 'taxmod-shipping')], [__('Produkt', 'taxmod-shipping')], [__('Preis', 'taxmod-shipping'), \Taxmod\WordPress\Draw\GridMarkup::NUMBER], [__('Sendungsverfolgung', 'taxmod-shipping')], [__('Haftung', 'taxmod-shipping'), \Taxmod\WordPress\Draw\GridMarkup::NUMBER], ['']],
            $rows,
            esc_html__('Kein angeschlossener Dienst hat ein Angebot für diese Sendung. Dienste werden unter Einstellungen angeschlossen (z. B. DHL mit API-Schlüssel).', 'taxmod-shipping')
        ) . ($failed === '' ? '' : '<p class="description">' . esc_html__('Ohne Antwort:', 'taxmod-shipping') . '</p><ul>' . $failed . '</ul>');
    }

    private function buyDialog(string $switch, Rate $rate, Parcel $parcel): string
    {
        $formId = $switch . '-form';
        $field = static fn (string $name, string $label, int $max): string => '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html($label) . '</span>'
            . '<input type="text" name="receiver[' . esc_attr($name) . ']" maxlength="' . $max . '" form="' . esc_attr($formId) . '" style="width:16em"></label>';
        $contacts = '<option value="0">' . esc_html__('— neuer Kontakt, unten eintragen —', 'taxmod-shipping') . '</option>';
        foreach ($this->contactNames() as $id => $name) {
            $contacts .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($name) . '</option>';
        }
        $hidden = '';
        foreach ($parcel->toArray() as $name => $value) {
            $hidden .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }

        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: carrier, 2: product, 3: price */
            __('%1$s %2$s für %3$s kaufen. Bezahlt wird danach auf der Seite des Dienstes.', 'taxmod-shipping'),
            strtoupper($rate->carrier),
            $rate->name,
            self::euro($rate->priceCents)
        )) . '</p>'
            . '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html__('Empfänger', 'taxmod-shipping') . '</span>'
            . '<select name="receiver_contact" form="' . esc_attr($formId) . '" style="width:16em">' . $contacts . '</select></label>'
            . $field('name', __('Name', 'taxmod-shipping'), 35)
            . $field('street', __('Straße', 'taxmod-shipping'), 35) . $field('number', __('Hausnummer', 'taxmod-shipping'), 10)
            . $field('postal_code', __('PLZ', 'taxmod-shipping'), 17) . $field('city', __('Ort', 'taxmod-shipping'), 35)
            . $field('email', __('E-Mail', 'taxmod-shipping'), 70)
            . '<input type="hidden" name="receiver[country]" value="' . esc_attr($parcel->country) . '" form="' . esc_attr($formId) . '">';

        return '<form id="' . esc_attr($formId) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::BUY, '_wpnonce', true, false) . $hidden
            . '<input type="hidden" name="carrier" value="' . esc_attr($rate->carrier) . '"><input type="hidden" name="product" value="' . esc_attr($rate->product) . '">'
            . '<input type="hidden" name="price_cents" value="' . esc_attr((string) $rate->priceCents) . '"></form>'
            . ListPage::dialogAction($switch, __('Kaufen', 'taxmod-shipping'), __('Etikett kaufen', 'taxmod-shipping'), $body, 'action', self::BUY, sprintf(
                /* translators: %s: price */
                __('Für %s kaufen', 'taxmod-shipping'),
                self::euro($rate->priceCents)
            ), $formId, 'cart');
    }

    private function purchases(): string
    {
        $rows = [];

        try {
            $all = (new ShipmentStore())->all();
        } catch (ShippingError $failure) {
            return '<div class="notice notice-warning inline"><p>' . esc_html($failure->getMessage()) . '</p></div>';
        }

        foreach ($all as $purchase) {
            $rows[] = [
                esc_html($purchase->createdAt),
                esc_html($purchase->receiver),
                esc_html(strtoupper($purchase->rate->carrier) . ' ' . $purchase->rate->name),
                esc_html(self::euro($purchase->rate->priceCents)),
                $purchase->isPaid() ? esc_html__('bezahlt', 'taxmod-shipping') : esc_html__('wartet auf Bezahlung', 'taxmod-shipping'),
                esc_html(implode(', ', $purchase->numbers)),
                $this->purchaseButtons($purchase),
            ];
        }

        return ListPage::grid(
            [[__('Gekauft', 'taxmod-shipping')], [__('Empfänger', 'taxmod-shipping')], [__('Produkt', 'taxmod-shipping')], [__('Preis', 'taxmod-shipping'), \Taxmod\WordPress\Draw\GridMarkup::NUMBER], [__('Stand', 'taxmod-shipping')], [__('Sendungsnummer', 'taxmod-shipping')], ['']],
            $rows,
            esc_html__('Noch nichts gekauft.', 'taxmod-shipping')
        );
    }

    private function purchaseButtons(Purchase $purchase): string
    {
        $post = static fn (string $action, string $icon, string $label, string $extra = ''): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . wp_nonce_field($action . $purchase->id, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="purchase" value="' . esc_attr($purchase->id) . '">' . $extra
            . '<button type="submit" class="button" title="' . esc_attr($label) . '">' . ListPage::icon($icon, $label) . '</button></form>';
        $html = '';

        if (! $purchase->isPaid() && $purchase->payUrl !== '') {
            $html .= '<a class="button button-primary" href="' . esc_url($purchase->payUrl) . '" target="_blank" rel="noopener" title="' . esc_attr__('Beim Dienst bezahlen', 'taxmod-shipping') . '">'
                . ListPage::icon('money-alt', __('Beim Dienst bezahlen', 'taxmod-shipping')) . '</a> ';
        }

        $html .= $post(self::STATUS, 'update', __('Stand abfragen', 'taxmod-shipping')) . ' ';

        foreach ($purchase->isPaid() ? $purchase->numbers : [] as $number) {
            $html .= '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => self::DOWNLOAD, 'purchase' => $purchase->id, 'number' => $number], admin_url('admin-post.php')), self::DOWNLOAD . $purchase->id)) . '" title="' . esc_attr__('Etikett als PDF', 'taxmod-shipping') . '">'
                . ListPage::icon('pdf', __('Etikett als PDF', 'taxmod-shipping')) . '</a> '
                . $post(self::PRINT, ListPage::ICON_PRINT, __('Etikett drucken', 'taxmod-shipping'), '<input type="hidden" name="number" value="' . esc_attr($number) . '">') . ' ';
        }

        return $html . $post(self::REMOVE, ListPage::ICON_DELETE, __('Sendung löschen (rückgängig machbar)', 'taxmod-shipping'));
    }

    /** admin-post «Kaufen»: capability → nonce → validate (the price shown is still the price) → act. */
    public function buy(): string
    {
        self::allowed();
        ListPage::nonce(self::BUY);

        $parcel = $this->parcelFromRequest($_POST);

        if (! $parcel instanceof Parcel) {
            throw new ShippingError(__('Die Sendung ist unvollständig.', 'taxmod-shipping'));
        }

        $carrier = sanitize_key(wp_unslash((string) ($_POST['carrier'] ?? '')));
        $product = sanitize_text_field(wp_unslash((string) ($_POST['product'] ?? '')));
        $shown = absint($_POST['price_cents'] ?? 0);
        $rate = $this->compare($parcel)->find($carrier, $product);

        if ($rate === null) {
            throw new ShippingError(__('Dieses Angebot gibt es nicht mehr. Bitte die Tarife neu vergleichen.', 'taxmod-shipping'));
        }

        if ($rate->priceCents !== $shown) {
            throw new ShippingError(sprintf(
                /* translators: 1: price shown, 2: price now */
                __('Der Preis hat sich geändert (%1$s → %2$s). Nichts gekauft; bitte neu vergleichen.', 'taxmod-shipping'),
                self::euro($shown),
                self::euro($rate->priceCents)
            ));
        }

        $settings = new Settings();
        $contact = absint($_POST['receiver_contact'] ?? 0);
        $typed = Address::fromArray(array_map(static fn (mixed $v): string => is_scalar($v) ? sanitize_text_field(wp_unslash((string) $v)) : '', is_array($_POST['receiver'] ?? null) ? $_POST['receiver'] : []));
        $receiver = $contact > 0 ? (new Contacts())->address($contact) : $typed;

        if (! $receiver->isComplete()) {
            throw new ShippingError(__('Empfänger unvollständig: Name, Straße, PLZ und Ort sind nötig.', 'taxmod-shipping'));
        }

        // Everything the record needs is checked before the carrier is asked, so no cart is made that cannot be kept.
        $sender = $settings->sender();
        $firm = $settings->carrierFirms()[$rate->carrier] ?? 0;
        $missing = ShipmentStore::roles()->missing();

        if ($firm === 0) {
            throw new ShippingError(sprintf(/* translators: %s: carrier */ __('Unter Einstellungen › Versand fehlt die Firma für %s.', 'taxmod-shipping'), strtoupper($rate->carrier)));
        }

        if ($missing !== []) {
            throw new ShippingError(__('Das Modell kennt «Sendungen» noch nicht (fehlende Rollen):', 'taxmod-shipping') . ' ' . implode(', ', $missing));
        }

        $answer = apply_filters(Plugin::BUY, null, $rate->toArray(), ['sender' => $sender->toArray(), 'receiver' => $receiver->toArray(), 'email' => $settings->email()]);
        $purchase = Purchase::bought('', $rate, $receiver->name, is_array($answer) ? $answer : null, current_time('mysql'));
        (new ShipmentStore())->add($purchase, $parcel, $contact > 0 ? $contact : $typed, $settings->senderContact(), $firm);

        return self::url();
    }

    public function status(): string
    {
        $purchase = $this->purchaseFromPost(self::STATUS);
        $answer = apply_filters(Plugin::STATUS, null, ['carrier' => $purchase->rate->carrier, 'reference' => $purchase->reference]);
        $updated = $purchase->withStatus(is_array($answer) ? $answer : null);

        if ($updated != $purchase) {
            (new ShipmentStore())->update((int) $purchase->id, $updated);
        }

        return self::url();
    }

    /** Only on the owner's click (D-1496): one job to the printer queue chosen in the settings. */
    public function printLabel(): string
    {
        $purchase = $this->purchaseFromPost(self::PRINT);
        $number = $this->numberOf($purchase, $_POST);
        $queue = (new Settings())->queue();

        if ($queue === '') {
            throw new ShippingError(__('Kein Drucker gewählt: unter Einstellungen › Versand die Druckerwarteschlange eintragen.', 'taxmod-shipping'));
        }

        (new ModelDoor())->queuePrint($this->label($purchase, $number), 'versand-' . $purchase->rate->carrier, $number, strtoupper($purchase->rate->carrier) . ' ' . $number, 1, ['queue' => $queue]);

        return self::url();
    }

    /** The label PDF to the browser: the one place this module sends bytes itself (CD-8: a file, not markup). */
    public function download(): void
    {
        self::allowed();
        $id = sanitize_text_field(wp_unslash((string) ($_GET['purchase'] ?? '')));
        ListPage::nonce(self::DOWNLOAD . $id);
        $purchase = (new ShipmentStore())->find((int) $id) ?? throw new ShippingError(__('Diesen Kauf gibt es nicht.', 'taxmod-shipping'));
        $number = $this->numberOf($purchase, $_GET);
        $pdf = $this->label($purchase, $number);

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="etikett-' . $number . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        print $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
        exit;
    }

    public function remove(): string
    {
        (new ShipmentStore())->remove((int) $this->purchaseFromPost(self::REMOVE)->id);

        return self::url();
    }

    private function label(Purchase $purchase, string $number): string
    {
        $pdf = apply_filters(Plugin::LABEL, null, $purchase->rate->carrier, $number);

        if ($pdf instanceof \WP_Error) {
            throw new ShippingError($pdf->get_error_message());
        }

        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new ShippingError(__('Der Dienst liefert für diese Nummer kein Etikett.', 'taxmod-shipping'));
        }

        return $pdf;
    }

    /** @param array<string, mixed> $source */
    private function numberOf(Purchase $purchase, array $source): string
    {
        $number = preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash((string) ($source['number'] ?? '')))) ?? '';

        if (! $purchase->isPaid() || ! in_array($number, $purchase->numbers, true)) {
            throw new ShippingError(__('Das Etikett gibt es erst nach der Bezahlung.', 'taxmod-shipping'));
        }

        return $number;
    }

    /** @return array<int, string> */
    private function contactNames(): array
    {
        static $names = null;

        return $names ??= (new Contacts())->names();
    }

    private function purchaseFromPost(string $action): Purchase
    {
        self::allowed();
        $id = sanitize_text_field(wp_unslash((string) ($_POST['purchase'] ?? '')));
        ListPage::nonce($action . $id);

        return (new ShipmentStore())->find((int) $id) ?? throw new ShippingError(__('Diesen Kauf gibt es nicht.', 'taxmod-shipping'));
    }

    private static function allowed(): void
    {
        if (! current_user_can(\Taxmod\WordPress\Plugin::CAPABILITY)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-shipping'), self::url());
        }
    }

    private static function euro(int $cents): string
    {
        return ListPage::number(Money::decimal($cents)) . ' €';
    }
}
