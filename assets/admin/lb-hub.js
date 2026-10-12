/* Launch Builder Design hub: the two tabs, the page stage (a frame rendered at a true width and scaled to the pane), the
   pattern library list and the change-design forms. Plain JavaScript; window.lbHub is printed by the page. */
(function () {
	'use strict';
	var cfg = window.lbHub;
	var root = document.querySelector('.lb-hub');
	if (!cfg || !root) { return; }
	var T = cfg.t || {};

	function q(sel, ctx) { return (ctx || root).querySelector(sel); }
	function qa(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
	function pref(key, val) {
		try {
			if (val === undefined) { return window.localStorage.getItem(key); }
			window.localStorage.setItem(key, val);
		} catch (e) { /* storage can be blocked: the width simply is not remembered */ }
		return null;
	}

	/* ---- a stage: a frame scaled to fit the pane ---- */
	function Stage(el) {
		var view = q('.lb-stage__view', el), box = q('[data-lb-frame]', el), frame = q('iframe', el);
		var load = q('[data-lb-loading]', el), size = q('[data-lb-size]', el), banner = q('[data-lb-banner]', el), open = q('[data-lb-open]', el);
		var btns = qa('[data-w]', el);
		var cur = btns.length ? +btns[0].getAttribute('data-w') : 1280;
		var timer = 0, started = false, liveSrc = '';
		var api = { el: el, frame: frame };

		function height() { return Math.max(440, Math.min(860, window.innerHeight - 250)); }
		function fit() {
			if (!el.offsetWidth) { return; }
			var cw = Math.max(240, view.clientWidth - 24);
			var sc = Math.min(1, cw / cur);
			var h = height();
			box.style.width = Math.round(cur * sc) + 'px';
			box.style.height = h + 'px';
			frame.style.width = cur + 'px';
			frame.style.height = Math.round(h / sc) + 'px';
			frame.style.transform = 'scale(' + sc + ')';
			if (size) { size.textContent = cur + ' px' + (sc < 0.995 ? ' · ' + Math.round(sc * 100) + '%' : ''); }
		}
		function busy(on) {
			window.clearTimeout(timer);
			if (!load) { return; }
			load.hidden = !on;
			if (on) { timer = window.setTimeout(function () { load.hidden = true; }, 20000); }
		}
		function loadedNow() {
			try {
				var d = frame.contentDocument;
				return !!d && d.readyState === 'complete' && frame.contentWindow.location.href !== 'about:blank';
			} catch (e) { return false; }
		}
		function press(b) {
			btns.forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			cur = +b.getAttribute('data-w');
		}
		btns.forEach(function (b, i) {
			b.addEventListener('click', function () { press(b); pref('lbHubW', String(i)); fit(); });
		});
		frame.addEventListener('load', function () { busy(false); });

		api.go = function (url, openUrl) {
			busy(true);
			frame.src = url;
			if (open && openUrl) { open.href = openUrl; }
		};
		api.fit = fit;
		api.show = function () {
			if (!started) {
				started = true;
				var saved = pref('lbHubW');
				if (saved !== null && btns[+saved]) { press(btns[+saved]); }
				else if (view.clientWidth && view.clientWidth < 700 && btns.length) { press(btns[btns.length - 1]); }
				else if (btns.length) {
					var on = btns.filter(function (x) { return x.getAttribute('aria-pressed') === 'true'; })[0];
					press(on || btns[0]);
				}
				var ds = frame.getAttribute('data-src');
				if (!frame.getAttribute('src') && ds) { frame.removeAttribute('data-src'); busy(true); frame.src = ds; }
				else if (frame.getAttribute('src') && !loadedNow()) { busy(true); }
			}
			fit();
		};
		api.reload = function () {
			if (!frame.getAttribute('src')) { return; }
			busy(true);
			try { frame.contentWindow.location.reload(); } catch (e) { frame.src = frame.getAttribute('src'); }
		};
		api.preview = function (url, text) {
			if (!liveSrc) { liveSrc = frame.getAttribute('src') || ''; }
			if (banner) {
				banner.textContent = '';
				var s = document.createElement('span');
				s.textContent = text;
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'lb-btn lb-btn--sm';
				b.textContent = T.back || 'Back';
				b.addEventListener('click', function () { api.back(); });
				banner.appendChild(s);
				banner.appendChild(b);
				banner.hidden = false;
			}
			busy(true);
			frame.src = url;
			fit();
		};
		api.back = function () {
			if (banner) { banner.hidden = true; banner.textContent = ''; }
			if (liveSrc) { busy(true); frame.src = liveSrc; liveSrc = ''; }
			fit();
		};
		var reload = q('[data-lb-reload]', el);
		if (reload) { reload.addEventListener('click', api.reload); }
		return api;
	}

	/* ---- the pattern library list ---- */
	function Library(stage) {
		var items = qa('.lb-it'), heads = qa('.lb-all'), loaded = '', began = false;
		var title = q('#lb-t'), desc = q('#lb-d'), used = q('#lb-used'), search = q('#lb-q');

		function mark(a) { items.forEach(function (x) { x.classList.toggle('on', x === a); }); }
		function url(cat, slug) { return cfg.base + '?lb_preview=@' + cat + '&_lbn=' + cfg.n + (slug ? '#p-' + slug : ''); }
		function load(cat, slug) {
			if (loaded === cat) {
				try {
					var d = stage.frame.contentWindow.document;
					var el = slug ? d.getElementById('p-' + slug) : null;
					stage.frame.contentWindow.scrollTo(0, el ? el.offsetTop : 0);
					return;
				} catch (e) { /* not loaded yet: fall through and load it */ }
			}
			loaded = cat;
			stage.go(url(cat, slug), url(cat, slug));
		}
		function usedInfo(a) {
			if (!used) { return; }
			var text = a ? a.getAttribute('data-used') : '';
			used.textContent = '';
			if (!text) { used.hidden = true; return; }
			var b = document.createElement('strong');
			b.textContent = T.in_use || 'In use:';
			used.appendChild(b);
			used.appendChild(document.createTextNode(' ' + text + ' '));
			var u = a.getAttribute('data-used-url');
			if (u) {
				var l = document.createElement('a');
				l.href = u;
				l.textContent = T.see_live || 'See it on a page';
				used.appendChild(l);
			}
			used.hidden = false;
		}
		function showItem(a, remember) {
			mark(a);
			title.textContent = a.getAttribute('data-title') + '  (' + a.getAttribute('data-slug') + ')';
			desc.textContent = a.getAttribute('data-desc') + '  ' + (T.use_with || 'Use it with: apply-pattern slug') + ' "' + a.getAttribute('data-slug') + '"';
			usedInfo(a);
			load(a.getAttribute('data-cat'), a.getAttribute('data-slug'));
			if (remember !== false) {
				var u = new URL(window.location.href);
				u.searchParams.set('tab', 'library');
				u.searchParams.set('p', a.getAttribute('data-slug'));
				window.history.replaceState(null, '', u.pathname + u.search);
			}
		}
		items.forEach(function (a) {
			a.addEventListener('click', function (e) { e.preventDefault(); showItem(a); });
		});
		heads.forEach(function (h) {
			h.addEventListener('click', function (e) {
				e.preventDefault();
				mark(null);
				title.textContent = h.getAttribute('data-title') + ' ' + (T.all_suffix || '(all)');
				desc.textContent = T.all_hint || '';
				usedInfo(null);
				load(h.getAttribute('data-cat'), '');
			});
		});
		if (search) {
			search.addEventListener('input', function (e) {
				var s = e.target.value.toLowerCase();
				items.forEach(function (a) {
					var hay = (a.getAttribute('data-title') + a.getAttribute('data-slug') + a.getAttribute('data-desc')).toLowerCase();
					a.style.display = hay.indexOf(s) > -1 ? '' : 'none';
				});
				qa('.lb-g').forEach(function (g) {
					var any = qa('.lb-it', g).some(function (a) { return a.style.display !== 'none'; });
					g.style.display = any ? '' : 'none';
				});
			});
		}
		return {
			start: function () {
				if (began) { return; }
				began = true;
				var first = items.filter(function (a) { return a.classList.contains('on'); })[0] || items[0];
				if (first) { showItem(first, false); }
			}
		};
	}

	/* ---- tabs ---- */
	var panels = { placed: q('#lb-hub-placed'), library: q('#lb-hub-library') };
	var liveEl = q('#lb-stage-live');
	var live = liveEl ? Stage(liveEl) : null;
	var libEl = q('#lb-stage-lib');
	var libStage = libEl ? Stage(libEl) : null;
	var lib = libStage ? Library(libStage) : null;

	function show(name, remember) {
		if (!panels[name]) { return; }
		Object.keys(panels).forEach(function (n) { panels[n].hidden = n !== name; });
		qa('[data-lb-hub-tab]').forEach(function (a) {
			a.setAttribute('aria-selected', a.getAttribute('data-lb-hub-tab') === name ? 'true' : 'false');
		});
		if (remember) {
			var u = new URL(window.location.href);
			u.searchParams.set('tab', name);
			window.history.replaceState(null, '', u.pathname + u.search);
		}
		if (name === 'placed' && live) { live.show(); }
		if (name === 'library' && libStage) { libStage.show(); lib.start(); }
	}
	qa('[data-lb-hub-tab]').forEach(function (a) {
		a.addEventListener('click', function (e) { e.preventDefault(); show(a.getAttribute('data-lb-hub-tab'), true); });
		a.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') { return; }
			var tabs = qa('[data-lb-hub-tab]');
			var next = tabs[(tabs.indexOf(a) + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
			next.focus();
			show(next.getAttribute('data-lb-hub-tab'), true);
		});
	});

	/* ---- change-design forms ---- */
	root.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f || !f.querySelector || !(f.hasAttribute('data-lb-busy') || (f.parentNode && f.parentNode.classList && f.parentNode.classList.contains('lb-flash')))) { return; }
		qa('button[type="submit"]', f).forEach(function (b) {
			b.setAttribute('data-label', b.textContent);
			b.disabled = true;
			b.textContent = b.getAttribute('data-busy') || T.busy || 'Working…';
		});
	});
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) { return; }
		qa('button[data-label]').forEach(function (b) { b.disabled = false; b.textContent = b.getAttribute('data-label'); });
	});
	document.addEventListener('toggle', function (e) { // One open form at a time.
		var d = e.target;
		if (d && d.classList && d.classList.contains('lb-change') && d.open) {
			qa('.lb-change[open]').forEach(function (x) { if (x !== d) { x.open = false; } });
		}
	}, true);

	/* ---- preview a pattern on its own, as a page of the role ---- */
	root.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-lb-preview]') : null;
		if (!b || !live) { return; }
		var sel = document.getElementById(b.getAttribute('data-lb-preview'));
		var opt = sel && sel.options[sel.selectedIndex];
		var slug = opt ? opt.getAttribute('data-slug') : '';
		if (!slug) { if (sel) { sel.focus(); } return; }
		var role = b.getAttribute('data-role') || '';
		live.preview(
			cfg.base + '?lb_preview=' + encodeURIComponent(slug) + '&_lbn=' + cfg.n + '&lb_role=' + encodeURIComponent(role),
			(T.previewing || 'Previewing %1$s on its own, as a %2$s page.').replace('%1$s', opt.getAttribute('data-title') || slug).replace('%2$s', b.getAttribute('data-role-label') || role)
		);
		var r = live.el.getBoundingClientRect();
		if (r.top < 40 || r.bottom > window.innerHeight) { live.el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
	});

	/* ---- the current page type chip stays in view when the row scrolls sideways ---- */
	var chip = q('.lb-role[aria-current]');
	if (chip && chip.parentNode && chip.parentNode.scrollWidth > chip.parentNode.clientWidth) {
		chip.parentNode.scrollLeft = chip.offsetLeft - (chip.parentNode.clientWidth - chip.offsetWidth) / 2;
	}

	var raf = 0;
	window.addEventListener('resize', function () {
		window.cancelAnimationFrame(raf);
		raf = window.requestAnimationFrame(function () {
			if (live) { live.fit(); }
			if (libStage) { libStage.fit(); }
		});
	});

	show(cfg.tab === 'library' ? 'library' : 'placed', false);
})();
