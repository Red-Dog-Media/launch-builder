/* Launch Builder admin: module switches (saved as you click), search and filters, copy buttons, tabs, click feedback between screens.
   Plain JavaScript; window.lbAdmin ({ ajax, nonce, i18n }) is printed by the dashboard class. */
(function () {
	'use strict';
	var cfg = window.lbAdmin || { ajax: '', nonce: '', i18n: {} };
	var t = function (k, d) { return (cfg.i18n && cfg.i18n[k]) || d; };

	/* ---- toast ---- */
	var toast, toastTimer;
	function say(message, opts) {
		opts = opts || {};
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'lb-toast';
			toast.setAttribute('role', 'status');
			toast.setAttribute('aria-live', 'polite');
			document.body.appendChild(toast);
		}
		toast.className = 'lb-toast' + (opts.bad ? ' lb-toast--bad' : '');
		toast.textContent = '';
		var span = document.createElement('span');
		span.textContent = message;
		toast.appendChild(span);
		if (opts.reload) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = t('reload', 'Reload');
			b.addEventListener('click', function () { window.location.reload(); });
			toast.appendChild(b);
		}
		window.requestAnimationFrame(function () { toast.classList.add('is-on'); });
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(function () { toast.classList.remove('is-on'); }, opts.reload ? 9000 : 4200);
	}

	cfg.say = say; // Other screens' scripts show their messages the same way.
	window.lbAdmin = cfg;

	/* ---- module switches ---- */
	function counts() {
		var tiles = document.querySelectorAll('.lb-mod');
		var on = 0;
		tiles.forEach(function (x) { if (x.getAttribute('data-on') === '1') { on++; } });
		document.querySelectorAll('[data-lb-on-count]').forEach(function (el) { el.textContent = on; });
		document.querySelectorAll('[data-lb-total-count]').forEach(function (el) { el.textContent = tiles.length; });
	}
	function setTile(tile, on) {
		tile.setAttribute('data-on', on ? '1' : '0');
		var sw = tile.querySelector('.lb-switch');
		if (sw) { sw.setAttribute('aria-checked', on ? 'true' : 'false'); }
		var chip = tile.querySelector('[data-lb-state]');
		if (chip) {
			chip.textContent = on ? t('on', 'On') : t('off', 'Off');
			chip.className = 'lb-chip lb-chip--' + (on ? 'on' : 'plain');
		}
		counts();
	}
	document.addEventListener('click', function (e) {
		var sw = e.target.closest('.lb-switch');
		if (!sw || sw.disabled || sw.getAttribute('aria-busy') === 'true') { return; }
		var tile = sw.closest('.lb-mod');
		if (!tile) { return; }
		var want = sw.getAttribute('aria-checked') !== 'true';
		var label = tile.getAttribute('data-label') || '';
		sw.setAttribute('aria-busy', 'true');
		setTile(tile, want); // Show the change at once; undone below when the server says no.
		var body = new URLSearchParams();
		body.set('action', 'lb_module_toggle');
		body.set('nonce', cfg.nonce);
		body.set('slug', tile.getAttribute('data-slug'));
		body.set('on', want ? '1' : '0');
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				sw.removeAttribute('aria-busy');
				if (!j || !j.success) {
					setTile(tile, !want);
					say((j && j.data && j.data.message) || t('failed', 'Could not save that change.'), { bad: true });
					return;
				}
				setTile(tile, !!j.data.enabled);
				say(j.data.message || label, { reload: !!j.data.reload });
			})
			.catch(function () {
				sw.removeAttribute('aria-busy');
				setTile(tile, !want);
				say(t('failed', 'Could not save that change.'), { bad: true });
			});
	});

	/* ---- search and category filter ---- */
	function applyFilter(root) {
		var q = (root.querySelector('[data-lb-search]') || { value: '' }).value.trim().toLowerCase();
		var pressed = root.querySelector('.lb-filters [aria-pressed="true"]');
		var cat = pressed ? pressed.getAttribute('data-cat') : 'all';
		var shown = 0;
		root.querySelectorAll('.lb-mod').forEach(function (tile) {
			var hay = (tile.getAttribute('data-label') + ' ' + (tile.getAttribute('data-text') || '')).toLowerCase();
			var inCat = cat === 'all' || (cat === 'on' ? tile.getAttribute('data-on') === '1' : tile.getAttribute('data-cat') === cat);
			var ok = inCat && (q === '' || hay.indexOf(q) !== -1);
			tile.hidden = !ok;
			if (ok) { shown++; }
		});
		var empty = root.querySelector('[data-lb-none]');
		if (empty) { empty.hidden = shown !== 0; }
		var c = root.querySelector('[data-lb-shown]');
		if (c) { c.textContent = shown; }
	}
	document.addEventListener('input', function (e) {
		var s = e.target.closest('[data-lb-search]');
		if (s) { applyFilter(s.closest('[data-lb-board]') || document); }
	});
	document.addEventListener('click', function (e) {
		var f = e.target.closest('.lb-filters button');
		if (f) {
			var root = f.closest('[data-lb-board]') || document;
			root.querySelectorAll('.lb-filters button').forEach(function (b) { b.setAttribute('aria-pressed', b === f ? 'true' : 'false'); });
			applyFilter(root);
		}
	});

	/* ---- copy buttons and tabs (setup screen) ---- */
	document.addEventListener('click', function (e) {
		var b = e.target.closest('[data-lb-copy]');
		if (b) {
			var src = document.getElementById(b.getAttribute('data-lb-copy'));
			if (src && navigator.clipboard) {
				var clone = src.cloneNode(true);
				clone.querySelectorAll('button').forEach(function (x) { x.remove(); });
				navigator.clipboard.writeText(clone.innerText.trim()).then(function () {
					var o = b.textContent;
					b.textContent = t('copied', 'Copied');
					window.setTimeout(function () { b.textContent = o; }, 1400);
				});
			}
		}
		var tab = e.target.closest('[data-lb-tab]');
		if (tab) {
			var g = tab.closest('.lb-tabgroup');
			g.querySelectorAll('.lb-tabs button').forEach(function (x) { x.classList.remove('on'); x.setAttribute('aria-selected', 'false'); });
			tab.classList.add('on');
			tab.setAttribute('aria-selected', 'true');
			g.querySelectorAll('.lb-pane').forEach(function (p) { p.classList.toggle('on', p.id === tab.getAttribute('data-lb-tab')); });
		}
	});

	/* ---- links between Launch Builder screens: show at once that the next one is loading ---- */
	var leavingTimer;
	document.addEventListener('click', function (e) {
		if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
		var a = e.target.closest ? e.target.closest('a[href]') : null;
		if (!a || a.target === '_blank' || a.hasAttribute('download')) { return; }
		var u;
		try { u = new URL(a.href, window.location.href); } catch (x) { return; }
		var here = window.location;
		if (u.origin !== here.origin || !/\/admin\.php$/.test(u.pathname) || !/(^|&)page=(launch-builder|lb-)/.test(u.search.slice(1))) { return; }
		if (u.pathname === here.pathname && u.search === here.search && u.hash) { return; } // A jump inside this screen.
		document.body.classList.add('lb-leaving');
		window.clearTimeout(leavingTimer);
		leavingTimer = window.setTimeout(function () { document.body.classList.remove('lb-leaving'); }, 10000); // The visit was cancelled (for example "leave this page?"): carry on as before.
	});
	window.addEventListener('pageshow', function () { document.body.classList.remove('lb-leaving'); }); // Back to a kept copy of this screen.

	counts();
})();
