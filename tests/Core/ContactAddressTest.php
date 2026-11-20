<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\ContactAddress;
use TaxmodShipping\Core\CountryName;
use TaxmodShipping\Core\ShippingError;

final class ContactAddressTest extends TestCase
{
    private const FIELDS = ['firm_name' => 1, 'contact_first_name' => 2, 'contact_address' => 3, 'address_street' => 4, 'street_name' => 5, 'house_number' => 6,
        'address_zip_city' => 7, 'postal_code' => 8, 'city_name' => 9, 'address_country' => 10];

    public function testAddressIsReadAlongTheCompositionsInThreeReads(): void
    {
        $reads = 0;
        $store = [
            100 => [1 => ['Fambach'], 2 => ['Stefan'], 3 => ['200']],
            200 => [4 => ['300'], 7 => ['400'], 10 => ['Deutschland']],
            300 => [5 => ['Testweg'], 6 => ['1a']],
            400 => [8 => ['12345'], 9 => ['Teststadt']],
        ];
        $values = static function (array $ids) use ($store, &$reads): array {
            ++$reads;

            return array_intersect_key($store, array_flip($ids));
        };

        $address = (new ContactAddress(self::FIELDS))->of(100, $values);

        self::assertSame(['name' => 'Stefan Fambach', 'street' => 'Testweg', 'number' => '1a', 'postal_code' => '12345', 'city' => 'Teststadt', 'country' => 'DE', 'email' => '', 'addition' => ''], $address->toArray());
        self::assertSame(3, $reads);
    }

    public function testIncompleteAddressFails(): void
    {
        $this->expectException(ShippingError::class);
        (new ContactAddress(self::FIELDS))->of(100, static fn (array $ids): array => [100 => [1 => ['Firma']]]);
    }

    public function testUnknownContactFails(): void
    {
        $this->expectException(ShippingError::class);
        (new ContactAddress(self::FIELDS))->of(1, static fn (array $ids): array => []);
    }

    public function testCountryNames(): void
    {
        self::assertSame('DE', CountryName::iso(''));
        self::assertSame('AT', CountryName::iso('Österreich'));
        self::assertSame('NL', CountryName::iso('nl'));
        self::assertSame('Atlantis', CountryName::iso('Atlantis'));
    }
}
