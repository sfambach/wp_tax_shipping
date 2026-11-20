<?php declare(strict_types=1);

namespace TaxmodShipping\Core;

/**
 * The contact's «Country» is free text («Deutschland», «Germany», «DE»); carriers want an ISO code.
 * ⚠️ *INFERRED: a short list of the names used; an unknown name passes as it is and the carrier refuses it with a message.*
 *
 * @see docs/konzept.md
 */
final class CountryName
{
    private const NAMES = [
        'deutschland' => 'DE', 'germany' => 'DE', 'österreich' => 'AT', 'austria' => 'AT', 'schweiz' => 'CH', 'switzerland' => 'CH',
        'niederlande' => 'NL', 'netherlands' => 'NL', 'belgien' => 'BE', 'belgium' => 'BE', 'frankreich' => 'FR', 'france' => 'FR',
        'italien' => 'IT', 'italy' => 'IT', 'spanien' => 'ES', 'spain' => 'ES', 'polen' => 'PL', 'poland' => 'PL',
        'dänemark' => 'DK', 'denmark' => 'DK', 'luxemburg' => 'LU', 'luxembourg' => 'LU', 'tschechien' => 'CZ', 'czech republic' => 'CZ',
        'vereinigtes königreich' => 'GB', 'united kingdom' => 'GB', 'usa' => 'US', 'united states' => 'US', 'china' => 'CN',
    ];

    public static function iso(string $country): string
    {
        $key = mb_strtolower(trim($country));

        if ($key === '') {
            return 'DE';
        }

        return self::NAMES[$key] ?? (preg_match('/^[a-z]{2,3}$/', $key) === 1 ? strtoupper($key) : trim($country));
    }
}
