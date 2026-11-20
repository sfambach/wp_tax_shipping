<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\ParcelInput;
use TaxmodShipping\Core\ShipmentRoles;
use TaxmodShipping\Core\ShippingError;

/** D-1538: weight and «Abmessung» come from the modeller's controls; the carriers get grams and centimetres. */
final class ParcelInputTest extends TestCase
{
    private const IDS = ['fields' => ['money_amount' => 1, 'unit_prefix' => 2, 'money_unit' => 3, 'shipment_weight' => 10, 'shipment_dimension' => 11,
        'dimension_length' => 20, 'dimension_width' => 21, 'dimension_height' => 22], 'values' => ['unit_gram' => 50, 'unit_metre' => 51, 'prefix_centi' => 90, 'prefix_kilo' => 91]];

    public function testTypedPartsBecomeGramsAndCentimetres(): void
    {
        $typed = [10 => ['0' => [1 => '1,2', 2 => '91', 3 => '50']], 11 => ['0' => [
            20 => ['0' => [1 => '22', 2 => '90', 3 => '51']], 21 => ['0' => [1 => '15', 2 => '90']], 22 => ['neu' => [1 => '0.5', 2 => '90']],
        ]]];

        $parcel = ParcelInput::of(self::roles(), $typed, 'AT');

        self::assertSame(['weight_g' => 1200, 'length_cm' => 22, 'width_cm' => 15, 'height_cm' => 1, 'country' => 'AT'], $parcel?->toArray());
    }

    public function testATemplateRoundTripsThroughThePartForm(): void
    {
        $parts = ParcelInput::parts(self::roles(), 500, 22, 15, 1);

        self::assertSame(['weight_g' => 500, 'length_cm' => 22, 'width_cm' => 15, 'height_cm' => 1, 'country' => 'DE'], ParcelInput::of(self::roles(), $parts, 'DE')?->toArray());
        self::assertSame('22', ParcelInput::names('tp', $parts)['tp[11][0][20][0][1]']);
        self::assertSame('50', ParcelInput::names('tp', $parts)['tp[10][0][3]'], 'The unit goes with the weight.');
        self::assertSame('', ParcelInput::names('tp', $parts)['tp[10][0][2]'], 'No prefix is said as none, so an earlier «kg» is cleared.');
    }

    public function testNothingTypedIsNoParcel(): void
    {
        self::assertNull(ParcelInput::of(self::roles(), [], 'DE'));
    }

    public function testAMissingEdgeIsRefused(): void
    {
        $this->expectException(ShippingError::class);
        ParcelInput::of(self::roles(), [10 => ['0' => [1 => '500']], 11 => ['0' => [20 => ['0' => [1 => '22', 2 => '90']]]]], 'DE');
    }

    public function testAUnitThatDoesNotFitIsRefused(): void
    {
        $this->expectException(ShippingError::class);
        ParcelInput::of(self::roles(), [10 => ['0' => [1 => '500', 3 => '51']]], 'DE');
    }

    private static function roles(): ShipmentRoles
    {
        return new ShipmentRoles(static fn (string $group, string $key): ?int => self::IDS[$group][$key] ?? null);
    }
}
