/**
 * A shipping template chosen in «Vorlage» fills weight (max − 1 g) and the three edges at once (D-1505, Stefan 20.11.). The values
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
			var input = document.getElementById('taxship-' + name);

			if (input && values[name] !== '') {
				input.value = values[name];
			}
		});

		applied.value = radio.value;
	}

	document.addEventListener('change', fill);
	document.addEventListener('taxmod-record-picked', fill);
}());
