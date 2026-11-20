<?php declare(strict_types=1);

namespace TaxmodShipping\WordPress;

/**
 * The printers of the person's workplace (D-1502), asked of the Etiketten module's catalog: the default printer first. The module
 * keeps no printer of its own; without the Etiketten module or a printer at the workplace there is none.
 *
 * @see docs/konzept.md
 */
final class WorkplacePrinters
{
    /** @var array<int, array{name: string, queue: string}>|null */
    private ?array $printers = null;

    /** @return array<int, array{name: string, queue: string}> printer record id ⇒ name and queue, the default first */
    public function all(): array
    {
        if ($this->printers !== null) {
            return $this->printers;
        }

        $catalog = apply_filters('taxmod_label_catalog', null);
        $this->printers = [];

        foreach (is_object($catalog) && method_exists($catalog, 'printerList') ? $catalog->printerList() : [] as $id => $printer) {
            $queue = (string) ($printer['queue'] ?? '');
            if ($queue !== '') {
                $name = (string) ($printer['name'] ?? '');
                $this->printers[(int) $id] = ['name' => $name !== '' ? $name : $queue, 'queue' => $queue];
            }
        }

        return $this->printers;
    }

    /** The queue of the chosen printer, if it stands at the workplace; else the default printer's; '' when there is none. */
    public function queue(int $chosen): string
    {
        $all = $this->all();

        return $all[$chosen]['queue'] ?? (reset($all)['queue'] ?? '');
    }
}
