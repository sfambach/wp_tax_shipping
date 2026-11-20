<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\WordPress\ShippingPage;

/** D-1507: the stamp print is the Etiketten module's script, attached by its handle; without it the button is left out. */
final class StampPrintTest extends TestCase
{
    public function testAttachedByHandleOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $page = (string) file_get_contents($root . '/src/WordPress/ShippingPage.php');

        self::assertSame('taxmod-label62', ShippingPage::LABEL62);
        self::assertStringContainsString("wp_script_is(self::LABEL62, 'registered')", $page);
        self::assertSame(3, substr_count($page, 'self::canPrintPostage()'), 'Upload dialog, enqueue and row button all ask whether the script is there.');
        self::assertDirectoryDoesNotExist($root . '/assets/pdfjs');
        self::assertStringNotContainsString('pdfjs', $page);
    }

    public function testSettingsChooseContactsWithTheModellersChooser(): void
    {
        $settings = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/SettingsPage.php');

        self::assertStringNotContainsString('<select', $settings, 'D-1488: sender and firms use RecordPick::field, not an own select.');
        self::assertSame(1, substr_count($settings, 'RecordPick::field('));
        self::assertStringContainsString('array_unique($taken)', $settings, 'One firm for two carriers is refused.');
    }

    public function testNoPrinterOfItsOwn(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['Settings.php', 'SettingsPage.php'] as $file) {
            self::assertStringNotContainsString("'queue'", (string) file_get_contents($root . '/src/WordPress/' . $file), 'D-1502: the printer is the workplace\'s.');
        }

        self::assertStringContainsString('(new WorkplacePrinters())->queue(', (string) file_get_contents($root . '/src/WordPress/ShippingPage.php'));
        self::assertStringContainsString("apply_filters('taxmod_label_catalog'", (string) file_get_contents($root . '/src/WordPress/WorkplacePrinters.php'));
    }

    public function testStampPriceIsReadAsCents(): void
    {
        self::assertSame(375, \TaxmodShipping\Core\Money::cents('3,75'));
        self::assertSame(110, \TaxmodShipping\Core\Money::cents('1.1'));
        self::assertSame(300, \TaxmodShipping\Core\Money::cents(' 3 € '));
        $this->expectException(\TaxmodShipping\Core\ShippingError::class);
        \TaxmodShipping\Core\Money::cents('drei');
    }

    public function testUploadedStampLeavesTheSizesEmpty(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/ShippingPage.php');
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/stamp-upload.js');

        self::assertStringContainsString('->add($purchase, null,', $page, 'D-1507: no parcel is guessed for an uploaded stamp.');
        self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(|XMLHttpRequest|FormData/', $script, 'The PDF never leaves the browser.');
        self::assertStringContainsString('taxmodLabel62.run(chosen', $script);
    }
}
