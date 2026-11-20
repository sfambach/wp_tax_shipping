<?php declare(strict_types=1);

namespace TaxmodShipping\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodShipping\Core\LabelOrigin;
use TaxmodShipping\Core\LabelRows;
use TaxmodShipping\Core\Purchase;
use TaxmodShipping\Core\Rate;

/** D-1515: one list of labels, its origin read from the reference only, a mail's label never twice. */
final class LabelRowsTest extends TestCase
{
    public function testOriginComesFromTheReference(): void
    {
        self::assertSame(LabelOrigin::MAIL, LabelOrigin::of('mail-7BQ'));
        self::assertSame(LabelOrigin::UPLOAD, LabelOrigin::of('upload-20261120'));
        self::assertSame(LabelOrigin::SHOP, LabelOrigin::of('shop-20261120'));
        self::assertSame(LabelOrigin::CARRIER, LabelOrigin::of('SC1'));
        self::assertSame('mail-7BQ', LabelOrigin::reference(LabelOrigin::MAIL, '7BQ'));
        self::assertSame('upload-1', LabelOrigin::reference(LabelOrigin::UPLOAD, '1'));
    }

    public function testAMailLabelWithItsShipmentIsListedOnceNewestFirst(): void
    {
        $bought = static fn (string $id, string $reference, string $at): Purchase => Purchase::fromArray([
            'id' => $id, 'rate' => (new Rate('post', 'stamp', 'Brief', 95, false, 0))->toArray(), 'reference' => $reference, 'state' => 'paid', 'created_at' => $at,
        ]) ?? throw new \LogicException('no purchase');
        $purchases = [1 => $bought('1', 'mail-C1', '2026-11-20 10:00:00'), 2 => $bought('2', 'upload-x', '2026-11-20 12:00:00')];
        $mail = [
            ['cart' => 'C1', 'date' => '2026-11-20T09:00:00+00:00', 'links' => ['https://a'], 'carrier' => 'post'],
            ['cart' => 'C2', 'date' => '2026-11-20T11:00:00+00:00', 'links' => ['https://b'], 'carrier' => 'dhl'],
        ];

        $rows = LabelRows::of($purchases, $mail);

        self::assertCount(3, $rows);
        self::assertSame(['upload', 'mail', 'mail'], array_column($rows, 'origin'));
        self::assertSame('C2', $rows[1]['mail']['cart'] ?? null, 'The open label sits between the two shipments by its date.');
        self::assertSame('1', $rows[2]['purchase']?->id, 'C1 is listed only as its shipment.');
    }
}
