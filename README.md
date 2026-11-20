# Taxmod Versand

Shipping for the [Taxonomy Modeller](https://github.com/sfambach/wp-taxonomy-modeler): enter a parcel, compare the rates
of every connected carrier cheapest first, buy the chosen label on the owner's click, print it on request.

**Status 0.0.10** (needs modeller 0.2.172 and the Lager module's logistics frame): page *Logistik › Warenausgang › Versand*,
settings *Logistik › Einstellungen › Versand* (sender, confirmation e-mail, carrier firms; labels print on the workplace's default printer, D-1502). Carriers: DHL Online
Frankierung via [wp_tax_api_dhl](https://github.com/sfambach/wp_tax_api_dhl) 0.0.22 or later. Concept: [docs/konzept.md](docs/konzept.md).

## Development

`composer install`, then `vendor/bin/phpunit` (core suite, no WordPress).
