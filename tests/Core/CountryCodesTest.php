<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\CountryCodes;

/** D-1532: the destination comes from the list «Länder»; the carriers get the ISO code its symbol holds (D-1534). */
final class CountryCodesTest extends TestCase
{
    public function testCodeOfTheChosenCountry(): void
    {
        $codes = CountryCodes::of([7 => 'DE', 8 => ' at ', 9 => 'Germany', 10 => '']);

        self::assertSame('DE', $codes->codeOf(7));
        self::assertSame('AT', $codes->codeOf(8));
        self::assertSame('', $codes->codeOf(9), 'A name is no code; nothing is guessed from it.');
        self::assertSame('', $codes->codeOf(99));
    }

    public function testNodeOfACode(): void
    {
        $codes = CountryCodes::of([7 => 'DE', 8 => 'AT']);

        self::assertSame(8, $codes->nodeOf('at'));
        self::assertSame(0, $codes->nodeOf('NL'));
    }

    public function testUnderContinentsACountryStaysValidAndAContinentIsNoDestination(): void
    {
        $parents = [100 => 1, 20 => 100, 21 => 100, 7 => 20, 8 => 20, 9 => 21];
        $codes   = CountryCodes::of([7 => 'DE', 8 => 'AT', 9 => 'JP']);

        self::assertSame('DE', $codes->codeOf(7), 'A country moved under its continent keeps its node and code.');
        self::assertSame(9, $codes->nodeOf('JP'));
        self::assertSame([100, 20, 21], CountryCodes::groups(100, $parents), 'The list and the continents are not pickable; its own parent is outside.');
        self::assertNotContains(7, CountryCodes::groups(100, $parents));
    }

    public function testPageChoosesWithTheModellersChooser(): void
    {
        $root  = dirname(__DIR__, 2);
        $class = (string) file_get_contents($root . '/src/WordPress/Countries.php');

        self::assertStringContainsString('Modeller::NODE_CHOOSER_FILTER', $class);
        self::assertStringContainsString('symbolsOf(', $class);
        self::assertStringContainsString('->tree($root, self::DEPTH)', $class, 'Countries are read below the continents too (D-1537).');
        self::assertStringContainsString('CountryCodes::groups(', $class);
        self::assertStringContainsString("version_compare(Modeller::VERSION, self::MIN_MODELLER, '<')", $class, 'An older modeller keeps the code field.');
        self::assertStringContainsString("'countries'", (string) file_get_contents($root . '/src/Core/ShipmentRoles.php'));
    }
}
