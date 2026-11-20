/**
 * D-1507: «Gekaufte Marke hochladen». Reads the Internetmarke PDF from the Post shop in the browser (pdf.js of the Etiketten module's
 * taxmod-label62), fills the dialog with what its text says — product, price, shipment number, and the receiver's address as a hint — and on «Eintragen und drucken» prints
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

	// Text items grouped into lines from top to bottom, each line's words left to right.
	async function lines(file) {
		pdfjsLib.GlobalWorkerOptions.workerSrc = taxmodLabel62.worker;
		var pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer(), cMapUrl: taxmodLabel62.cmaps, cMapPacked: true, standardFontDataUrl: taxmodLabel62.fonts }).promise;
		var content = await (await pdf.getPage(1)).getTextContent();
		var rows = [];

		content.items.forEach(function (item) {
			if (!item.str.trim()) {
				return;
			}

			var y = item.transform[5], x = item.transform[4];
			var row = rows.find(function (r) { return Math.abs(r.y - y) < 3; });

			if (!row) {
				row = { y: y, words: [] };
				rows.push(row);
			}

			row.words.push({ x: x, text: item.str.trim() });
		});

		rows.sort(function (a, b) { return b.y - a.y; });

		return rows.map(function (row) {
			return { y: row.y, text: row.words.sort(function (a, b) { return a.x - b.x; }).map(function (w) { return w.text; }).join(' ').replace(/\s+/g, ' ') };
		});
	}

	// The Internetmarke as bought on 20.11.2026: sender line, «EINSCHREIBEN», number, «IM <date> <price>», then the receiver block.
	function read(rows) {
		var found = {};
		var priceAt = -1;

		rows.forEach(function (row, i) {
			var price = row.text.match(/\b\d{2}\.\d{2}\.\d{2}\s+(\d{1,3},\d{2})\b/);
			var number = row.text.match(/\b(A0(?:\s?[0-9A-F]{2,4}){4,})\b/);

			if (price && priceAt < 0) {
				found.price = price[1];
				priceAt = i;
			}

			if (number && !found.number) {
				found.number = number[1].replace(/\s+/g, '');
			}

			if (/EINSCHREIBEN/i.test(row.text)) {
				found.registered = true;
			}
		});

		var block = priceAt < 0 ? [] : rows.slice(priceAt + 1).map(function (r) { return r.text; }).filter(function (text) {
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

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-taxship-stamp-pick]');

		if (!button) {
			return;
		}

		event.preventDefault();
		var input = document.createElement('input');
		input.type = 'file';
		input.accept = 'application/pdf,.pdf';
		input.addEventListener('change', async function () {
			if (!input.files || !input.files.length) {
				return;
			}

			chosen = input.files[0];
			var state = document.querySelector('[data-taxship-stamp-state]');

			try {
				var found = read(await lines(chosen));
				put('product', product(found));
				put('price', found.price);
				put('number', found.number);
				// D-1510: the address on the stamp is only shown; the receiver contact is optional and nothing is stored from the stamp.
				var address = document.querySelector('[data-taxship-stamp-address]');
				var shown = [found.name, found.addition, [found.street, found.house].filter(Boolean).join(' '), [found.postal_code, found.city].filter(Boolean).join(' ')].filter(Boolean);
				address.textContent = shown.length ? address.getAttribute('data-label') + ' ' + shown.join(', ') : '';
				state.textContent = state.getAttribute('data-read') + ' ' + chosen.name;
			} catch (failure) {
				state.textContent = taxmodLabel62.failed + ' ' + failure.message;
			}
		});
		input.click();
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
