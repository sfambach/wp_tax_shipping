<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\Admin\ListPage;

/**
 * The boundary: the pages «Warenausgang › Versand» and «Einstellungen › Versand» inside the logistics frame (D-1496, no
 * menu item of its own) and their admin-post acts. It wires WordPress to the core and decides nothing.
 *
 * ```mermaid
 * flowchart LR
 *   S["Versand page"] -->|apply_filters taxmod_shipping_rates| C["carrier modules (DHL …)"]
 *   S -->|click «Kaufen»| B["taxmod_shipping_buy"] --> C
 *   S -->|click «Status»| T["taxmod_shipping_status"] --> C
 *   S -->|click «Drucken»| L["taxmod_shipping_label"] --> C
 *   L --> Q["ModelDoor::queuePrint"]
 * ```
 *
 * @see docs/konzept.md
 */
final class Plugin
{
    public const VERSION      = '0.0.1';
    public const MIN_MODELLER = '0.2.133';
    public const SLUG         = 'taxmod-versand';
    public const SETTINGS     = 'taxmod-versand-einstellungen';

    public const RATES  = 'taxmod_shipping_rates';
    public const BUY    = 'taxmod_shipping_buy';
    public const STATUS = 'taxmod_shipping_status';
    public const LABEL  = 'taxmod_shipping_label';

    public static function boot(): void
    {
        add_action('plugins_loaded', static function (): void {
            if (! self::modellerFits()) {
                add_action('admin_notices', static function (): void {
                    print '<div class="notice notice-warning"><p><strong>Taxmod Versand:</strong> ' . esc_html(sprintf(
                        /* translators: %s: version */
                        __('needs the Taxonomy Modeller %s or later.', 'taxmod-shipping'),
                        self::MIN_MODELLER
                    )) . '</p></div>';
                });

                return;
            }

            add_filter('taxmod_logistics_pages', self::pages(...));

            if (\Taxmod\WordPress\Plugin::handlesAdminActions()) {
                $page = new ShippingPage();
                foreach (ShippingPage::ACTS as $act => $method) {
                    add_action('admin_post_' . $act, ListPage::acting([$page, $method], ShippingPage::url()));
                }
                add_action('admin_post_' . SettingsPage::SAVE, ListPage::acting([new SettingsPage(), 'save'], SettingsPage::url()));
            }
        }, 20);
    }

    private static function modellerFits(): bool
    {
        return class_exists(\Taxmod\WordPress\Plugin::class) && version_compare(\Taxmod\WordPress\Plugin::VERSION, self::MIN_MODELLER, '>=');
    }

    /**
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    public static function pages(array $pages): array
    {
        $pages[] = ['slug' => self::SLUG, 'label' => __('Versand', 'taxmod-shipping'), 'area' => 'outbound', 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY,
            'render' => static fn (): string => (new ShippingPage())->render()];
        $pages[] = ['slug' => self::SETTINGS, 'label' => __('Versand', 'taxmod-shipping'), 'area' => 'settings', 'capability' => \Taxmod\WordPress\Plugin::CAPABILITY,
            'render' => static fn (): string => (new SettingsPage())->render()];

        return $pages;
    }

    public static function moduleUrl(string $slug, array $query = []): string
    {
        return add_query_arg(['page' => 'taxmod-lager-modul', 'module' => $slug] + $query, admin_url('admin.php'));
    }
}
