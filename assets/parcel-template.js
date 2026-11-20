/**
 * A shipping template chosen in «Vorlage» fills weight (its maximum) and the three edges at once (D-1505, Stefan 20.11.). The values
 * come with the field from the server; everything stays editable, and «Tarife vergleichen» keeps what is typed.
 */
(function () {
	'use strict';

	function fill(event) {
		var radio = event.target;

		if (!radio.matches || !radio.matches('input[name="packaging"]') || !radio.checked) {
			return;
		}

		var applied = document.querySelector('[data-taxship-packagings]');
		var all = applied ? JSON.parse(applied.getAttribute('data-taxship-packagings') || '{}') : {};
		var values = all[radio.value];

		if (!values) {
			return;
		}

		Object.keys(values).forEach(function (name) {
			// D-1538: the modeller's controls are found by the name they post under, the page's own fields by id.
			var input = document.getElementById('taxship-' + name) || document.querySelector('[name="' + name.replace(/"/g, '') + '"]');

			if (input && values[name] !== '') {
				input.value = values[name];
			}
		});

		applied.value = radio.value;
	}

	document.addEventListener('change', fill);
	document.addEventListener('taxmod-record-picked', fill);
}());
