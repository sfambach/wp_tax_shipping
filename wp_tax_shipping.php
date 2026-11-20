<?php declare(strict_types=1);

/**
 * Plugin Name:       Taxmod Versand
 * Description:       Shipping for the Taxonomy Modeller: compares the rates of every connected carrier, buys the chosen label on the owner's click and prints it on request.
 * Version:           0.0.16
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Stefan Fambach (SteFam), Claude (Anthropic AI)
 * Author URI:        https://www.fambach.net
 * License:           PolyForm Noncommercial 1.0.0
 * License URI:       https://polyformproject.org/licenses/noncommercial/1.0.0/
 * Text Domain:       taxmod-shipping
 * GitHub Plugin URI: sfambach/wp_tax_shipping
 * Primary Branch:    main
 */

if (! defined('ABSPATH')) {
    exit;
}

$taxmodShippingAutoloader = __DIR__ . '/vendor/autoload.php';

if (! is_readable($taxmodShippingAutoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Taxmod Versand:</strong> '
            . esc_html__('run "composer install" in the plugin folder — the autoloader is missing.', 'taxmod-shipping')
            . '</p></div>';
    });

    return;
}

require_once $taxmodShippingAutoloader;

TaxmodShipping\WordPress\Plugin::boot();
