<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\MailLabels;

final class MailLabelsTest extends TestCase
{
    public function testListsEachLinkOfALabelNoShipmentHolds(): void
    {
        $labels = [
            ['cart' => 'C1', 'date' => '2026-11-20T10:00:00+01:00', 'links' => ['https://a/1', 'https://a/2'], 'carrier' => 'dhl'],
            ['cart' => 'C2', 'date' => '2026-11-19', 'links' => ['https://b'], 'carrier' => 'post'],
            ['cart' => 'C3', 'date' => '2026-11-18', 'links' => ['https://c']],
            ['cart' => 'C4', 'date' => '2026-11-18', 'links' => ['https://d'], 'carrier' => 'ups'],
            'no label',
        ];

        $open = MailLabels::open($labels, ['mail-C2']);

        self::assertSame(['https://a/1', 'https://a/2', 'https://c'], array_column($open, 'link'));
        self::assertSame('dhl', $open[2]['carrier'], 'A label from before D-1511 has no carrier and is DHL\'s.');
        self::assertSame([], MailLabels::open(null, []));
    }

    public function testTheCartComesBackFromTheReference(): void
    {
        self::assertSame('C1', MailLabels::cartOf('mail-C1'));
        self::assertSame('', MailLabels::cartOf('shop-20261120'));
    }
}
