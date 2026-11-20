<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\Money;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\Purchase;
use TaxmodShipping\Core\Rate;
use TaxmodShipping\Core\RateComparison;
use TaxmodShipping\Core\ShippingError;

final class ShippingCoreTest extends TestCase
{
    public function testOffersComeCheapestFirstAndFailuresApart(): void
    {
        $comparison = RateComparison::of([
            ['carrier' => 'dhl', 'product' => 'PAK02', 'name' => 'Paket 2 kg', 'price_cents' => 549, 'tracked' => true],
            ['carrier' => 'dhl', 'product' => 'PAECK', 'name' => 'Päckchen S', 'price_cents' => 419, 'tracked' => false],
            ['carrier' => 'gls', 'error' => 'no key'],
            ['carrier' => 'x', 'product' => '', 'price_cents' => 1],
            'junk',
        ]);

        self::assertSame(['dhl:PAECK', 'dhl:PAK02'], array_map(static fn (Rate $r): string => $r->key(), $comparison->offers));
        self::assertSame('PAECK', $comparison->cheapest()?->product);
        self::assertSame(['gls' => 'no key'], $comparison->failures);
        self::assertSame(549, $comparison->find('dhl', 'PAK02')?->priceCents);
        self::assertNull($comparison->find('dhl', 'NOPE'));
    }

    public function testTrackedOfferWinsAtTheSamePrice(): void
    {
        $comparison = RateComparison::of([
            ['carrier' => 'a', 'product' => 'p', 'price_cents' => 400, 'tracked' => false],
            ['carrier' => 'b', 'product' => 'p', 'price_cents' => 400, 'tracked' => true],
        ]);

        self::assertSame('b', $comparison->cheapest()?->carrier);
    }

    public function testParcelNeedsWeightAndEdges(): void
    {
        self::assertSame(['weight_g' => 800, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 8, 'country' => 'DE'], (new Parcel(800, 30, 20, 8))->toArray());
        $this->expectException(ShippingError::class);
        new Parcel(0, 30, 20, 8);
    }

    public function testPurchaseFromBuyAnswerThenPaid(): void
    {
        $rate = new Rate('dhl', 'PAK02', 'Paket 2 kg', 549, true, 500);
        $purchase = Purchase::bought('p1', $rate, 'Max', ['reference' => 'SC9', 'state' => 'awaiting_payment', 'pay_url' => 'https://www.dhl.de/p'], '2026-11-20 08:00');

        self::assertFalse($purchase->isPaid());
        self::assertSame('https://www.dhl.de/p', $purchase->payUrl);

        $paid = $purchase->withStatus(['state' => 'paid', 'numbers' => ['0034 0434']]);
        self::assertTrue($paid->isPaid());
        self::assertSame(['00340434'], $paid->numbers);
        self::assertSame('https://www.dhl.de/p', $paid->payUrl);
        self::assertEquals($paid, Purchase::fromArray($paid->toArray()));
    }

    public function testBuyWithoutAnswerOrWithRefusalFails(): void
    {
        $rate = new Rate('dhl', 'PAK02', 'Paket 2 kg', 549, true, 0);

        try {
            Purchase::bought('p', $rate, '', null, '');
            self::fail('no answer must fail');
        } catch (ShippingError $e) {
            self::assertStringContainsString('dhl', $e->getMessage());
        }

        $this->expectExceptionMessage('address too long');
        Purchase::bought('p', $rate, '', ['error' => 'address too long'], '');
    }

    public function testPayUrlMustBeHttps(): void
    {
        $purchase = Purchase::bought('p', new Rate('dhl', 'P', 'P', 1, true, 0), '', ['reference' => 'R', 'pay_url' => 'javascript:alert(1)'], '');

        self::assertSame('', $purchase->payUrl);
    }

    public function testAddressCompleteness(): void
    {
        self::assertTrue(Address::fromArray(['name' => 'Max', 'street' => 'Weg', 'number' => '1', 'postal_code' => '12345', 'city' => 'Ort', 'country' => 'de'])->isComplete());
        self::assertFalse(Address::fromArray(['name' => 'Max'])->isComplete());
        self::assertSame('DE', Address::fromArray([])->country);
    }

    public function testMoneyAsStoredDecimal(): void
    {
        self::assertSame('5.49', Money::decimal(549));
        self::assertSame('0.05', Money::decimal(5));
    }
}
