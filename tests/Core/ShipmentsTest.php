<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\Purchase;
use TaxmodShipping\Core\Rate;
use TaxmodShipping\Core\ShipmentChanges;
use TaxmodShipping\Core\ShipmentRoles;
use TaxmodShipping\Core\Shipments;
use TaxmodShipping\Core\ShippingError;

final class ShipmentsTest extends TestCase
{
    public function testBoughtWithTypedReceiverCreatesContactAndShipmentInOneList(): void
    {
        $changes = (new ShipmentChanges(self::roles()))->bought(self::purchase(), new Parcel(800, 30, 20, 8), new Address('Max', 'Weg', '5', '53113', 'Bonn', 'DE', 'max@example.org'), 77, 13);

        self::assertSame(['create_record', 'set_values', 'create_record', 'set_values'], array_column($changes, 'op'));
        self::assertSame((string) self::id('nodes', 'contact_person'), $changes[0]['node']);
        $contact = $changes[1]['values'];
        self::assertSame('Max', $contact[(string) self::id('fields', 'firm_name')]);
        self::assertSame('max@example.org', $contact[(string) self::id('fields', 'contact_email')]);
        $address = $contact[(string) self::id('fields', 'contact_address')]['neu'];
        self::assertSame('53113', $address[(string) self::id('fields', 'address_zip_city')]['neu'][(string) self::id('fields', 'postal_code')]);

        $shipment = $changes[3]['values'];
        self::assertSame('@receiver', $shipment[(string) self::id('fields', 'shipment_receiver')]);
        self::assertSame('77', $shipment[(string) self::id('fields', 'shipment_sender')]);
        self::assertSame('13', $shipment[(string) self::id('fields', 'shipment_carrier')]);
        self::assertSame(['neu' => [(string) self::id('fields', 'money_amount') => '4.19', (string) self::id('fields', 'money_unit') => (string) self::id('values', 'currency_default')]], $shipment[(string) self::id('fields', 'shipment_price')]);
        self::assertSame('800', $shipment[(string) self::id('fields', 'shipment_weight')]['neu'][(string) self::id('fields', 'money_amount')]);
        $dimension = $shipment[(string) self::id('fields', 'shipment_dimension')]['neu'];
        self::assertSame((string) self::id('values', 'prefix_centi'), $dimension[(string) self::id('fields', 'dimension_length')]['neu'][(string) self::id('fields', 'unit_prefix')]);
        self::assertSame('8', $dimension[(string) self::id('fields', 'dimension_height')]['neu'][(string) self::id('fields', 'money_amount')]);
        self::assertArrayNotHasKey((string) self::id('fields', 'unit_prefix'), $shipment[(string) self::id('fields', 'shipment_weight')]['neu']);
        self::assertSame((string) self::id('values', 'shipment_awaiting_payment'), $shipment[(string) self::id('fields', 'shipment_state')]);
        self::assertArrayNotHasKey((string) self::id('fields', 'shipment_number'), $shipment);
        self::assertArrayNotHasKey((string) self::id('fields', 'shipment_sale'), $shipment);
    }

    public function testExistingContactIsReferenced(): void
    {
        $changes = (new ShipmentChanges(self::roles()))->bought(self::purchase(), new Parcel(800, 30, 20, 8), 55, 77, 0);

        self::assertCount(2, $changes);
        self::assertSame('55', $changes[1]['values'][(string) self::id('fields', 'shipment_receiver')]);
    }

    public function testWithoutParcelTheSizesStayEmpty(): void
    {
        $values = (new ShipmentChanges(self::roles()))->bought(self::purchase(), null, 55, 77, 0)[1]['values'];

        foreach (['shipment_weight', 'shipment_dimension'] as $role) {
            self::assertArrayNotHasKey((string) self::id('fields', $role), $values);
        }

        self::assertArrayHasKey((string) self::id('fields', 'shipment_price'), $values);
    }

    public function testNoReceiverWritesNone(): void
    {
        $changes = (new ShipmentChanges(self::roles()))->bought(self::purchase(), null, 0, 77, 0);

        self::assertCount(2, $changes);
        self::assertArrayNotHasKey((string) self::id('fields', 'shipment_receiver'), $changes[1]['values']);
    }

    public function testStatusSetsStateAndNumbers(): void
    {
        $paid = self::purchase()->withStatus(['state' => 'paid', 'numbers' => ['0034']]);
        $change = (new ShipmentChanges(self::roles()))->status(900, $paid)[0];

        self::assertSame('900', $change['record']);
        self::assertSame((string) self::id('values', 'shipment_paid'), $change['values'][(string) self::id('fields', 'shipment_state')]);
        self::assertSame(['values' => ['0034']], $change['values'][(string) self::id('fields', 'shipment_number')]);
    }

    public function testRecordsReadBackAsPurchasesNewestFirst(): void
    {
        $f = static fn (string $key): int => self::id('fields', $key);
        $records = [
            900 => [$f('shipment_reference') => ['SC1'], $f('shipment_product') => ['Päckchen S'], $f('shipment_price') => ['950'], $f('shipment_carrier') => ['13'],
                $f('shipment_receiver') => ['55'], $f('shipment_state') => [(string) self::id('values', 'shipment_paid')], $f('shipment_number') => ['0034'], $f('shipment_bought_at') => ['2026-11-20 08:00:00']],
            901 => [$f('shipment_reference') => ['SC2'], $f('shipment_state') => [(string) self::id('values', 'shipment_awaiting_payment')], $f('shipment_bought_at') => ['2026-11-20 09:00:00'],
                $f('shipment_pay_url') => ['https://www.dhl.de/p']],
            902 => [$f('shipment_product') => ['ohne Referenz']],
        ];
        $parts = [950 => [$f('money_amount') => ['4.19']]];

        $purchases = Shipments::of(self::roles(), $records, $parts, [55 => 'Max'], [13 => 'dhl']);

        self::assertSame([901, 900], array_keys($purchases));
        self::assertTrue($purchases[900]->isPaid());
        self::assertSame(['0034'], $purchases[900]->numbers);
        self::assertSame('dhl', $purchases[900]->rate->carrier);
        self::assertSame(419, $purchases[900]->rate->priceCents);
        self::assertSame('Max', $purchases[900]->receiver);
        self::assertFalse($purchases[901]->isPaid());
        self::assertSame('?', $purchases[901]->rate->carrier);
    }

    public function testMissingRoleSaysWhich(): void
    {
        $roles = new ShipmentRoles(static fn (string $g, string $k): ?int => null);

        self::assertContains('nodes:shipment', $roles->missing());
        $this->expectException(ShippingError::class);
        $this->expectExceptionMessage('nodes:shipment');
        $roles->node('shipment');
    }

    private static function purchase(): Purchase
    {
        return Purchase::bought('x', new Rate('dhl', 'PAECK', 'Päckchen S', 419, false, 0), 'Max', ['reference' => 'SC1', 'pay_url' => 'https://www.dhl.de/p'], '2026-11-20 08:00:00');
    }

    private static function id(string $group, string $key): int
    {
        $all = array_merge(ShipmentRoles::NODES, ShipmentRoles::FIELDS, ShipmentRoles::VALUES);

        return 1000 * (['nodes' => 1, 'fields' => 2, 'values' => 3][$group]) + (int) array_search($key, $all, true);
    }

    private static function roles(): ShipmentRoles
    {
        return new ShipmentRoles(static fn (string $group, string $key): ?int => self::id($group, $key));
    }
}
