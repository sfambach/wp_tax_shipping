<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Renderer\DecimalText;
use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\RecordPick;
use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\LabelOrigin;
use TaxmodShipping\Core\LabelRows;
use TaxmodShipping\Core\LetterRates;
use TaxmodShipping\Core\MailLabels;
use TaxmodShipping\Core\Money;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\ParcelInput;
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
    public const STAMP    = 'taxship_stamp';
    public const MAIL_PDF = 'taxship_mail_pdf';
    public const KEPT_PDF = 'taxship_kept_pdf';

    /** D-1513: the Etiketten module's store of postage PDFs; a shipment's stamp is kept under «taxship-<id>». */
    public const POSTAGE_PUT = 'taxmod_postage_put';

    public const POSTAGE_GET = 'taxmod_postage_get';

    public const POSTAGE_KEYS = 'taxmod_postage_keys';

    public const POSTAGE_PRINTED = 'taxmod_postage_printed';

    /** D-1511: the mail module's labels and the PDF behind one of their links. */
    public const MAIL_LABELS = 'taxmail_shipping_labels';

    public const MAIL_LABEL_PDF = 'taxmail_shipping_label_pdf';

    public const MAIL_LABEL_PRINTED = 'taxmail_shipping_label_printed';

    /** admin-post action → method */
    public const ACTS = [self::BUY => 'buy', self::STATUS => 'status', self::PRINT => 'printLabel', self::DOWNLOAD => 'download', self::REMOVE => 'remove', self::STAMP => 'stamp', self::MAIL_PDF => 'mailPdf', self::KEPT_PDF => 'keptPdf'];

    /** D-1507: the Etiketten module's 62 mm postage print, attached by its handle only. */
    public const LABEL62 = 'taxmod-label62';

    private const STAMP_FORM = 'taxship-stamp-form';

    private const STAMP_SWITCH = 'taxship-stamp';

    private ?WorkplacePrinters $printers = null;

    private ?Countries $countries = null;

    private ?ParcelControls $parcelControls = null;

    /** D-1515: the labels on the page's front; the rest are behind «Alle Etiketten». */
    private const SHORT_LIST = 5;

    private const PARCEL_FIELDS = ['weight_g', 'length_cm', 'width_cm', 'height_cm'];

    public static function url(array $query = []): string
    {
        return Plugin::moduleUrl(Plugin::SLUG, $query);
    }

    public function render(): string
    {
        if (! current_user_can(\Taxmod\WordPress\Rights::SHIP)) {
            return '';
        }

        if (isset($_GET['label'])) {
            return $this->oneLabel(absint($_GET['label']));
        }

        if (sanitize_key(wp_unslash((string) ($_GET['view'] ?? ''))) === 'labels') {
            return $this->allLabels();
        }

        $source = $this->withPackaging($_GET);
        $parcel = $this->parcelFromRequest($source);
        $sections = [['title' => __('Sendung', 'taxmod-shipping'), 'html' => $this->parcelForm($parcel, $source)]];

        if (isset($_GET['taxship_unkept'])) {
            $why = sanitize_text_field(rawurldecode(wp_unslash((string) $_GET['taxship_unkept'])));
            array_unshift($sections, ['html' => '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(/* translators: %s: reason */ __('Sendung eingetragen, die Marke aber nicht gespeichert: %s', 'taxmod-shipping'), $why)) . '</p></div>']);
        }

        if ($parcel instanceof Parcel) {
            $sections[] = ['title' => __('Tarife', 'taxmod-shipping'), 'html' => $this->offers($parcel)];
        } elseif (is_string($parcel)) {
            $sections[] = ['html' => '<div class="notice notice-warning inline"><p>' . esc_html($parcel) . '</p></div>'];
        }

        // D-1515: one list of labels, the newest five here, all of them one click away.
        $sections[] = ['title' => __('Etiketten', 'taxmod-shipping'), 'html' => $this->labels(self::SHORT_LIST)];

        return $this->page(__('Versand', 'taxmod-shipping'), $sections);
    }

    /**
     * The same scripts on every view of this page: the template fill, the stamp dialog, the 62 mm print.
     *
     * @param list<array{title?: string, html: string}> $sections
     */
    private function page(string $title, array $sections): string
    {
        wp_enqueue_script('taxship-parcel', plugins_url('assets/parcel-template.js', dirname(__DIR__, 2) . '/wp_tax_shipping.php'), [], Plugin::VERSION, true);

        if (self::canPrintPostage()) {
            wp_enqueue_script(self::LABEL62);
            wp_enqueue_script('taxship-stamp', plugins_url('assets/stamp-upload.js', dirname(__DIR__, 2) . '/wp_tax_shipping.php'), [self::LABEL62], Plugin::VERSION, true);
        }

        return ListPage::page($title, [], $sections, Plugin::VERSION, 'Taxmod Versand');
    }

    /** D-1515: every label, the same list as on the page's front with no limit. */
    private function allLabels(): string
    {
        $html = '<p>' . ListPage::back(self::url(), __('Zurück zum Versand', 'taxmod-shipping')) . '</p>'
            . (self::canPrintPostage() ? $this->stampForm() . ListPage::toolbar([$this->stampDialog()]) : '') . $this->labels(null);

        return $this->page(__('Etiketten', 'taxmod-shipping'), [['title' => __('Alle Etiketten', 'taxmod-shipping'), 'html' => $html]]);
    }

    /** D-1515, D-1376: one label in the standard single view — status, back to the list, its actions, its data. */
    private function oneLabel(int $id): string
    {
        try {
            $purchase = $this->all()[$id] ?? null;
        } catch (ShippingError $failure) {
            $purchase = null;
        }

        if ($purchase === null) {
            return $this->page(__('Etiketten', 'taxmod-shipping'), [['html' => '<div class="notice notice-warning inline"><p>' . esc_html__('Dieses Etikett gibt es nicht.', 'taxmod-shipping') . '</p></div>'
                . ListPage::back(self::url(['view' => 'labels']), __('Alle Etiketten', 'taxmod-shipping'))]]);
        }

        $facts = [
            [esc_html__('Herkunft', 'taxmod-shipping'), esc_html(self::originWord(LabelOrigin::of($purchase->reference)))],
            [esc_html__('Gekauft', 'taxmod-shipping'), esc_html(ListPage::date($purchase->createdAt))],
            [esc_html__('Dienst', 'taxmod-shipping'), esc_html(strtoupper($purchase->rate->carrier))],
            [esc_html__('Produkt', 'taxmod-shipping'), esc_html($purchase->rate->name)],
            [esc_html__('Preis', 'taxmod-shipping'), esc_html(self::euro($purchase->rate->priceCents))],
            [esc_html__('Empfänger', 'taxmod-shipping'), esc_html($purchase->receiver)],
            [esc_html__('Sendungsnummer', 'taxmod-shipping'), esc_html(implode(', ', $purchase->numbers))],
            [esc_html__('Referenz', 'taxmod-shipping'), esc_html($purchase->reference)],
        ];
        $steps = [['awaiting_payment', __('wartet auf Bezahlung', 'taxmod-shipping')], ['paid', __('bezahlt', 'taxmod-shipping')]];

        return $this->page(__('Etiketten', 'taxmod-shipping'), [['html' => ListPage::record(
            $purchase->rate->name,
            $steps,
            $purchase->isPaid() ? 'paid' : 'awaiting_payment',
            ListPage::stepButton(__('Alle Etiketten', 'taxmod-shipping'), false, self::url(['view' => 'labels'])),
            '',
            [$this->purchaseButtons($purchase)],
            ListPage::grid([[__('Feld', 'taxmod-shipping')], [__('Wert', 'taxmod-shipping')]], $facts)
        )]]);
    }

    /**
     * @param array<string, mixed> $source $_GET or $_POST
     * @return Parcel|string|null null when nothing was asked yet, a string saying what is wrong
     */
    private function parcelFromRequest(array $source): Parcel|string|null
    {
        // D-1538: the form's own controls; the buy dialog still carries the parcel as plain hidden numbers.
        if (! isset($source['weight_g']) && isset($source[ParcelControls::PREFIX]) && $this->parcelControls()->available()) {
            try {
                return ParcelInput::of(ShipmentStore::roles(), $this->parcelControls()->typed($source), $this->countryFrom($source));
            } catch (ShippingError) {
                return __('Gewicht und alle drei Maße müssen über 0 liegen, Gewicht in Gramm und Maße in Meter (mit Präfix, z. B. cm).', 'taxmod-shipping');
            }
        }

        $values = [];
        foreach (self::PARCEL_FIELDS as $field) {
            $raw = isset($source[$field]) ? sanitize_text_field(wp_unslash((string) $source[$field])) : '';
            $values[$field] = $raw === '' ? null : (is_numeric(str_replace(',', '.', $raw)) ? (int) ceil((float) str_replace(',', '.', $raw)) : -1);
        }

        if (array_filter($values, static fn (?int $v): bool => $v !== null) === []) {
            return null;
        }

        try {
            return new Parcel((int) $values['weight_g'], (int) $values['length_cm'], (int) $values['width_cm'], (int) $values['height_cm'], $this->countryFrom($source));
        } catch (ShippingError) {
            return __('Gewicht und alle drei Maße müssen über 0 liegen; das Land ist ein Kürzel wie DE.', 'taxmod-shipping');
        }
    }

    /** @param array<string, mixed> $source */
    private function countryFrom(array $source): string
    {
        $country = isset($source['country']) ? strtoupper(sanitize_key(wp_unslash((string) $source['country']))) : 'DE';
        // D-1532: a country chosen from the list «Länder» goes to the carriers as its ISO code.
        if (isset($source[Countries::FIELD])) {
            $chosen = $this->countries()->codes()->codeOf(absint($source[Countries::FIELD]));
            $country = $chosen !== '' ? $chosen : $country;
        }

        return $country === '' ? 'DE' : $country;
    }

    /**
     * D-1505: a template chosen anew fills the edges and the weight; once applied, what the owner typed wins, so the
     * template can be a start and nothing more. Reading only — nothing is written.
     *
     * @param array<string, mixed> $source $_GET
     * @return array<string, mixed>
     */
    private function withPackaging(array $source): array
    {
        $chosen = absint($source['packaging'] ?? 0);

        if ($chosen === 0 || $chosen === absint($source['applied_packaging'] ?? 0)) {
            return $source;
        }

        $template = (new PackagingStore())->find($chosen);

        if ($template === null) {
            return $source;
        }

        return $this->parcelControls()->available()
            ? array_merge($source, [ParcelControls::PREFIX => $this->parcelControls()->partsOf($template)])
            : array_merge($source, array_filter($template->formValues(), static fn (string $v): bool => $v !== ''));
    }

    /** @param array<string, mixed> $source */
    private function parcelForm(Parcel|string|null $parcel, array $source): string
    {
        $value = static fn (string $field): string => isset($source[$field]) ? sanitize_text_field(wp_unslash((string) $source[$field])) : '';
        $number = static fn (string $field, string $unit): string => ListPage::quantity($field, $unit, $value($field), ['id' => 'taxship-' . $field, 'size' => '8']);
        $packagings = new PackagingStore();
        $controlled = $this->parcelControls()->available();
        $chosen = absint($source['packaging'] ?? 0);
        $templateRow = $packagings->root() === 0 ? [] : [[
            'label' => __('Vorlage', 'taxmod-shipping'),
            // The template's values ride with the field, so a choice fills the form at once (Stefan 20.11.); the server fills too, without script.
            'control' => '<input type="hidden" name="applied_packaging" value="' . esc_attr((string) $chosen) . '" data-taxship-packagings="'
                . esc_attr((string) wp_json_encode(array_map(fn (\TaxmodShipping\Core\Packaging $p): array => $controlled ? $this->parcelControls()->namesOf($p) : $p->formValues(), $packagings->all()))) . '">'
                . \Taxmod\WordPress\Admin\RecordPick::field('packaging', $packagings->pickTree(), $chosen > 0 ? $chosen : null),
            'hint' => __('Versandverpackung aus dem Lager: füllt Maße und Gewicht (Höchstgewicht). Alles bleibt änderbar.', 'taxmod-shipping'),
        ]];
        $country = $this->countryFrom($source);
        if ($controlled) {
            // D-1538: the modeller's unit values, filled with what was typed or what the template gave.
            $controls = $this->parcelControls()->controls($this->parcelControls()->typed($source));
            $sizeRows = [
                ['label' => __('Gewicht', 'taxmod-shipping'), 'control' => $controls['weight'], 'hint' => __('Mit Verpackung.', 'taxmod-shipping')],
                ['label' => __('Abmessung', 'taxmod-shipping'), 'control' => $controls['dimension']],
            ];
        } else {
            $sizeRows = [
                ['label' => __('Gewicht', 'taxmod-shipping'), 'control' => $number('weight_g', 'g'), 'for' => 'taxship-weight_g', 'hint' => __('Mit Verpackung.', 'taxmod-shipping')],
                ['label' => __('Länge', 'taxmod-shipping'), 'control' => $number('length_cm', 'cm'), 'for' => 'taxship-length_cm'],
                ['label' => __('Breite', 'taxmod-shipping'), 'control' => $number('width_cm', 'cm'), 'for' => 'taxship-width_cm'],
                ['label' => __('Höhe', 'taxmod-shipping'), 'control' => $number('height_cm', 'cm'), 'for' => 'taxship-height_cm'],
            ];
        }
        $countryRow = $this->countries()->root() > 0
            ? ['label' => __('Zielland', 'taxmod-shipping'), 'control' => $this->countries()->chooser($country)]
            : ['label' => __('Zielland', 'taxmod-shipping'), 'control' => ListPage::field(InputKind::Line, 'country', $country, ['id' => 'taxship-country', 'size' => '5']), 'for' => 'taxship-country', 'hint' => __('ISO-Kürzel, z. B. DE, AT, NL.', 'taxmod-shipping')];

        // Stefan 20.11. 18:27 «den Button einfach zum Tarif vergleichen, also rechts daneben … wieder die Toolbar-Idee»: one toolbar
        // line. The stamp's own form stands outside this one; its dialog sends only to it.
        $stamp = self::canPrintPostage();

        return ($stamp ? $this->stampForm() : '') . '<form method="get" action="' . esc_url(admin_url('admin.php')) . '">'
            . '<input type="hidden" name="page" value="taxmod-lager-modul"><input type="hidden" name="module" value="' . esc_attr(Plugin::SLUG) . '">'
            . ListPage::form([
                ...$templateRow,
                ...$sizeRows,
                $countryRow,
            ], '<button type="submit" class="button button-primary">' . ListPage::icon('search', __('Tarife vergleichen', 'taxmod-shipping')) . ' ' . esc_html__('Tarife vergleichen', 'taxmod-shipping') . '</button>'
                . ($stamp ? ' ' . $this->stampDialog() : ''))
            . '</form>';
    }

    private function parcelControls(): ParcelControls
    {
        return $this->parcelControls ??= new ParcelControls();
    }

    private function countries(): Countries
    {
        return $this->countries ??= new Countries();
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
        $field = static fn (string $name, string $label): string => '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html($label) . '</span>'
            . ListPage::field($name === 'email' ? InputKind::Email : InputKind::Line, 'receiver[' . $name . ']', '', ['form' => $formId, 'size' => '24']) . '</label>';
        $contacts = '<option value="0">' . esc_html__('— neuer Kontakt, unten eintragen —', 'taxmod-shipping') . '</option>';
        foreach ($this->contactNames() as $id => $name) {
            $contacts .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($name) . '</option>';
        }
        $hidden = '';
        foreach ($parcel->toArray() as $name => $value) {
            $hidden .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }

        $shop = $rate->shopUrl !== '';
        $body = ($shop
            ? '<p>' . esc_html(sprintf(
                /* translators: 1: product, 2: price */
                __('%1$s für %2$s: Die Marke kaufst du im Shop der Post (dort auch mit PayPal). Danach hier eintragen; hier fließt kein Geld.', 'taxmod-shipping'),
                $rate->name,
                self::euro($rate->priceCents)
            )) . '</p><p><a class="button" href="' . esc_url($rate->shopUrl) . '" target="_blank" rel="noopener noreferrer">' . ListPage::icon('external', __('Shop der Post öffnen', 'taxmod-shipping')) . ' '
                . esc_html__('Shop der Post öffnen', 'taxmod-shipping') . '</a></p>'
                . '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html__('Sendungsnummer', 'taxmod-shipping') . '</span>'
                . ListPage::field(InputKind::Line, 'number', '', ['form' => $formId, 'size' => '24', 'placeholder' => __('falls vorhanden', 'taxmod-shipping')]) . '</label>'
            : '<p>' . esc_html(sprintf(
                /* translators: 1: carrier, 2: product, 3: price */
                __('%1$s %2$s für %3$s kaufen. Bezahlt wird danach auf der Seite des Dienstes.', 'taxmod-shipping'),
                strtoupper($rate->carrier),
                $rate->name,
                self::euro($rate->priceCents)
            )) . '</p>')
            . '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html__('Empfänger', 'taxmod-shipping') . '</span>'
            . '<select name="receiver_contact" form="' . esc_attr($formId) . '" style="width:16em">' . $contacts . '</select></label>'
            . $field('name', __('Name', 'taxmod-shipping'))
            . $field('street', __('Straße', 'taxmod-shipping')) . $field('number', __('Hausnummer', 'taxmod-shipping'))
            . $field('postal_code', __('PLZ', 'taxmod-shipping')) . $field('city', __('Ort', 'taxmod-shipping'))
            . $field('email', __('E-Mail', 'taxmod-shipping'))
            . '<input type="hidden" name="receiver[country]" value="' . esc_attr($parcel->country) . '" form="' . esc_attr($formId) . '">';

        return '<form id="' . esc_attr($formId) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::BUY, '_wpnonce', true, false) . $hidden
            . '<input type="hidden" name="carrier" value="' . esc_attr($rate->carrier) . '"><input type="hidden" name="product" value="' . esc_attr($rate->product) . '">'
            . '<input type="hidden" name="price_cents" value="' . esc_attr((string) $rate->priceCents) . '"></form>'
            . ListPage::dialogAction($switch, $shop ? __('Im Post-Shop kaufen und hier als gekauft eintragen', 'taxmod-shipping') : __('Etikett kaufen', 'taxmod-shipping'), $shop ? __('Briefmarke bei der Post kaufen', 'taxmod-shipping') : __('Etikett kaufen', 'taxmod-shipping'), $body, 'action', self::BUY, sprintf(
                /* translators: %s: price */
                $shop ? __('Als gekauft eintragen (%s)', 'taxmod-shipping') : __('Für %s kaufen', 'taxmod-shipping'),
                self::euro($rate->priceCents)
            ), $formId, 'cart');
    }

    /** @var array<int, Purchase>|null */
    private ?array $all = null;

    /**
     * @return array<int, Purchase>
     * @throws ShippingError
     */
    private function all(): array
    {
        return $this->all ??= (new ShipmentStore())->all();
    }

    /**
     * D-1515: shipments and the labels from mails that no shipment holds yet, as one list with their origin (D-1511: a label from a
     * mail is turned into a shipment in the stamp dialog by its row's button). With a limit, the newest ones and a link to all.
     */
    private function labels(?int $limit): string
    {
        try {
            $purchases = $this->all();
        } catch (ShippingError $failure) {
            return '<div class="notice notice-warning inline"><p>' . esc_html($failure->getMessage()) . '</p></div>';
        }

        $all = LabelRows::of($purchases, self::canPrintPostage() ? apply_filters(self::MAIL_LABELS, []) : []);
        $rows = [];

        foreach ($limit === null ? $all : array_slice($all, 0, $limit) as $row) {
            $purchase = $row['purchase'];
            $mail = $row['mail'];

            if ($purchase !== null) {
                $rows[] = [
                    esc_html(ListPage::date($purchase->createdAt)),
                    esc_html(self::originWord($row['origin'])),
                    esc_html($purchase->receiver),
                    '<a href="' . esc_url(self::url(['label' => $purchase->id])) . '">' . esc_html(strtoupper($purchase->rate->carrier) . ' ' . $purchase->rate->name) . '</a>',
                    esc_html(self::euro($purchase->rate->priceCents)),
                    $purchase->isPaid() ? esc_html__('bezahlt', 'taxmod-shipping') : esc_html__('wartet auf Bezahlung', 'taxmod-shipping'),
                    esc_html(implode(', ', $purchase->numbers)),
                    self::side($this->purchaseButtons($purchase)),
                ];
            } elseif ($mail !== null) {
                $pdf = wp_nonce_url(add_query_arg(['action' => self::MAIL_PDF, 'link' => rawurlencode($mail['link'])], admin_url('admin-post.php')), self::MAIL_PDF);
                $rows[] = [
                    esc_html(ListPage::date($mail['date'])),
                    esc_html(self::originWord($row['origin'])),
                    '',
                    esc_html(strtoupper($mail['carrier']) . ' ' . $mail['cart']),
                    '',
                    esc_html__('offen (aus Mail)', 'taxmod-shipping'),
                    '',
                    '<button type="button" class="button taxmod-square" data-taxship-mail-pdf="' . esc_url($pdf) . '" data-taxship-mail-cart="' . esc_attr($mail['cart']) . '" data-taxship-mail-carrier="' . esc_attr($mail['carrier']) . '" title="' . esc_attr__('Sendung anlegen und drucken', 'taxmod-shipping') . '">'
                        . ListPage::icon('upload', __('Sendung anlegen und drucken', 'taxmod-shipping')) . '</button>',
                ];
            }
        }

        $more = $limit !== null && count($all) > $limit
            ? '<p><a class="button" href="' . esc_url(self::url(['view' => 'labels'])) . '">' . esc_html(sprintf(/* translators: %d: count */ __('Alle %d Etiketten', 'taxmod-shipping'), count($all))) . '</a></p>'
            : '';

        return ListPage::grid(
            [[__('Gekauft', 'taxmod-shipping')], [__('Herkunft', 'taxmod-shipping')], [__('Empfänger', 'taxmod-shipping')], [__('Produkt', 'taxmod-shipping')], [__('Preis', 'taxmod-shipping'), \Taxmod\WordPress\Draw\GridMarkup::NUMBER], [__('Stand', 'taxmod-shipping')], [__('Sendungsnummer', 'taxmod-shipping')], ['']],
            $rows,
            esc_html__('Noch keine Etiketten.', 'taxmod-shipping')
        ) . $more;
    }

    /** Stefan 20.11. 18:27 «die Buttons sind jetzt übereinander … wir könnten die Buttons nebeneinander haben»: one line. */
    private static function side(string $buttons): string
    {
        return '<span style="display:inline-flex;gap:4px;white-space:nowrap">' . $buttons . '</span>';
    }

    private static function originWord(string $origin): string
    {
        return match ($origin) {
            LabelOrigin::MAIL   => __('aus Mail', 'taxmod-shipping'),
            LabelOrigin::UPLOAD => __('hochgeladen', 'taxmod-shipping'),
            LabelOrigin::SHOP   => __('im Shop gekauft', 'taxmod-shipping'),
            default             => __('beim Dienst gekauft', 'taxmod-shipping'),
        };
    }

    private function purchaseButtons(Purchase $purchase): string
    {
        $post = static fn (string $action, string $icon, string $label, string $extra = ''): string => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . wp_nonce_field($action . $purchase->id, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="purchase" value="' . esc_attr($purchase->id) . '">' . $extra
            . '<button type="submit" class="button taxmod-square" title="' . esc_attr($label) . '">' . ListPage::icon($icon, $label) . '</button></form>';
        $html = '';

        if (! $purchase->isPaid() && $purchase->payUrl !== '') {
            $html .= '<a class="button button-primary taxmod-square" href="' . esc_url($purchase->payUrl) . '" target="_blank" rel="noopener" title="' . esc_attr__('Beim Dienst bezahlen', 'taxmod-shipping') . '">'
                . ListPage::icon('money-alt', __('Beim Dienst bezahlen', 'taxmod-shipping')) . '</a> ';
        }

        $html .= $post(self::STATUS, 'update', __('Stand abfragen', 'taxmod-shipping')) . ' ';

        // D-1513: a kept stamp prints from the store, without choosing the file again.
        if (self::canPrintPostage() && in_array(self::postageKey((int) $purchase->id), $this->keptKeys(), true)) {
            $url = wp_nonce_url(add_query_arg(['action' => self::KEPT_PDF, 'purchase' => $purchase->id], admin_url('admin-post.php')), self::KEPT_PDF . $purchase->id);
            $html .= '<button type="button" class="button taxmod-square" data-taxship-kept-pdf="' . esc_url($url) . '" data-taxship-carrier="' . esc_attr($purchase->rate->carrier) . '" title="' . esc_attr__('Gespeicherte Marke drucken (62 mm)', 'taxmod-shipping') . '">'
                . ListPage::icon(ListPage::ICON_PRINT, __('Gespeicherte Marke drucken (62 mm)', 'taxmod-shipping')) . '</button> ';

            return $html . $post(self::REMOVE, ListPage::ICON_DELETE, __('Sendung löschen (rückgängig machbar)', 'taxmod-shipping'));
        }

        // D-1507: a stamp bought in the Post shop is a PDF on the owner's disk; the browser crops it to 62 mm and prints it on his click.
        if ($purchase->rate->carrier === LetterRates::CARRIER) {
            if (self::canPrintPostage()) {
                $html .= '<button type="button" class="button taxmod-square" data-taxmod-label62-pick="" data-taxmod-label62-cut-marks title="' . esc_attr__('Marke aus dem Post-Shop drucken (PDF wählen, 62 mm)', 'taxmod-shipping') . '">'
                    . ListPage::icon(ListPage::ICON_PRINT, __('Marke aus dem Post-Shop drucken (PDF wählen, 62 mm)', 'taxmod-shipping')) . '</button> ';
            }

            return $html . $post(self::REMOVE, ListPage::ICON_DELETE, __('Sendung löschen (rückgängig machbar)', 'taxmod-shipping'));
        }

        $printers = array_values(array_column(($this->printers ??= new WorkplacePrinters())->all(), 'name'));

        foreach ($purchase->isPaid() ? $purchase->numbers : [] as $number) {
            $html .= '<a class="button taxmod-square" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => self::DOWNLOAD, 'purchase' => $purchase->id, 'number' => $number], admin_url('admin-post.php')), self::DOWNLOAD . $purchase->id)) . '" title="' . esc_attr__('Etikett als PDF', 'taxmod-shipping') . '">'
                . ListPage::icon('pdf', __('Etikett als PDF', 'taxmod-shipping')) . '</a> '
                . $post(self::PRINT, ListPage::ICON_PRINT, $printers === [] ? __('Etikett drucken', 'taxmod-shipping') : sprintf(/* translators: %s: printer */ __('Etikett drucken auf %s', 'taxmod-shipping'), $printers[0]), '<input type="hidden" name="number" value="' . esc_attr($number) . '">') . ' ';
        }

        return $html . $post(self::REMOVE, ListPage::ICON_DELETE, __('Sendung löschen (rückgängig machbar)', 'taxmod-shipping'));
    }

    /**
     * D-1507: «Gekaufte Marke hochladen». The stamp PDF from the Post shop is read in the browser only (assets/stamp-upload.js
     * fills this dialog from its text); «Eintragen und drucken» prints it on 62 mm and then records the purchase.
     */
    private function stampDialog(): string
    {
        $formId = self::STAMP_FORM;
        $line = static fn (string $name, string $label, array $options = []): string => '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html($label) . '</span>'
            . ListPage::field(InputKind::Line, $name, '', $options + ['form' => $formId, 'size' => '24', 'id' => 'taxship-stamp-' . preg_replace('/\W+/', '-', $name)]) . '</label>';
        $letters = '';
        foreach (LetterPrices::all() as $letter) {
            $letters .= '<option value="' . esc_attr((string) $letter['name']) . '" data-cents="' . esc_attr((string) $letter['price_cents']) . '"></option>';
        }

        $body = '<p>' . esc_html__('Wähle das PDF der Marke aus dem Shop der Post. Es wird nur hier im Browser gelesen, nicht hochgeladen. Was sich lesen lässt, steht danach in den Feldern; prüfe es.', 'taxmod-shipping') . '</p>'
            . '<p><button type="button" class="button" data-taxship-stamp-pick>' . ListPage::icon('pdf', __('PDF wählen', 'taxmod-shipping')) . ' ' . esc_html__('PDF wählen', 'taxmod-shipping') . '</button> '
            . '<span data-taxship-stamp-state data-registered="' . esc_attr__('Einschreiben', 'taxmod-shipping') . '" data-none="' . esc_attr__('Noch kein PDF gewählt.', 'taxmod-shipping') . '" data-read="' . esc_attr__('Gelesen:', 'taxmod-shipping') . '">' . esc_html__('Noch kein PDF gewählt.', 'taxmod-shipping') . '</span></p>'
            . '<datalist id="taxship-stamp-letters">' . $letters . '</datalist>'
            . $line('product', __('Produkt', 'taxmod-shipping'), ['list' => 'taxship-stamp-letters'])
            . '<label style="display:block;margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html__('Preis', 'taxmod-shipping') . '</span>'
            . ListPage::field(InputKind::Decimal, 'price', '', ['form' => $formId, 'size' => '8', 'id' => 'taxship-stamp-price']) . ' €</label>'
            . $line('number', __('Sendungsnummer', 'taxmod-shipping'))
            // D-1510: a receiver, if any, is a contact (D-1497); the stamp's address is shown, never stored (Stefan 20.11., privacy).
            . '<div style="margin:.3em 0"><span style="display:inline-block;width:9em">' . esc_html__('Empfänger (freiwillig)', 'taxmod-shipping') . '</span>'
            . RecordPick::field('receiver_contact', (new Contacts())->pickTree(), null, $formId) . '</div>'
            . '<input type="hidden" name="stamp_pdf" value="" form="' . esc_attr($formId) . '">'
            . '<input type="hidden" name="mail_cart" value="" form="' . esc_attr($formId) . '"><input type="hidden" name="carrier" value="' . esc_attr(LetterRates::CARRIER) . '" form="' . esc_attr($formId) . '">'
            . '<p class="description" data-taxship-stamp-address data-label="' . esc_attr__('Anschrift auf der Marke (nur zur Ansicht, wird nicht gespeichert):', 'taxmod-shipping') . '"></p>';

        return ListPage::dialogAction(self::STAMP_SWITCH, __('Marke im Shop gekauft', 'taxmod-shipping'), __('Gekaufte Marke hochladen', 'taxmod-shipping'), $body, 'action', self::STAMP, __('Eintragen und drucken', 'taxmod-shipping'), self::STAMP_FORM);
    }

    /** The stamp dialog's own form, empty: its fields join it by their form attribute, so it may stand outside any other form. */
    private function stampForm(): string
    {
        return '<form id="' . esc_attr(self::STAMP_FORM) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-taxship-stamp-form>'
            . wp_nonce_field(self::STAMP, '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::STAMP) . '"></form>';
    }

    /** admin-post «Eintragen und drucken» (D-1507): capability → nonce → validate → record. The print happened in the browser. */
    public function stamp(): string
    {
        self::allowed();
        ListPage::nonce(self::STAMP);
        $text = static fn (string $name): string => sanitize_text_field(wp_unslash((string) ($_POST[$name] ?? '')));
        $product = $text('product');
        $price = DecimalText::typed($text('price'));
        $cents = $price === null ? 0 : Money::cents($price);

        if ($product === '' || $cents <= 0) {
            throw new ShippingError(__('Produkt und Preis der Marke fehlen.', 'taxmod-shipping'));
        }

        $settings = new Settings();
        // Stefan 20.11.: the receiver is optional and never made from the stamp — no address is kept unless he picks a contact.
        $contact = absint($_POST['receiver_contact'] ?? 0);
        $name = $contact > 0 ? ((new Contacts())->names()[$contact] ?? '') : '';

        if ($contact > 0 && $name === '') {
            throw new ShippingError(__('Diesen Kontakt gibt es nicht.', 'taxmod-shipping'));
        }

        // D-1511: a label from a mail may be DHL's; anything else is the Post shop's stamp.
        $carrier = $text('carrier');
        $carrier = in_array($carrier, MailLabels::CARRIERS, true) ? $carrier : LetterRates::CARRIER;
        $cart = (string) preg_replace('/[^A-Za-z0-9]/', '', $text('mail_cart'));
        $firm = $settings->carrierFirms()[$carrier] ?? 0;

        if ($firm === 0) {
            throw new ShippingError(sprintf(/* translators: %s: carrier */ __('Unter Einstellungen › Versand fehlt die Firma für %s.', 'taxmod-shipping'), strtoupper($carrier)));
        }

        $missing = ShipmentStore::roles()->missing();

        if ($missing !== []) {
            throw new ShippingError(__('Das Modell kennt «Sendungen» noch nicht (fehlende Rollen):', 'taxmod-shipping') . ' ' . implode(', ', $missing));
        }

        $number = (string) preg_replace('/\s+/', '', $text('number'));
        $rate = new Rate($carrier, 'stamp', $product, $cents, $number !== '', 0);
        $reference = $cart === '' ? LabelOrigin::reference(LabelOrigin::UPLOAD, current_time('YmdHis')) : LabelOrigin::reference(LabelOrigin::MAIL, $cart);
        $purchase = Purchase::bought('', $rate, $name, ['carrier' => $carrier, 'reference' => $reference, 'state' => 'paid', 'numbers' => $number === '' ? [] : [$number]], current_time('mysql'));
        $id = (new ShipmentStore())->add($purchase, null, $contact, $settings->senderContact(), $firm);
        $kept = $this->keep($id, (string) wp_unslash((string) ($_POST['stamp_pdf'] ?? '')));

        if ($cart !== '') {
            // Printed in the browser before this form was sent: the mail module now keeps the label two weeks, not thirty days.
            do_action(self::MAIL_LABEL_PRINTED, $cart);
        }

        return $kept === true ? self::url() : self::url(['taxship_unkept' => rawurlencode($kept)]);
    }

    /**
     * D-1513: the stamp's PDF, sent with the form as base64, sealed into the Etiketten module's store and marked printed (it was,
     * in the browser, before the form left). Answers true, or why it was not kept; the shipment stands either way.
     */
    private function keep(int $id, string $base64): bool|string
    {
        if ($base64 === '' || $id <= 0) {
            return true;
        }

        $pdf = base64_decode($base64, true);
        $answer = $pdf === false ? __('kein PDF', 'taxmod-shipping') : apply_filters(self::POSTAGE_PUT, false, self::postageKey($id), $pdf);

        if ($answer === true) {
            do_action(self::POSTAGE_PRINTED, self::postageKey($id));

            return true;
        }

        return is_string($answer) ? $answer : __('Das Etikettenmodul fehlt.', 'taxmod-shipping');
    }

    private static function postageKey(int $id): string
    {
        return 'taxship-' . $id;
    }

    /** @var list<string>|null */
    private ?array $keptKeys = null;

    /** @return list<string> */
    private function keptKeys(): array
    {
        return $this->keptKeys ??= array_values(array_filter((array) apply_filters(self::POSTAGE_KEYS, []), 'is_string'));
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

        // D-1506: bought in the carrier's shop by the owner; this only records it, nothing is asked of a carrier module.
        $number = sanitize_text_field(wp_unslash((string) ($_POST['number'] ?? '')));
        $answer = $rate->shopUrl !== ''
            ? ['carrier' => $rate->carrier, 'reference' => LabelOrigin::reference(LabelOrigin::SHOP, current_time('YmdHis')), 'state' => 'paid', 'numbers' => $number === '' ? [] : [$number]]
            : apply_filters(Plugin::BUY, null, $rate->toArray(), ['sender' => $sender->toArray(), 'receiver' => $receiver->toArray(), 'email' => $settings->email()]);
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

    /** Only on the owner's click (D-1496): one job to the default printer of his workplace (D-1502). */
    public function printLabel(): string
    {
        $purchase = $this->purchaseFromPost(self::PRINT);
        $number = $this->numberOf($purchase, $_POST);
        // D-1502: printed on the default printer of the person's workplace, as every label (Stefan 20.11.: no printer of the module's own).
        $queue = (new WorkplacePrinters())->queue(0);

        if ($queue === '') {
            throw new ShippingError(__('An deinem Arbeitsplatz steht kein Drucker: unter Einstellungen › Druck › Arbeitsplätze einen zuordnen und ihn unter Benutzereinstellungen wählen.', 'taxmod-shipping'));
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

    /** D-1511: the PDF of a label from a mail, fetched by the mail module (stored links only), to the browser for reading and printing. */
    public function mailPdf(): void
    {
        self::allowed();
        ListPage::nonce(self::MAIL_PDF);
        $link = esc_url_raw(rawurldecode(wp_unslash((string) ($_GET['link'] ?? ''))));
        $pdf = apply_filters(self::MAIL_LABEL_PDF, null, $link);

        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new ShippingError(__('Das PDF dieser Marke ist nicht mehr zu holen.', 'taxmod-shipping'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        print $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
        exit;
    }

    /** D-1513: a kept stamp from the Etiketten module's store to the browser, for printing again. */
    public function keptPdf(): void
    {
        self::allowed();
        $id = absint($_GET['purchase'] ?? 0);
        ListPage::nonce(self::KEPT_PDF . $id);
        $pdf = apply_filters(self::POSTAGE_GET, null, self::postageKey($id));

        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new ShippingError(__('Diese Marke ist nicht mehr gespeichert (14 Tage nach dem Druck gelöscht). Bitte das PDF neu wählen.', 'taxmod-shipping'));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
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
        if (! current_user_can(\Taxmod\WordPress\Rights::SHIP)) {
            ListPage::fail(__('Keine Berechtigung.', 'taxmod-shipping'), self::url());
        }
    }

    /** Without the Etiketten module there is no postage print and no button for it. */
    private static function canPrintPostage(): bool
    {
        return wp_script_is(self::LABEL62, 'registered');
    }

    private static function euro(int $cents): string
    {
        return ListPage::number(Money::decimal($cents)) . ' €';
    }
}
