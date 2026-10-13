/* Business Info screen: repeaters, image picker, unsaved-changes note, click-to-copy tokens, and opening "More options"
   when a link or an invalid field points inside it. Plain JavaScript; the form works without it (nothing here is needed
   to save). window.lbCompany ({ i18n }) is printed by Company_Admin. */
(function () {
	'use strict';
	var cfg = window.lbCompany || { i18n: {} };
	var t = function (k, d) { return (cfg.i18n && cfg.i18n[k]) || d; };
	var form = document.getElementById('lb-biz-form');
	if (!form) { return; }

	/* ---- repeaters (review sites, opening hours) ---- */
	document.addEventListener('click', function (e) {
		var add = e.target.closest('[data-lb-add]');
		if (add) {
			var key = add.getAttribute('data-lb-add');
			var tpl = document.getElementById('lb-tpl-' + key);
			var rows = document.querySelector('[data-lb-rep="' + key + '"] .lb-rep__rows');
			if (tpl && rows) {
				var holder = document.createElement('div');
				holder.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + Date.now());
				var row = holder.firstElementChild;
				rows.appendChild(row);
				var first = row.querySelector('input:not([type="checkbox"]),select');
				if (first) { first.focus(); }
				markDirty();
			}
			return;
		}
		var rm = e.target.closest('[data-lb-remove]');
		if (rm) {
			var r = rm.closest('.lb-rep__row');
			if (r) {
				var list = r.parentNode;
				r.remove();
				var next = list.querySelector('.lb-rep__row input:not([type="checkbox"]),.lb-rep__row select');
				var addBtn = list.closest('.lb-rep').querySelector('[data-lb-add]');
				(next || addBtn).focus();
				markDirty();
			}
		}
	});

	/* ---- image fields: the media library ---- */
	document.addEventListener('click', function (e) {
		var pick = e.target.closest('[data-lb-pick]');
		var clear = e.target.closest('[data-lb-clear]');
		if (!pick && !clear) { return; }
		var box = (pick || clear).closest('[data-lb-image]');
		var input = box.querySelector('input[type="text"]');
		var preview = box.querySelector('[data-lb-image-preview]');
		var pickBtn = box.querySelector('[data-lb-pick]');
		var clearBtn = box.querySelector('[data-lb-clear]');
		var show = function (url) {
			preview.textContent = '';
			preview.classList.toggle('is-empty', !url);
			if (url) {
				var img = document.createElement('img');
				img.src = url;
				img.alt = '';
				preview.appendChild(img);
			}
			pickBtn.textContent = input.value ? t('change', 'Change image') : t('choose', 'Choose image');
			clearBtn.hidden = !input.value;
		};
		if (clear) {
			input.value = '';
			show('');
			markDirty();
			return;
		}
		if (!window.wp || !window.wp.media) { input.focus(); return; }
		var frame = window.wp.media({ title: t('choose', 'Choose image'), button: { text: t('use', 'Use this image') }, library: { type: 'image' }, multiple: false });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			input.value = a.id;
			show((a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url) || '');
			markDirty();
		});
		frame.open();
	});

	/* ---- click to copy a token ---- */
	document.addEventListener('click', function (e) {
		var chip = e.target.closest('[data-lb-token]');
		if (!chip) { return; }
		var text = chip.getAttribute('data-lb-token');
		var done = function () {
			if (window.lbAdmin && window.lbAdmin.say) { window.lbAdmin.say(t('copied', 'Copied') + ': ' + text); }
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
		} else {
			fallbackCopy(text);
			done();
		}
	});
	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (err) { /* nothing more to try */ }
		ta.remove();
	}

	/* ---- unsaved changes: the Save bar says so, and the Rank Math buttons wait for a save ---- */
	var dirty = false;
	var bar = form.querySelector('.lb-savebar');
	var note = document.getElementById('lb-save-note');
	var rmNote = document.getElementById('lb-rm-dirty');
	function markDirty() {
		if (dirty) { return; }
		dirty = true;
		if (bar) { bar.classList.add('is-dirty'); }
		if (note) { note.textContent = t('unsaved', 'You have unsaved changes.'); }
		document.querySelectorAll('[data-lb-rm]').forEach(function (b) { b.disabled = true; });
		if (rmNote) { rmNote.hidden = false; rmNote.textContent = t('saveRm', 'Save your changes first, so Rank Math gets them.'); }
	}
	var onEdit = function (e) {
		if (e.target && e.target.form === form) { markDirty(); }
	};
	form.addEventListener('input', onEdit);
	form.addEventListener('change', onEdit);
	form.addEventListener('submit', function () { dirty = false; });
	window.addEventListener('beforeunload', function (e) {
		if (dirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	});

	/* ---- More options opens for a link or a field that needs attention inside it ---- */
	var more = document.getElementById('lb-g-more');
	function openFor(el) {
		var d = el && el.closest ? el.closest('details') : null;
		while (d) {
			d.open = true;
			d = d.parentElement ? d.parentElement.closest('details') : null;
		}
	}
	function fromHash() {
		if (!window.location.hash) { return; }
		var el = document.getElementById(window.location.hash.slice(1));
		if (el) {
			openFor(el);
			if (el.tagName === 'DETAILS') { el.open = true; }
			el.scrollIntoView();
		}
	}
	window.addEventListener('hashchange', fromHash);
	fromHash();
	document.addEventListener('click', function (e) {
		var a = e.target.closest('a[href^="#"]');
		if (a && more && a.getAttribute('href') === '#lb-g-more') { more.open = true; }
	});
	form.addEventListener('invalid', function (e) { openFor(e.target); }, true);
})();
