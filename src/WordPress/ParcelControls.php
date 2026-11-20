<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin as Modeller;
use TaxmodShipping\Core\Packaging;
use TaxmodShipping\Core\ParcelInput;

/**
 * D-1538 (Stefan 20.11. «Abmessung und Gewicht als Einheitswert»): weight and «Abmessung» in the parcel form are the
 * modeller's own controls of «Sendungen» (D-1250), read back with its `typed()`. A modeller before the part round trip keeps
 * the page's number fields.
 *
 * @see docs/konzept.md
 */
final class ParcelControls
{
    /** typed() and controls() carry parts both ways, two levels deep (Modeller 0.2.188–0.2.190), drawn compact from 0.2.191. */
    public const MIN_MODELLER = '0.2.191';

    public const PREFIX = 'taxship_parcel';

    public function available(): bool
    {
        return method_exists(ModelDoor::class, 'typed') && version_compare(Modeller::VERSION, self::MIN_MODELLER, '>=')
            && ShipmentStore::roles()->optional('shipment_dimension') > 0;
    }

    /**
     * What was typed, in the part form.
     *
     * @param array<string, mixed> $source $_GET or $_POST
     * @return array<int, mixed>
     */
    public function typed(array $source): array
    {
        $posted = $source[self::PREFIX] ?? null;

        return is_array($posted) ? (new ModelDoor())->typed(ShipmentStore::roles()->node('shipment'), wp_unslash($posted)) : [];
    }

    /**
     * The two controls, filled with these parts.
     *
     * @param array<int, mixed> $parts
     * @return array{weight: string, dimension: string} control markup, escaped by the modeller
     */
    public function controls(array $parts): array
    {
        $r = ShipmentStore::roles();
        $weight = $r->field('shipment_weight');
        $dimension = $r->field('shipment_dimension');
        $controls = (new ModelDoor())->controls($r->node('shipment'), [$weight, $dimension], self::PREFIX, '', 0, $parts);

        return ['weight' => $controls[$weight] ?? '', 'dimension' => $controls[$dimension] ?? ''];
    }

    /** @return array<int, mixed> a template's weight and edges as the controls take them */
    public function partsOf(Packaging $template): array
    {
        return ParcelInput::parts(ShipmentStore::roles(), $template->startWeight(), $template->lengthCm, $template->widthCm, $template->heightCm);
    }

    /** @return array<string, string> the same as the names the browser fills */
    public function namesOf(Packaging $template): array
    {
        return ParcelInput::names(self::PREFIX, $this->partsOf($template));
    }
}
