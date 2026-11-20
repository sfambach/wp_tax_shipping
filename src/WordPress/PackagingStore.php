<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use TaxmodShipping\Core\Packaging;
use TaxmodShipping\Core\Packagings;

/**
 * The shipping templates (D-1505): the materials under the group «Versandverpackungen», named by a role. Read in five
 * queries whatever their number (CD-7); without the role there are none.
 */
final class PackagingStore
{
    /** @var array<int, array{name: string, parent: int, class: string, hidden: bool}>|null */
    private ?array $tree = null;

    /** @var array<int, int>|null record id → node */
    private ?array $records = null;

    /** @var array<int, Packaging>|null */
    private ?array $all = null;

    private ?ModelDoor $door = null;

    public function root(): int
    {
        return ShipmentStore::roles()->optional('packaging');
    }

    /** @return array<int, Packaging> material id → template, by name */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $r = ShipmentStore::roles();
        $records = array_keys($this->records());
        $values = $records === [] ? [] : $this->door()->valuesOf($records);
        $partIds = [];
        foreach ($values as $fields) {
            foreach (['packaging_length', 'packaging_width', 'packaging_height', 'packaging_empty_weight', 'packaging_max_weight'] as $role) {
                $partIds[] = (int) (($fields[$r->optional($role)] ?? [])[0] ?? 0);
            }
        }
        $partIds = array_values(array_unique(array_filter($partIds)));
        $name = $r->optional('material_name');
        $words = $name === 0 ? [] : ($this->door()->fieldWords(array_keys($values), [$name])[$name] ?? []);

        return $this->all = Packagings::of($r, $values, $partIds === [] ? [] : $this->door()->valuesOf($partIds), $words);
    }

    public function find(int $id): ?Packaging
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * The group and its subgroups with their templates, as the modeller's chooser draws them (D-1182): «New here …» in
     * each group adds a template right there.
     *
     * @return list<array{depth: int, name: string, records: array<int, string>, node: int}>
     */
    public function pickTree(): array
    {
        $tree = $this->tree();
        $records = [];
        foreach ($this->records() as $record => $node) {
            $records[$node][$record] = $this->all()[$record]->name ?? ('#' . $record);
        }

        $depth = [];
        $rows = [];
        foreach ($tree as $node => $row) {
            $depth[$node] = isset($depth[$row['parent']]) ? $depth[$row['parent']] + 1 : 0;
            $rows[] = ['depth' => $depth[$node], 'name' => $row['name'], 'records' => $records[$node] ?? [], 'node' => $node];
        }

        return $rows;
    }

    /** @return array<int, array{name: string, parent: int, class: string, hidden: bool}> */
    private function tree(): array
    {
        return $this->tree ??= $this->root() === 0 ? [] : $this->door()->tree($this->root());
    }

    /** @return array<int, int> */
    private function records(): array
    {
        return $this->records ??= $this->tree() === [] ? [] : $this->door()->recordsOf(array_keys($this->tree()), 500);
    }

    private function door(): ModelDoor
    {
        return $this->door ??= new ModelDoor();
    }
}
