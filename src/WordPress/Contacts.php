<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\ContactAddress;
use TaxmodShipping\Core\ShipmentRoles;
use TaxmodShipping\Core\ShippingError;

/**
 * Contacts as sender and receiver (D-1497): the existing contact branch and its address compositions, named by roles
 * (D-1394), never by id in the code that reads them. The ids below are this site's, handed to the shared role table
 * once per release ({@see ModelDoor::adoptRoles()}); a role already set elsewhere wins.
 */
final class Contacts
{
    public const ROLE_MODULE = 'taxmod-shipping';

    /** Read live 2026-11-20 (Prüftor): «Kontact» 3083, «Address» 56070 → 66335, «Street / H#» 76120 → 75473, «Zip/City» 76121 → 75477. */
    private const SITE_FIELDS = ['contact_address' => 56070, 'address_street' => 76120, 'street_name' => 75475, 'house_number' => 75476,
        'address_zip_city' => 76121, 'postal_code' => 75479, 'city_name' => 75480, 'address_country' => 66341];

    private const SITE_NODES = ['contact_person' => 14];

    private const ADOPTED = 'taxmod_shipping_roles_adopted';

    /**
     * The ids of «Sendungen», its states, the units and «E-Mail» at the contact (D-1497), written by whoever creates them in
     * the model ({nodes, fields, values}); taken into the shared role table and then dropped, like the sales module's.
     */
    public const STRUCTURE = 'taxmod_shipping_structure';

    public static function register(): void
    {
        add_filter('taxmod_roles', static function (mixed $roles): array {
            $roles = is_array($roles) ? $roles : [];
            foreach (['nodes' => ShipmentRoles::NODES, 'fields' => ShipmentRoles::FIELDS, 'values' => ShipmentRoles::VALUES] as $group => $keys) {
                foreach ($keys as $key) {
                    $roles[] = ['module' => self::ROLE_MODULE, 'group' => $group, 'key' => $key, 'required' => $key !== 'contact_email'];
                }
            }

            return $roles;
        });
        add_action('admin_init', static function (): void {
            if (! current_user_can(\Taxmod\WordPress\Plugin::CAPABILITY)) {
                return;
            }

            if (get_option(self::ADOPTED) !== Plugin::VERSION) {
                ModelDoor::adoptRoles(['nodes' => self::SITE_NODES, 'fields' => self::SITE_FIELDS]);
                update_option(self::ADOPTED, Plugin::VERSION, false);
            }

            $structure = get_option(self::STRUCTURE);
            if (is_array($structure)) {
                $conflicts = ModelDoor::adoptRoles($structure);
                if ($conflicts === []) {
                    delete_option(self::STRUCTURE);
                } else {
                    error_log('Taxmod Versand: roles set differently in the modeller: ' . wp_json_encode($conflicts));
                }
            }
        });
    }

    /** @throws ShippingError */
    public function address(int $contact): Address
    {
        $door = new ModelDoor();

        return (new ContactAddress($this->fields()))->of($contact, static fn (array $ids): array => $door->valuesOf($ids));
    }

    /**
     * Every contact by name, for a choice list: one read for the records, one for their names (CD-7).
     *
     * @return array<int, string> record id → «first name name»
     */
    public function names(): array
    {
        $roles = ModelDoor::roles();
        $root = $roles->find('nodes', 'contact') ?? 0;

        if ($root === 0) {
            return [];
        }

        $door = new ModelDoor();
        $records = array_keys($door->recordsOf(array_keys($door->subtree($root)['steps']), 5000));
        $fields = $this->fields();
        $names = [];

        foreach ($records === [] ? [] : $door->valuesOf($records) as $id => $values) {
            $name = trim(($values[$fields['contact_first_name'] ?? 0][0] ?? '') . ' ' . ($values[$fields['firm_name'] ?? 0][0] ?? ''));
            if ($name !== '') {
                $names[(int) $id] = $name;
            }
        }

        asort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    /** @return array<string, int> */
    private function fields(): array
    {
        $roles = ModelDoor::roles();
        $fields = [];
        foreach (ContactAddress::ROLES as $key) {
            $fields[$key] = $roles->find('fields', $key) ?? 0;
        }

        return $fields;
    }
}
