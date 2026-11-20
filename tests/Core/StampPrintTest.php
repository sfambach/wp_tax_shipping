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
        self::assertSame(6, substr_count($page, 'self::canPrintPostage()'), 'Front and full list (upload dialog, mail labels), enqueue, kept-stamp and row button all ask whether the script is there.');
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
        // D-1511: the one fetch brings a PDF from this site into the browser (a mail's label, D-1513 a kept stamp).
        // D-1513: the stamp leaves the browser only with the form, as base64 in one hidden field, after it was printed.
        self::assertDoesNotMatchRegularExpression('/XMLHttpRequest|FormData|method:\s*.POST/', $script, 'The PDF never leaves the browser.');
        self::assertSame(1, preg_match_all('/\bfetch\s*\(/', $script));
        self::assertStringContainsString("pdfFrom(button.getAttribute('data-taxship-mail-pdf'))", $script);
        self::assertStringContainsString('taxmodLabel62.run(chosen', $script);
        self::assertStringContainsString("field('stamp_pdf').value", $script);
        self::assertStringContainsString("apply_filters(self::POSTAGE_PUT, false, self::postageKey(\$id), \$pdf)", $page);
        self::assertStringNotContainsString('file_put_contents', $page, 'D-1513: the one store of postage PDFs is the Etiketten module\'s.');

        // D-1511: choosing and reading the PDF is the label module's building block; this script only fills fields from its lines.
        self::assertStringContainsString('taxmodLabel62.lines(', $script);
        self::assertStringContainsString('taxmodLabel62.pick()', $script);
        self::assertDoesNotMatchRegularExpression('/getTextContent|pdfjsLib|getDocument/', $script);

        // D-1510: the receiver is a contact from the chooser; the stamp's address is only a hint, never typed fields.
        $dialog = substr($page, (int) strpos($page, 'private function stampDialog'), (int) strpos($page, 'public function stamp()') - (int) strpos($page, 'private function stampDialog'));
        self::assertStringNotContainsString("'receiver[", $dialog);
        self::assertStringContainsString("RecordPick::field('receiver_contact'", $dialog);
        self::assertStringContainsString('InputKind::Decimal', $dialog);
        self::assertStringContainsString('->add($purchase, null, $contact,', $page);
        self::assertStringContainsString("Empfänger (freiwillig)", $dialog, 'Stefan 20.11.: no receiver is forced and none is stored from the stamp.');
        self::assertStringNotContainsString("put('receiver", $script);
    }
}
