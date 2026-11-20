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

    private ?CountryCodes $codes = null;

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
            $root = $this->root();
            $door = new ModelDoor();
            $this->codes = CountryCodes::of($root === 0 ? [] : $door->symbolsOf(array_keys($door->children($root))));
        }

        return $this->codes;
    }

    /** The modeller's chooser over the list, the country of `$code` chosen. */
    public function chooser(string $code): string
    {
        $root = $this->root();
        $now  = $this->codes()->nodeOf($code);

        return (string) apply_filters(Modeller::NODE_CHOOSER_FILTER, '', ['name' => self::FIELD, 'root' => $root, 'unpickable' => [$root]] + ($now > 0 ? ['preselected' => $now] : []));
    }
}
