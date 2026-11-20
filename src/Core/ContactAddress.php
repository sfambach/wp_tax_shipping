<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The postal address of a contact record, read along the model's existing compositions (D-1250): contact → «Address» →
 * «Street / H#» and «Zip/City», plus «Country». Three reads at most, whatever the depth (CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   K["contact: name, first name, address"] --> A["address: street/no., zip/city, country"]
 *   A --> S["street name, house number"]
 *   A --> Z["postal code, city"]
 * ```
 *
 * @see docs/konzept.md
 */
final class ContactAddress
{
    /** The roles this reader needs, each a field id on the site (D-1394). */
    public const ROLES = ['firm_name', 'contact_first_name', 'contact_address', 'address_street', 'street_name', 'house_number', 'address_zip_city', 'postal_code', 'city_name', 'address_country', 'contact_email'];

    /** @param array<string, int> $fields role → field id */
    public function __construct(private readonly array $fields)
    {
    }

    /**
     * @param \Closure(list<int>): array<int, array<int, list<string>>> $values the modeller's values of these records
     * @throws ShippingError when the contact or a part of its address is missing
     */
    public function of(int $contact, \Closure $values): Address
    {
        $own = $values([$contact])[$contact] ?? throw new ShippingError(sprintf('Contact %d does not exist.', $contact));
        $addressId = $this->id($own, 'contact_address');
        $address = $addressId === 0 ? [] : ($values([$addressId])[$addressId] ?? []);
        $street = $this->id($address, 'address_street');
        $zip = $this->id($address, 'address_zip_city');
        $parts = array_filter([$street, $zip]) === [] ? [] : $values(array_values(array_filter([$street, $zip])));

        $name = trim($this->text($own, 'contact_first_name') . ' ' . $this->text($own, 'firm_name'));
        $result = new Address(
            $name,
            $this->text($parts[$street] ?? [], 'street_name'),
            $this->text($parts[$street] ?? [], 'house_number'),
            $this->text($parts[$zip] ?? [], 'postal_code'),
            $this->text($parts[$zip] ?? [], 'city_name'),
            CountryName::iso($this->text($address, 'address_country')),
            $this->text($own, 'contact_email'),
        );

        if (! $result->isComplete()) {
            throw new ShippingError(sprintf('The address of contact %d is incomplete (name, street, postal code, city, country).', $contact));
        }

        return $result;
    }

    /** @param array<int, list<string>> $values */
    private function text(array $values, string $role): string
    {
        return trim((string) (($values[$this->fields[$role] ?? 0] ?? [])[0] ?? ''));
    }

    /** @param array<int, list<string>> $values */
    private function id(array $values, string $role): int
    {
        return (int) $this->text($values, $role);
    }
}
