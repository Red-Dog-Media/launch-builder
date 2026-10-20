// Checks the names and screens of the Design hub's Kadence objects on a running test site (WordPress Playground or any site
// with a dev MCP token): Elements are named for the piece without the plugin name, the Elements list has a Launch Builder
// view, a "Shows on" column and a "Change design" row action, the edit screen carries the note, and a name a person
// gave an Element survives placing its design again.
//
//   node tools/dev/check-names.mjs            site at LB_URL (default http://127.0.0.1:9432), token LB_TOKEN (default lbm_devlocal_only_token)
//
// It places the Home services look (set-design) and renames one Element, so run it on a throwaway site with a brief built.
const U = (process.env.LB_URL || 'http://127.0.0.1:9432').replace(/\/$/, '');
const T = process.env.LB_TOKEN || 'lbm_devlocal_only_token';
let pass = 0, fail = 0, jar = '';
const row = (label, ok, detail = '') => { ok ? pass++ : fail++; console.log((ok ? 'PASS' : 'FAIL') + '  ' + label + (ok || !detail ? '' : '   ' + String(typeof detail === 'string' ? detail : JSON.stringify(detail)).slice(0, 300))); };
const call = async (name, args = {}) => {
	const r = await fetch(U + '/mcp', { method: 'POST', headers: { 'content-type': 'application/json', Authorization: 'Bearer ' + T, Cookie: jar }, body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name, arguments: args } }) });
	const j = await r.json();
	const t = j.result?.content?.[0]?.text ?? '';
	try { return JSON.parse(t); } catch (e) { return { error: t || JSON.stringify(j) }; }
};
const admin = async (p) => (await fetch(U + '/wp-admin/' + p, { headers: { Cookie: jar } })).text();
const rowOf = (html, id) => (html.match(new RegExp('<tr id="post-' + id + '"[\\s\\S]*?</tr>')) || [''])[0];
const titles = (html) => [...html.matchAll(/class="row-title"[^>]*>([^<]*)</g)].map((m) => m[1]);

(async () => {
	jar = (await fetch(U + '/', { redirect: 'manual' })).headers.getSetCookie().map((c) => c.split(';')[0]).join('; ');
	const set = await call('set-design', { look: 'home-services' });
	const info = await call('get-designs');
	const pieces = info.pieces || {};
	const elements = Object.entries(pieces).filter(([, p]) => 'Kadence Element' === p.placed_as && p.id).map(([piece, p]) => ({ piece, id: p.id }));
	row('set-design placed the Elements', elements.length >= 5, Object.keys(set));

	const list = await admin('edit.php?post_type=kadence_element&lb_managed=1');
	const names = titles(list);
	const labels = elements.map((e) => (pieces[e.piece] || {}).label);
	row('every Element is named for its piece, with no plugin name in front', labels.every((l) => names.includes(l)) && !names.some((n) => /^Launch Builder/i.test(n)), { names, labels });
	row('the Elements list has a Launch Builder (N) view', new RegExp('lb_managed=1[^>]*>Launch Builder <span class="count">\\(' + elements.length + '\\)').test(list), (list.match(/subsubsub[\s\S]{0,600}/) || [''])[0].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
	row('...and a "Shows on" column', /id=["']lb_shows_on["']/.test(list) && />Shows on</.test(list));
	const first = elements[0];
	const r0 = rowOf(list, first.id);
	row('a placed Element row says where it shows and has a Change design link to its place in the Design hub', /column-lb_shows_on/.test(r0) && new RegExp('page=launch-builder-patterns#piece-' + first.piece).test(r0), r0.slice(0, 300));
	const all = await admin('edit.php?post_type=kadence_element');
	row('the Launch Builder view leaves out nothing it placed, and the All view lists the same Elements', elements.every((e) => all.includes('post-' + e.id)));

	const post = await admin('post.php?post=' + first.id + '&action=edit');
	row('the edit screen carries the note (what it is, where it shows, how to leave a page out)', /lb-element-note/.test(post) && /Hide On/.test(post), '');

	const target = elements.find((e) => 'hero-service' === e.piece) || first;
	const mine = 'My ' + (pieces[target.piece] || {}).label;
	await call('rest-request', { method: 'POST', route: '/wp/v2/kadence_element/' + target.id, params: { title: mine } });
	const a = titles(await admin('edit.php?post_type=kadence_element&lb_managed=1'));
	row('a name a person gives an Element shows in the list', a.includes(mine), a);
	for (let i = 0; i < 2; i++) await call('set-design', { pieces: { [target.piece]: 'home-services' } });
	const b = titles(await admin('edit.php?post_type=kadence_element&lb_managed=1'));
	row('...and survives placing its design again, twice', b.includes(mine) && !b.includes((pieces[target.piece] || {}).label), b);
	await call('rest-request', { method: 'POST', route: '/wp/v2/kadence_element/' + target.id, params: { title: (pieces[target.piece] || {}).label } });

	const heads = { kadence_header: 'Site header', kadence_navigation: 'Main menu' };
	for (const [type, want] of Object.entries(heads)) row('the ' + type.replace('kadence_', '') + ' is named "' + want + '"', titles(await admin('edit.php?post_type=' + type)).includes(want), titles(await admin('edit.php?post_type=' + type)));
	const q = titles(await admin('edit.php?post_type=kadence_query'));
	row('Query Loop lists are named for their piece ("Sub-service list"), their cards "... card"', q.length > 0 && !q.some((n) => /^Launch Builder/i.test(n) || /^Design /i.test(n)), q);
	const c = titles(await admin('edit.php?post_type=kadence_query_card'));
	row('...cards', c.length > 0 && c.every((n) => / card( \d+)?$/.test(n)), c);
	console.log(`${pass} PASS, ${fail} FAIL (${U})`);
	process.exit(fail ? 1 : 0);
})().catch((e) => { console.log('FAILED', e.stack || e.message); process.exit(1); });
