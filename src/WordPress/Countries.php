<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodShipping\Core\CountryCodes;

/**
 * D-1532 (Stefan 20.11. «Zielland, ja, sollte man eine Auswahlliste machen … Die Länder sollten wir als … Konstantenliste anlegen»):
 * the destination is chosen from the choice list «Länder» with the modeller's node chooser; the carriers get its ISO code.
 * Without the list (role `countries`) or a modeller that reads symbols, the page keeps the code field.
 *
 * @see docs/konzept.md
 */
final class Countries
{
    /** ModelDoor::symbolsOf (D-1534). */
    public const MIN_MODELLER = '0.2.185';

    public const FIELD = 'country_node';

    /** How deep the list goes: «Länder» › continent › country (Stefan 20.11. «nach kontinenten ordnen»). */
    private const DEPTH = 2;

    private ?CountryCodes $codes = null;

    /** @var array<int, array{name: string, parent: int, class: string, hidden: bool}>|null */
    private ?array $tree = null;

    public function root(): int
    {
        if (! method_exists(ModelDoor::class, 'symbolsOf') || version_compare(Modeller::VERSION, self::MIN_MODELLER, '<')) {
            return 0;
        }

        return ShipmentStore::roles()->optional('countries');
    }

    public function codes(): CountryCodes
    {
        if ($this->codes === null) {
            $nodes = array_diff(array_keys($this->tree()), [$this->root()]);
            $this->codes = CountryCodes::of($nodes === [] ? [] : (new ModelDoor())->symbolsOf(array_values($nodes)));
        }

        return $this->codes;
    }

    /** The modeller's chooser over the list, the country of `$code` chosen. */
    public function chooser(string $code): string
    {
        $root = $this->root();
        $now  = $this->codes()->nodeOf($code);

        return (string) apply_filters(Modeller::NODE_CHOOSER_FILTER, '', ['name' => self::FIELD, 'root' => $root, 'unpickable' => $this->groups()] + ($now > 0 ? ['preselected' => $now] : []));
    }

    /** @return list<int> the list itself and every node holding others — a continent is a heading, never a destination */
    private function groups(): array
    {
        return CountryCodes::groups($this->root(), array_map(static fn (array $node): int => $node['parent'], $this->tree()));
    }

    /** @return array<int, array{name: string, parent: int, class: string, hidden: bool}> */
    private function tree(): array
    {
        $root = $this->root();

        return $this->tree ??= $root === 0 ? [] : (new ModelDoor())->tree($root, self::DEPTH);
    }
}
