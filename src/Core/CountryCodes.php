<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * D-1532: the countries of the choice list «Länder» by their ISO code, as the modeller keeps it — each country a node whose
 * symbol is its ISO 3166 code (D-1534). Only what looks like a code counts; nothing is guessed from a name.
 *
 * @see docs/konzept.md
 */
final class CountryCodes
{
    /** @param array<int, string> $codes node id ⇒ symbol */
    private function __construct(private readonly array $codes)
    {
    }

    /** @param array<int, string> $symbols node id ⇒ symbol, as ModelDoor::symbolsOf answers */
    public static function of(array $symbols): self
    {
        $codes = [];
        foreach ($symbols as $node => $symbol) {
            $code = strtoupper(trim($symbol));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1) {
                $codes[(int) $node] = $code;
            }
        }

        return new self($codes);
    }

    /** The code of the chosen country; '' where the node is none of the list. */
    public function codeOf(int $node): string
    {
        return $this->codes[$node] ?? '';
    }

    /** The node of a code; 0 where the list has none. */
    public function nodeOf(string $code): int
    {
        return (int) (array_search(strtoupper(trim($code)), $this->codes, true) ?: 0);
    }
}
