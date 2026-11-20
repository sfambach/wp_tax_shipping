/**
 * D-1507: «Gekaufte Marke hochladen». The Etiketten module's taxmod-label62 chooses the PDF and reads its text (D-1511: one building
 * block, taxmodLabel62.pick and .lines); this script only fills the dialog with what those lines say — product, price, shipment number, and the receiver's address as a hint — and on «Eintragen und drucken» prints
 * it on 62 mm before the form records it. Nothing that is not found is guessed; the PDF never leaves the browser.
 */
(function () {
	'use strict';

	var chosen = null;

	function field(name) {
		return document.querySelector('[form="taxship-stamp-form"][name="' + name + '"]');
	}

	function put(name, value) {
		var input = field(name);

		if (input && value) {
			input.value = value;
		}
	}

	// The Internetmarke as bought on 20.11.2026: sender line, «EINSCHREIBEN», number, «IM <date> <price>», then the receiver block.
	function read(rows) {
		var found = {};
		var priceAt = -1;

		rows.forEach(function (text, i) {
			var price = text.match(/\b\d{2}\.\d{2}\.\d{2}\s+(\d{1,3},\d{2})\b/);
			var number = text.match(/\b(A0(?:\s?[0-9A-F]{2,4}){4,})\b/);

			if (price && priceAt < 0) {
				found.price = price[1];
				priceAt = i;
			}

			if (number && !found.number) {
				found.number = number[1].replace(/\s+/g, '');
			}

			if (/EINSCHREIBEN/i.test(text)) {
				found.registered = true;
			}
		});

		var block = priceAt < 0 ? [] : rows.slice(priceAt + 1).filter(function (text) {
			return !/Deutsche Post|^IM\b|^R$/.test(text);
		});
		var last = block.length - 1;
		var city = last >= 0 ? block[last].match(/^(\d{5})\s+(.+)$/) : null;

		if (city) {
			found.postal_code = city[1];
			found.city = city[2];
			var street = last >= 1 ? block[last - 1].match(/^(.*\D)\s+(\d+\s?[a-zA-Z]?(?:[-\/]\d+)?)$/) : null;

			if (street) {
				found.street = street[1];
				found.house = street[2];
				found.name = block[0] !== block[last - 1] ? block[0] : '';
				found.addition = block.slice(1, last - 1).join(', ');
			}
		}

		return found;
	}

	function product(found) {
		var cents = found.price ? Math.round(parseFloat(found.price.replace(',', '.')) * 100) : 0;
		var match = Array.prototype.find.call(document.querySelectorAll('#taxship-stamp-letters option'), function (option) {
			return parseInt(option.getAttribute('data-cents'), 10) === cents;
		});

		var registered = document.querySelector('[data-taxship-stamp-state]').getAttribute('data-registered');

		return match ? match.value : (found.registered ? registered : '');
	}

	// A PDF from the disk or from a mail: read, and the dialog filled from its lines.
	async function load(file, name) {
		chosen = file;
		var state = document.querySelector('[data-taxship-stamp-state]');

		try {
			var found = read(await taxmodLabel62.lines(file));
			put('product', product(found));
			put('price', found.price);
			put('number', found.number);
			// D-1510: the address on the stamp is only shown; the receiver contact is optional and nothing is stored from the stamp.
			var address = document.querySelector('[data-taxship-stamp-address]');
			var shown = [found.name, found.addition, [found.street, found.house].filter(Boolean).join(' '), [found.postal_code, found.city].filter(Boolean).join(' ')].filter(Boolean);
			address.textContent = shown.length ? address.getAttribute('data-label') + ' ' + shown.join(', ') : '';
			state.textContent = state.getAttribute('data-read') + ' ' + name;
		} catch (failure) {
			state.textContent = taxmodLabel62.failed + ' ' + failure.message;
		}
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-taxship-stamp-pick]');

		if (!button) {
			return;
		}

		event.preventDefault();
		taxmodLabel62.pick().then(function (file) {
			if (file) {
				field('mail_cart').value = '';
				field('carrier').value = 'post';
				load(file, file.name);
			}
		});
	});

	// D-1511: «Sendung anlegen» at a label from a mail opens the same dialog with that label's PDF.
	document.addEventListener('click', async function (event) {
		var button = event.target.closest('[data-taxship-mail-pdf]');

		if (!button) {
			return;
		}

		event.preventDefault();
		button.disabled = true;

		try {
			var response = await fetch(button.getAttribute('data-taxship-mail-pdf'), { credentials: 'same-origin' });

			if (!response.ok || response.headers.get('Content-Type') !== 'application/pdf') {
				throw new Error((await response.text()).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200));
			}

			document.querySelector('[data-taxship-stamp-form] + div .taxmod-dialog-open').click();
			field('mail_cart').value = button.getAttribute('data-taxship-mail-cart');
			field('carrier').value = button.getAttribute('data-taxship-mail-carrier');
			await load(new Blob([await response.arrayBuffer()], { type: 'application/pdf' }), button.getAttribute('data-taxship-mail-cart'));
		} catch (failure) {
			window.alert(taxmodLabel62.failed + ' ' + failure.message);
		} finally {
			button.disabled = false;
		}
	});

	// Printed on the click, then recorded: the PDF is only in this page, so it is printed before the form leaves it.
	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (!form.matches('[data-taxship-stamp-form]') || !chosen || form.dataset.printed) {
			return;
		}

		// Nothing is printed for an entry the server will refuse: it then says what is missing.
		var price = field('price');

		if (!field('product').value.trim() || !price || !price.value.trim()) {
			return;
		}

		event.preventDefault();
		taxmodLabel62.run(chosen, '').then(function () {
			form.dataset.printed = '1';
			form.submit();
		});
	});
}());
