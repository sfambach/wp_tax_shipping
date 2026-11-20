<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The roles the node «Sendungen» and the contact branch are named by (D-1394, D-1497). Code asks for a role, never an id.
 *
 * @see docs/konzept.md
 */
final class ShipmentRoles
{
    public const NODES = ['shipment', 'shipment_states', 'contact', 'contact_person'];

    public const FIELDS = [
        'shipment_receiver', 'shipment_sender', 'shipment_carrier', 'shipment_product', 'shipment_price', 'shipment_weight', 'shipment_dimension',
        'dimension_length', 'dimension_width', 'dimension_height', 'shipment_state', 'shipment_number', 'shipment_reference', 'shipment_pay_url', 'shipment_bought_at', 'shipment_sale',
        'money_amount', 'money_unit', 'unit_prefix',
        'firm_name', 'contact_first_name', 'contact_address', 'address_street', 'street_name', 'house_number', 'address_zip_city', 'postal_code', 'city_name',
        'address_country', 'contact_email',
    ];

    public const VALUES = ['shipment_awaiting_payment', 'shipment_paid', 'shipment_sent', 'currency_default', 'unit_gram', 'unit_metre', 'prefix_centi'];

    /** D-1505 templates and D-1506 letters are extras; without these roles the page simply offers none. */
    public const OPTIONAL = [
        'nodes'  => ['packaging', 'letter_product', 'countries'],
        'fields' => ['packaging_dimension', 'packaging_empty_weight', 'packaging_max_weight', 'material_name',
            'letter_name', 'letter_code', 'letter_price', 'letter_max_length', 'letter_max_width', 'letter_max_height', 'letter_max_weight', 'letter_shop_url'],
        'values' => ['prefix_kilo', 'prefix_deci', 'prefix_milli'],
    ];

    /** @param \Closure(string, string): ?int $find group, key → id */
    public function __construct(private readonly \Closure $find)
    {
    }

    public function node(string $key): int
    {
        return $this->get('nodes', $key);
    }

    public function field(string $key): int
    {
        return $this->get('fields', $key);
    }

    public function value(string $key): int
    {
        return $this->get('values', $key);
    }

    /** A role that may be missing: its id, or 0. */
    public function optional(string $key): int
    {
        foreach (self::OPTIONAL as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return ($this->find)($group, $key) ?? 0;
            }
        }

        foreach (['nodes' => self::NODES, 'fields' => self::FIELDS, 'values' => self::VALUES] as $group => $keys) {
            if (in_array($key, $keys, true)) {
                return ($this->find)($group, $key) ?? 0;
            }
        }

        return 0;
    }

    /** @return list<string> «group:key» of every role not set */
    public function missing(): array
    {
        $missing = [];
        foreach (['nodes' => self::NODES, 'fields' => self::FIELDS, 'values' => self::VALUES] as $group => $keys) {
            foreach ($keys as $key) {
                if (($this->find)($group, $key) === null) {
                    $missing[] = $group . ':' . $key;
                }
            }
        }

        return $missing;
    }

    private function get(string $group, string $key): int
    {
        return ($this->find)($group, $key) ?? throw new ShippingError(sprintf('The role %s:%s is not set on this site (Konfiguration › Rollen).', $group, $key));
    }
}
