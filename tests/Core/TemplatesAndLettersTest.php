<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\LetterRates;
use TaxmodShipping\Core\Packagings;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\Rate;
use TaxmodShipping\Core\ShipmentRoles;

final class TemplatesAndLettersTest extends TestCase
{
    private const IDS = ['fields' => ['money_amount' => 1, 'unit_prefix' => 2, 'packaging_dimension' => 10, 'dimension_length' => 30, 'dimension_width' => 31, 'dimension_height' => 32, 'packaging_empty_weight' => 13,
        'packaging_max_weight' => 14, 'letter_name' => 20, 'letter_code' => 21, 'letter_price' => 22, 'letter_max_length' => 23, 'letter_max_width' => 24, 'letter_max_height' => 25,
        'letter_max_weight' => 26, 'letter_shop_url' => 27], 'values' => ['prefix_centi' => 90, 'prefix_kilo' => 91, 'prefix_milli' => 92]];

    public function testScaledBringsUnitValuesToTheFieldsScale(): void
    {
        $r = self::roles();
        self::assertSame(18, Packagings::scaled($r, [1 => ['18'], 2 => ['90']], -2));
        self::assertSame(30, Packagings::scaled($r, [1 => ['0,3']], -2));
        self::assertSame(1000, Packagings::scaled($r, [1 => ['1'], 2 => ['91']], 0));
        self::assertSame(235, Packagings::scaled($r, [1 => ['23.5'], 2 => ['90']], -3));
        self::assertSame(0, Packagings::scaled($r, [1 => ['5'], 2 => ['999']], 0), 'an unknown prefix gives nothing rather than a guess');
    }

    public function testTemplateFillsEdgesAndMaximumLessAGram(): void
    {
        $all = Packagings::of(self::roles(), [7 => [10 => ['99'], 14 => ['104']]],
            [99 => [30 => ['100'], 31 => ['101'], 32 => ['102']], 100 => [1 => ['18'], 2 => ['90']], 101 => [1 => ['12'], 2 => ['90']], 102 => [1 => ['2'], 2 => ['90']], 104 => [1 => ['500']]], [7 => 'A6 Luftpolster']);

        self::assertSame(['weight_g' => '500', 'length_cm' => '18', 'width_cm' => '12', 'height_cm' => '2'], $all[7]->formValues());
    }

    public function testLetterOffersOnlyWhatFitsAndLinksToTheShop(): void
    {
        $records = [5 => [20 => ['Kompaktbrief'], 22 => ['50'], 23 => ['51'], 24 => ['52'], 25 => ['53'], 26 => ['54'], 27 => ['https://post.example/shop']],
            6 => [20 => ['Ohne Shop'], 22 => ['60']]];
        $parts = [50 => [1 => ['1.10']], 51 => [1 => ['23.5'], 2 => ['90']], 52 => [1 => ['12.5'], 2 => ['90']], 53 => [1 => ['1'], 2 => ['90']], 54 => [1 => ['50']], 60 => [1 => ['0.95']]];

        $offers = LetterRates::of(self::roles(), $records, $parts, new Parcel(40, 22, 11, 1));
        self::assertCount(1, $offers);
        self::assertSame(['post', 'letter-5', 110, 'https://post.example/shop'], [$offers[0]['carrier'], $offers[0]['product'], $offers[0]['price_cents'], Rate::fromArray($offers[0])?->shopUrl]);
        self::assertSame([], LetterRates::of(self::roles(), $records, $parts, new Parcel(60, 22, 11, 1)), 'too heavy');
        self::assertSame([], LetterRates::of(self::roles(), $records, $parts, new Parcel(40, 22, 11, 2)), 'too thick');
        self::assertSame('', Rate::fromArray(['carrier' => 'post', 'product' => 'x', 'price_cents' => 1, 'shop_url' => 'http://insecure'])?->shopUrl, 'https only');
    }

    private static function roles(): ShipmentRoles
    {
        return new ShipmentRoles(static fn (string $group, string $key): ?int => self::IDS[$group][$key] ?? null);
    }
}
