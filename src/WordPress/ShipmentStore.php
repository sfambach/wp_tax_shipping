<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\Address;
use TaxmodShipping\Core\Parcel;
use TaxmodShipping\Core\Purchase;
use TaxmodShipping\Core\ShipmentChanges;
use TaxmodShipping\Core\ShipmentRoles;
use TaxmodShipping\Core\Shipments;

/**
 * The bought labels as records of «Sendungen» (D-1497, AR-1): written through the modeller's `write-data`, so each
 * purchase is one change number and can be taken back (D-1410); read in four queries whatever their number (CD-7).
 */
final class ShipmentStore
{
    private ?ModelDoor $door = null;

    public static function roles(): ShipmentRoles
    {
        $map = ModelDoor::roles();

        return new ShipmentRoles(static fn (string $group, string $key): ?int => $map->find($group, $key));
    }

    /** @return array<int, Purchase> record id → purchase, newest first */
    public function all(): array
    {
        $r = self::roles();
        $records = $this->door()->records($r->node('shipment'));
        $first = static fn (string $role): array => array_values(array_filter(array_map(static fn (array $v): int => (int) (($v[$r->field($role)] ?? [])[0] ?? 0), $records)));
        $parts = $this->door()->valuesOf($first('shipment_price'));
        $receivers = [];
        foreach ($this->door()->valuesOf($first('shipment_receiver')) as $id => $values) {
            $receivers[(int) $id] = trim((($values[$r->field('contact_first_name')] ?? [])[0] ?? '') . ' ' . (($values[$r->field('firm_name')] ?? [])[0] ?? ''));
        }

        return Shipments::of($r, $records, $parts, $receivers, array_flip((new Settings())->carrierFirms()));
    }

    public function find(int $id): ?Purchase
    {
        return $this->all()[$id] ?? null;
    }

    /** One act: a typed receiver becomes a contact, the purchase a record of «Sendungen». @return int the record id */
    public function add(Purchase $purchase, ?Parcel $parcel, int|Address $receiver, int $sender, int $carrierFirm): int
    {
        $answer = $this->door()->write((new ShipmentChanges(self::roles()))->bought($purchase, $parcel, $receiver, $sender, $carrierFirm));

        return (int) ($answer['refs']['shipment'] ?? 0);
    }

    public function update(int $id, Purchase $purchase): void
    {
        $this->door()->write((new ShipmentChanges(self::roles()))->status($id, $purchase));
    }

    public function remove(int $id): void
    {
        $this->door()->write([['op' => 'remove_record', 'record' => (string) $id]]);
    }

    private function door(): ModelDoor
    {
        return $this->door ??= new ModelDoor();
    }
}
