/* Launch Builder Design hub: the two tabs, the page stage (a frame rendered at a true width and scaled to the pane), the
   pattern library list, the Change design dialog and the plain change-design forms. Plain JavaScript; window.lbHub and the
   dialog's designs (#lb-hub-pick) are printed by the page. */
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
	function Stage(el, opts) {
		opts = opts || {};
		var view = q('.lb-stage__view', el), box = q('[data-lb-frame]', el), frame = q('iframe', el);
		var load = q('[data-lb-loading]', el), size = q('[data-lb-size]', el), open = q('[data-lb-open]', el);
		var btns = qa('[data-w]', el);
		var cur = btns.length ? +btns[0].getAttribute('data-w') : 1280;
		var timer = 0, started = false;
		var api = { el: el, frame: frame };

		function height(sc) { return opts.height ? opts.height(sc) : Math.max(440, Math.min(860, window.innerHeight - 250)); }
		function fit() {
			if (!el.offsetWidth) { return; }
			var cw = Math.max(240, view.clientWidth - 24);
			var sc = Math.min(1, cw / cur);
			var h = height(sc);
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
			b.addEventListener('click', function () { press(b); if (!opts.volatile) { pref('lbHubW', String(i)); } fit(); });
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
		// Pick the width button nearest to px (the dialog opens a phone-only design at phone width).
		api.setWidth = function (px) {
			if (!btns.length) { return; }
			var best = btns[0];
			btns.forEach(function (x) { if (Math.abs(+x.getAttribute('data-w') - px) < Math.abs(+best.getAttribute('data-w') - px)) { best = x; } });
			press(best);
		};
		api.clear = function () {
			busy(false);
			frame.removeAttribute('src');
			try { frame.contentWindow.location.replace('about:blank'); } catch (e) { /* the frame is gone already */ }
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

	/* ---- the Change design dialog: a grid of live previews, then one design larger with its scope and Apply ---- */
	function Picker() {
		var dlg = document.getElementById('lb-modal');
		var raw = document.getElementById('lb-hub-pick');
		if (!dlg || !raw || typeof dlg.showModal !== 'function') { return null; } // The plain forms on the cards keep working.
		var data;
		try { data = JSON.parse(raw.textContent); } catch (e) { return null; }
		if (!data || !data.slots) { return null; }

		var form = q('form', dlg), list = q('[data-lb-pk-list]', dlg), title = q('[data-lb-pk-title]', dlg), sub = q('[data-lb-pk-sub]', dlg);
		var hint = q('[data-lb-pk-hint]', dlg), picked = q('[data-lb-pk-picked]', dlg), nopv = q('[data-lb-pk-nopv]', dlg);
		var nameEl = q('[data-lb-pk-name]', dlg), descEl = q('[data-lb-pk-desc]', dlg), forEl = q('[data-lb-pk-for]', dlg);
		var scopeBox = q('[data-lb-pk-scope]', dlg), scopeList = q('[data-lb-pk-scope-list]', dlg);
		var sentence = q('[data-lb-pk-sentence]', dlg), ownEl = q('[data-lb-pk-own]', dlg), repEl = q('[data-lb-pk-replaces]', dlg);
		var apply = q('[data-lb-pk-apply]', dlg), back = q('[data-lb-pk-back]', dlg), applyLabel = apply.textContent;
		var slotField = q('input[name="slot"]', dlg), designField = q('input[name="design"]', dlg);
		var stage = Stage(q('#lb-stage-modal', dlg), { volatile: true, height: function (sc) { return Math.max(200, Math.min(narrow.matches ? Math.round(window.innerHeight * 0.34) : 520, Math.round(((s ? s.ph : 560) + 160) * sc))); } });
		var narrow = window.matchMedia ? window.matchMedia('(max-width: 900px)') : { matches: false };

		var s = null, sel = null, opener = null, widthSet = false, cards = [], bigStop = null;
		var gen = 0, active = 0, queue = [], running = [], io = null, ro = null, down = false;
		var CONC = 3; // Previews render one at a time on the server, so only a few at once.

		function el(tag, cls, text) {
			var n = document.createElement(tag);
			if (cls) { n.className = cls; }
			if (text) { n.textContent = text; }
			return n;
		}
		function svg(markup) { // Static icon markup printed by the page itself.
			var w = document.createElement('span');
			w.innerHTML = markup || '';
			return w.firstChild;
		}
		function previewUrl(slug) {
			return cfg.base + '?lb_preview=' + encodeURIComponent(slug) + '&_lbn=' + cfg.n + '&lb_role=' + encodeURIComponent(data.role);
		}
		function setView(v) { dlg.setAttribute('data-view', v); }

		/* -- thumbnails: a real render at a fixed page width, shown at card width, loaded only when visible -- */
		function scale() {
			qa('.lb-dcard__pv', list).forEach(function (pv) {
				var f = pv.querySelector('iframe'), pw = +pv.getAttribute('data-pw');
				if (f && pw && pv.clientWidth) { f.style.transform = 'scale(' + (pv.clientWidth / pw) + ')'; }
			});
		}
		function pump() {
			while (active < CONC && queue.length) {
				var job = queue.shift();
				if (job.gen === gen) { run(job); }
			}
		}
		function run(job) {
			var pv = job.pv, f = document.createElement('iframe'), done = false, t = 0, stop = null;
			function finish(ok) {
				if (done) { return; }
				done = true;
				window.clearTimeout(t);
				if (stop) { stop(); }
				active--;
				running = running.filter(function (x) { return x !== finish; });
				if (job.gen === gen) {
					pv.setAttribute('data-state', ok ? 'ready' : 'fail');
					if (!ok) {
						var cap = q('.lb-dcard__cap', pv);
						if (cap) { cap.textContent = T.pk_nopv || 'Preview not available'; }
						if (f.parentNode) { f.parentNode.removeChild(f); }
					}
					pump();
				}
			}
			active++;
			running.push(finish);
			f.setAttribute('tabindex', '-1');
			f.setAttribute('aria-hidden', 'true');
			f.setAttribute('scrolling', 'no');
			f.title = '';
			f.style.width = pv.getAttribute('data-pw') + 'px';
			f.style.height = pv.getAttribute('data-ph') + 'px';
			stop = watch(f, job.slug, finish);
			t = window.setTimeout(function () { finish(false); }, 40000);
			pv.setAttribute('data-state', 'loading');
			f.src = job.url; // Set before the frame is added, so its first load is the design.
			pv.appendChild(f);
			scale();
		}
		function want(pv) {
			queue.push({ pv: pv, url: pv.getAttribute('data-url'), slug: pv.getAttribute('data-slug'), gen: gen });
			pump();
		}

		/* -- did the frame draw a design? Same host: look inside (an error page has no design in it). Another host, or a page the
		      site refuses to show in a frame, cannot be looked into: there the preview page's own "ready" message counts, and
		      no message within a few seconds means it failed. Returns a function that stops watching. -- */
		var waiting = [];
		window.addEventListener('message', function (e) {
			var d = e.data;
			if (!d || d.lbPv !== 1 || typeof d.p !== 'string') { return; }
			waiting.slice().forEach(function (w) {
				if (w.f.contentWindow === e.source && w.slug === d.p) { w.heard(); } // Only our own frame, showing this very design.
			});
		});
		function watch(f, slug, done) {
			var w = { f: f, slug: slug }, timer = 0, over = false;
			function stop() {
				over = true;
				window.clearTimeout(timer);
				f.removeEventListener('load', onload);
				waiting = waiting.filter(function (x) { return x !== w; });
			}
			function end(ok) {
				if (over) { return; }
				stop();
				done(ok);
			}
			function onload() {
				var inside = null;
				try {
					if ('about:blank' === f.contentWindow.location.href) { return; } // The empty document a new frame starts with.
					inside = !!f.contentDocument.querySelector('.lb-pv-item'); // Only a rendered design has one.
				} catch (e) { /* another host, or a page the browser would not show: wait for the message */ }
				if (inside !== null) { end(inside); return; }
				window.clearTimeout(timer);
				timer = window.setTimeout(function () { end(false); }, 4000);
			}
			w.heard = function () { end(true); };
			f.addEventListener('load', onload);
			waiting.push(w);
			return stop;
		}

		/* -- the grid -- */
		function card(it) {
			var seen = s.preview && !it.o;
			var li = el('li', 'lb-dcard');
			var pv = el('div', 'lb-dcard__pv');
			// A preview keeps the page's own proportions; a card with none is as tall as it needs to be to hold its caption.
			pv.style.setProperty('--ar', String(seen ? s.pw / s.ph : (s.wide ? 6 : Math.min(s.pw / s.ph, 2.2))));
			pv.setAttribute('data-state', seen ? 'idle' : 'none');
			if (seen) {
				pv.setAttribute('data-pw', s.pw);
				pv.setAttribute('data-ph', s.ph);
				pv.setAttribute('data-url', previewUrl(it.v));
				pv.setAttribute('data-slug', it.v);
			}
			var tile = el('span', 'lb-dcard__tile');
			var ic = svg(s.icon);
			if (ic) { tile.appendChild(ic); }
			tile.appendChild(el('span', 'lb-dcard__cap', seen ? '' : (it.cap || '')));
			pv.appendChild(tile);
			li.appendChild(pv);
			var tick = el('span', 'lb-dcard__tick');
			tick.setAttribute('aria-hidden', 'true');
			var ck = svg(data.check);
			if (ck) { tick.appendChild(ck); }
			li.appendChild(tick);
			var txt = el('div', 'lb-dcard__txt');
			if (it.use) { txt.appendChild(el('span', 'lb-dcard__badge', T.pk_in_use || 'In use')); }
			var b = el('button', 'lb-dcard__pick');
			b.type = 'button';
			b.setAttribute('aria-pressed', 'false');
			b.appendChild(document.createTextNode(it.t));
			if (it.use) { b.appendChild(el('span', 'screen-reader-text', ' (' + (T.pk_in_use || 'In use') + ')')); }
			b.addEventListener('click', function () { choose(it, li, b); });
			txt.appendChild(b);
			if (it.d) { txt.appendChild(el('p', 'lb-dcard__desc', it.d)); }
			li.appendChild(txt);
			cards.push({ it: it, li: li, btn: b });
			return li;
		}
		function build() {
			list.textContent = '';
			cards = [];
			var total = 0;
			s.groups.forEach(function (g, gi) {
				if (!g.items.length) { return; }
				var sec = el('section', 'lb-dgroup');
				var h = el('h3', '', g.label);
				h.id = 'lb-pk-g' + gi;
				sec.setAttribute('aria-labelledby', h.id);
				sec.appendChild(h);
				var ul = el('ul', 'lb-dgrid');
				if (s.wide) { ul.setAttribute('data-wide', ''); }
				g.items.forEach(function (it) { ul.appendChild(card(it)); total++; });
				sec.appendChild(ul);
				list.appendChild(sec);
			});
			if (!total) { list.appendChild(el('p', 'lb-modal__empty', T.pk_empty || 'There are no other designs for this part yet.')); }
			if ('IntersectionObserver' in window) {
				io = new IntersectionObserver(function (entries) {
					entries.forEach(function (en) {
						if (en.isIntersecting && en.target.getAttribute('data-state') === 'idle') {
							io.unobserve(en.target);
							en.target.setAttribute('data-state', 'queued');
							want(en.target);
						}
					});
				}, { root: list, rootMargin: '160px 0px' });
				qa('.lb-dcard__pv[data-state="idle"]', list).forEach(function (pv) { io.observe(pv); });
			} else {
				qa('.lb-dcard__pv[data-state="idle"]', list).forEach(want);
			}
			if (window.ResizeObserver) {
				ro = new ResizeObserver(scale);
				ro.observe(list);
			}
		}
		function buildScopes() {
			scopeList.textContent = '';
			scopeBox.hidden = s.scopes.length < 2;
			if (s.scopes.length < 2) { return; }
			s.scopes.forEach(function (o, i) {
				var id = 'lb-pk-scope-' + o.v;
				var lab = el('label', 'lb-check');
				lab.htmlFor = id;
				var inp = el('input');
				inp.type = 'radio';
				inp.name = 'scope';
				inp.id = id;
				inp.value = o.v;
				inp.checked = 0 === i; // The narrowest choice comes first and is the default.
				inp.addEventListener('change', refresh);
				var span = el('span');
				span.appendChild(el('b', '', o.label));
				span.appendChild(el('small', '', o.detail));
				lab.appendChild(inp);
				lab.appendChild(span);
				scopeList.appendChild(lab);
			});
		}
		function scopeNow() {
			if (s.scopes.length > 1) {
				var on = q('input[name="scope"]:checked', scopeList);
				for (var i = 0; i < s.scopes.length; i++) { if (on && s.scopes[i].v === on.value) { return s.scopes[i]; } }
			}
			return s.scopes[0] || null;
		}

		/* -- choosing -- */
		function refresh() {
			if (!sel) {
				apply.disabled = true;
				apply.textContent = applyLabel;
				apply.title = T.pk_first || 'Pick a design first.';
				return;
			}
			var sc = scopeNow();
			var inUse = sel.use && (!sc || 'default' !== sc.v); // Already in the design map here: only a wider scope changes anything...
			var settled = ['live', 'none', 'theme'].indexOf(s.status) > -1; // ...and only while the site shows it. Otherwise Apply places it.
			var same = inUse && settled, redo = inUse && !settled;
			apply.disabled = same;
			apply.textContent = redo ? (T.pk_apply || 'Apply') : applyLabel;
			apply.title = same ? (T.pk_same || '') : '';
			sentence.textContent = same ? (T.pk_same || '') : (sc ? sc.sentence : '');
			ownEl.textContent = same || !sc ? '' : sc.own;
			repEl.textContent = redo ? (T.pk_redo || '') : (same || sel.use ? '' : s.replaces);
		}
		function choose(it, li, btn) {
			sel = it;
			cards.forEach(function (c) {
				var on = c.li === li;
				if (on) { c.li.setAttribute('data-selected', ''); } else { c.li.removeAttribute('data-selected'); }
				c.btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			});
			designField.value = it.v;
			hint.hidden = true;
			picked.hidden = false;
			nameEl.textContent = it.t;
			descEl.textContent = it.long;
			descEl.hidden = !it.long;
			forEl.textContent = it.for;
			forEl.hidden = !it.for;
			if (narrow.matches) { setView('detail'); }
			if (s.preview && !it.o) {
				nopv.hidden = true;
				stage.el.hidden = false;
				if (!widthSet) {
					widthSet = true;
					stage.setWidth(s.sw <= 420 || narrow.matches ? 390 : 1280);
				}
				var u = previewUrl(it.v);
				if (bigStop) { bigStop(); }
				bigStop = watch(stage.frame, it.v, function (ok) { if (!ok && sel === it) { noPreview(T.pk_nopv_big || ''); } });
				stage.go(u, u);
				stage.fit();
			} else {
				if (bigStop) { bigStop(); bigStop = null; }
				noPreview(s.note || it.cap || '');
			}
			refresh();
			if (narrow.matches) {
				nameEl.setAttribute('tabindex', '-1');
				nameEl.focus();
				detailTop();
			}
		}
		// No picture for this choice (a header, the theme's own, nothing, or a preview that did not load): a calm note instead.
		function noPreview(text) {
			stage.el.hidden = true;
			stage.clear();
			nopv.textContent = '';
			var ic = svg(s.icon);
			if (ic) { nopv.appendChild(ic); }
			nopv.appendChild(el('p', '', text));
			nopv.hidden = false;
		}
		function detailTop() { var d = q('[data-lb-pk-detail]', dlg); if (d) { d.scrollTop = 0; } }

		/* -- opening and closing -- */
		function open(slot, trigger) {
			if (!data.slots[slot]) { return; }
			s = data.slots[slot];
			sel = null;
			opener = trigger || null;
			widthSet = false;
			gen++;
			slotField.value = slot;
			designField.value = '';
			title.textContent = s.title;
			sub.textContent = s.sub;
			picked.hidden = true;
			hint.hidden = false;
			stage.el.hidden = false;
			nopv.hidden = true;
			sentence.textContent = '';
			ownEl.textContent = '';
			repEl.textContent = '';
			build();
			buildScopes();
			refresh();
			setView('list');
			document.documentElement.classList.add('lb-modal-open');
			dlg.showModal();
			list.scrollTop = 0;
			title.focus();
			scale();
		}
		function cleanup() {
			gen++;
			queue = [];
			running.slice().forEach(function (fn) { fn(false); });
			running = [];
			active = 0;
			if (io) { io.disconnect(); io = null; }
			if (ro) { ro.disconnect(); ro = null; }
			list.textContent = '';
			cards = [];
			if (bigStop) { bigStop(); bigStop = null; }
			stage.clear();
			document.documentElement.classList.remove('lb-modal-open');
			if (opener && opener.focus && document.contains(opener)) { opener.focus(); }
			opener = null;
		}

		dlg.addEventListener('close', cleanup);
		qa('[data-lb-pk-close]', dlg).forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
		back.addEventListener('click', function () {
			setView('list');
			var c = cards.filter(function (x) { return x.it === sel; })[0];
			if (c) { c.btn.focus(); }
		});
		// A click on the dim area outside the box closes it (a drag that ends there does not).
		dlg.addEventListener('mousedown', function (e) { down = e.target === dlg; });
		dlg.addEventListener('click', function (e) {
			if (e.target === dlg && down) { dlg.close(); }
			down = false;
		});
		// Focus stays inside: Tab from the last control goes to the first and the other way round.
		dlg.addEventListener('keydown', function (e) {
			if (e.key !== 'Tab') { return; }
			var all = qa('button, a[href], input, select, textarea, summary, [tabindex]', dlg).filter(function (n) {
				return !n.disabled && n.getAttribute('tabindex') !== '-1' && n.getClientRects().length > 0;
			});
			if (!all.length) { return; }
			var first = all[0], last = all[all.length - 1];
			if (e.shiftKey && (document.activeElement === first || document.activeElement === title)) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		});
		qa('[data-w]', stage.el).forEach(function (b) { b.addEventListener('click', function () { widthSet = true; }); });

		// With the dialog available the cards open it; the plain forms stay for browsers without it.
		qa('[data-lb-pick]').forEach(function (b) {
			b.hidden = false;
			b.addEventListener('click', function () { open(b.getAttribute('data-lb-pick'), b); });
		});
		qa('.lb-change').forEach(function (d) { d.hidden = true; });

		return { fit: function () { if (dlg.open) { stage.fit(); scale(); } } };
	}
	var picker = Picker();

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
			if (picker) { picker.fit(); }
		});
	});

	show(cfg.tab === 'library' ? 'library' : 'placed', false);
})();
