/* Profile pictures screen: add Media Library pictures to the list, take them off again, and keep the grid in step.
   Plain JavaScript; the form saves without it. window.lbAvatars ({ drawn, max, i18n }) is printed by Avatars_Admin. */
(function () {
	'use strict';
	var cfg = window.lbAvatars || { i18n: {}, drawn: [] };
	var t = function (k, d) { return (cfg.i18n && cfg.i18n[k]) || d; };
	var form = document.getElementById('lb-avatars-form');
	var own = document.querySelector('[data-lb-own]');
	var grid = document.querySelector('[data-lb-tiles]');
	var empty = document.querySelector('[data-lb-own-empty]');
	var add = document.querySelector('[data-lb-add-pictures]');
	var bar = document.querySelector('.lb-savebar');
	var note = document.getElementById('lb-save-note');
	if (!form || !own || !grid) { return; }
	var frame, full = false;
	var max = parseInt(cfg.max, 10) || 40;

	function dirty(text) {
		if (bar) { bar.classList.add('is-dirty'); }
		if (note) { note.textContent = text || t('added', ''); }
	}
	function el(tag, cls, attrs) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
		return n;
	}
	function image(url) {
		var span = el('span', 'lb-tile__img');
		var img = el('img', '', { src: url, alt: '', width: '64', height: '64', loading: 'lazy' });
		span.appendChild(img);
		return span;
	}
	function refreshEmpty() {
		if (empty) { empty.hidden = own.children.length > 0; }
	}
	function has(id) { return !!own.querySelector('[data-att="' + id + '"]'); }

	function addPicture(a) {
		var id = String(a.id), size = (a.sizes && (a.sizes.thumbnail || a.sizes.medium)) || a;
		var label = a.title || a.filename || id;
		if (!id || has(id)) { return; }
		if (own.children.length >= max) { full = true; return; } // The list holds a fixed number of pictures: say so instead of dropping the rest on save.
		// In the list of the owner's pictures.
		var tile = el('div', 'lb-tile lb-tile--own', { 'data-att': id });
		tile.appendChild(image(size.url));
		var name = el('span', 'lb-tile__name'); name.textContent = label; tile.appendChild(name);
		var x = el('button', 'lb-tile__x', { type: 'button', 'data-lb-remove-picture': '', 'aria-label': t('remove', 'Remove from the list') + ': ' + label });
		x.innerHTML = '<svg class="lb-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg>';
		tile.appendChild(x);
		tile.appendChild(el('input', '', { type: 'hidden', name: 'library[]', value: id }));
		own.appendChild(tile);
		// And as a choice for people without a photo.
		var choice = el('label', 'lb-tile', { 'data-slug': 'media-' + id });
		choice.appendChild(el('input', '', { type: 'radio', name: 'default', value: 'preset:media-' + id }));
		choice.appendChild(image(size.url));
		var cname = el('span', 'lb-tile__name'); cname.textContent = label; choice.appendChild(cname);
		grid.appendChild(choice);
		refreshEmpty();
	}

	if (add) {
		add.addEventListener('click', function () {
			if (!window.wp || !wp.media) { return; }
			if (!frame) {
				frame = wp.media({ title: t('title', 'Add pictures from the Media Library'), button: { text: t('button', 'Add to the list') }, library: { type: 'image' }, multiple: 'add' });
				frame.on('select', function () {
					full = false;
					frame.state().get('selection').each(function (m) { addPicture(m.toJSON()); });
					dirty(full ? t('full', 'The list is full. Remove one before adding another.') : t('added', 'Added. Save to keep them.'));
				});
			}
			frame.open();
		});
	}

	own.addEventListener('click', function (e) {
		var b = e.target.closest('[data-lb-remove-picture]');
		if (!b) { return; }
		var tile = b.closest('[data-att]');
		var id = tile && tile.getAttribute('data-att');
		if (!id) { return; }
		var choice = grid.querySelector('[data-slug="media-' + id + '"]');
		if (choice) {
			var radio = choice.querySelector('input');
			if (radio && radio.checked) { // The default goes with it: fall back to the drawn initials.
				var fallback = grid.ownerDocument.querySelector('input[name="default"][value="initials"]');
				if (fallback) { fallback.checked = true; }
			}
			choice.parentNode.removeChild(choice);
		}
		var next = tile.nextElementSibling || tile.previousElementSibling; // Keyboard focus moves on to a neighbour, never to the page.
		tile.parentNode.removeChild(tile);
		refreshEmpty();
		var target = next && next.querySelector('[data-lb-remove-picture]');
		if (!target && add) { target = add; }
		if (target) { target.focus(); }
		dirty(t('gone', 'Removed. Save to keep the change.'));
	});

	form.addEventListener('change', function () { dirty(t('unsaved', 'You have unsaved changes.')); });
}());
