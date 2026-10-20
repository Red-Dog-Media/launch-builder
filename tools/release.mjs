#!/usr/bin/env node
// Release engineering for Launch Builder. Nothing here ever pushes: publishing is `git push` of the branch and the tag,
// done by a person, after which .github/workflows/release.yml builds the zip and publishes the GitHub release.
//
//   node tools/release.mjs <version> [--dry-run] [--date YYYY-MM-DD] [--skip-check]
//       Bump the version everywhere (plugin header Version, LB_VERSION, readme.txt Stable tag), move CHANGELOG.md's
//       [Unreleased] notes under "## [<version>] - <date>" (today unless --date), refresh readme.txt's == Changelog ==
//       from CHANGELOG.md, then commit ("Release <version>") and create the annotated tag v<version>. Refuses on a dirty
//       tree, an existing tag, a version lower than the current one, an empty [Unreleased] section, or (for 1.0.0 and
//       later stable versions, unless --skip-check) shipping files that fail `check`. --dry-run prints the plan only.
//       <version> may equal the current version once (its first tag): when CHANGELOG.md already has its section, only
//       the tag is created (no file changes, no commit).
//   node tools/release.mjs verify [<tag>]
//       Header Version == LB_VERSION == readme Stable tag (and the requirement headers agree); with a tag (v1.2.3 or
//       refs/tags/v1.2.3) also tag == v + Version and a non-empty CHANGELOG section for it. Used by CI.
//   node tools/release.mjs notes <tag|version>
//       The release notes: the CHANGELOG section plus a hidden line the updater reads for the new version's requirements
//       (<!-- launch-builder: {"requires":…,"requires_php":…,"tested":…} -->).
//   node tools/release.mjs build [--ref <ref>] [--out <dir>]
//       launch-builder.zip from `git archive` (top folder launch-builder/, .gitattributes export-ignore honoured, so only
//       committed files and attributes count), then checks the listing: required files present, development files absent.
//   node tools/release.mjs check [--ref <ref>] [--strict]
//       Scans every text file that ships in the zip for AI vendor names, absolute personal paths and the dev MCP token.
//       Findings fail for stable versions >= 1.0.0 (or with --strict) and are warnings before that.
//
// Exit code 1 on any refusal or failed check.

import fs from 'fs';
import path from 'path';
import url from 'url';
import zlib from 'zlib';
import { execFileSync } from 'child_process';

const ROOT = path.resolve(path.dirname(url.fileURLToPath(import.meta.url)), '..');
const REPO_URL = 'https://github.com/Red-Dog-Media/launch-builder';
const FILES = { main: 'launch-builder.php', readme: 'readme.txt', changelog: 'CHANGELOG.md' };
const PREFIX = 'launch-builder/';
const ASSET = 'launch-builder.zip';
const REQUIRED = ['launch-builder.php', 'uninstall.php', 'readme.txt', 'CHANGELOG.md', 'LICENSE', 'includes/classmap.php', 'includes/core/class-plugin.php', 'includes/core/class-updater.php'];
const FORBIDDEN = [/^tools\//, /^docs\//, /^\.github\//, /^\.playground\//, /^screenshots\//, /^tests\//, /^README\.md$/i, /^\.git(attributes|ignore)$/, /\.cmd$/i, /\.mjs$/i];
// AI vendor names that must not appear in public files (base64, so this file does not contain them itself).
export const VENDOR_RE = new RegExp(`\\b(${['Y2xhdWRl', 'YW50aHJvcGlj'].map((b) => Buffer.from(b, 'base64').toString('utf8')).join('|')})\\b`, 'i');
const HYGIENE = [
	{ label: 'AI vendor name (say "AI assistant" or "MCP client")', re: VENDOR_RE },
	{ label: 'absolute personal path', re: /\b[A-Za-z]:(?:\\\\|\\|\/)+Users(?:\\\\|\\|\/)+[^\\/\s'"`]+|(?:^|[\s'"`(=])\/(?:home|Users)\/[A-Za-z0-9._-]+\//i },
	{ label: 'dev MCP token', re: new RegExp('lbm_' + 'devlocal_only_token') },
];

const say = (...a) => console.log(...a);
const die = (msg) => { console.error('release: ' + msg); process.exit(1); };

/* ------------------------------------------------------------------ helpers */

function git(args, { buffer = false, allowFail = false } = {}) {
	try {
		return execFileSync('git', ['-C', ROOT, ...args], { encoding: buffer ? 'buffer' : 'utf8', maxBuffer: 512 * 1024 * 1024, stdio: ['ignore', 'pipe', 'pipe'] });
	} catch (e) {
		if (allowFail) return null;
		die(`git ${args.join(' ')} failed: ${String(e.stderr || e.message).trim()}`);
	}
}

const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');

const SEMVER = /^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/i;

// 'v1.2.3' -> '1.2.3' (build metadata dropped); null when not SemVer 2.0. Same rules as Updater::normalize().
export function normalize(v) {
	const m = SEMVER.exec(String(v || '').trim());
	return m ? `${m[1]}.${m[2]}.${m[3]}${m[4] ? '-' + m[4] : ''}` : null;
}

// SemVer 2.0 precedence: -1, 0, 1 (null if either is not a version). Same rules as Updater::compare().
export function compare(a, b) {
	a = normalize(a); b = normalize(b);
	if (!a || !b) return null;
	const [ac, ap = ''] = a.split(/-(.*)/s);
	const [bc, bp = ''] = b.split(/-(.*)/s);
	const x = ac.split('.').map(Number), y = bc.split('.').map(Number);
	for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] < y[i] ? -1 : 1;
	if (ap === bp) return 0;
	if (!ap || !bp) return ap ? -1 : 1;
	const p = ap.split('.'), q = bp.split('.');
	for (let i = 0; i < Math.max(p.length, q.length); i++) {
		if (p[i] === undefined || q[i] === undefined) return p[i] === undefined ? -1 : 1;
		const pn = /^\d+$/.test(p[i]), qn = /^\d+$/.test(q[i]);
		if (pn && qn) { if (Number(p[i]) !== Number(q[i])) return Number(p[i]) < Number(q[i]) ? -1 : 1; continue; }
		if (pn !== qn) return pn ? -1 : 1;
		if (p[i] !== q[i]) return p[i] < q[i] ? -1 : 1;
	}
	return 0;
}

const isStable = (v) => { const n = normalize(v); return !!n && !n.includes('-') && Number(n.split('.')[0]) >= 1; };

function header(text, name) {
	const m = new RegExp(`^[ \\t/*#@]*${name.replace(/ /g, '\\s+')}:[ \\t]*(.*?)[ \\t]*$`, 'mi').exec(text);
	return m ? m[1] : '';
}

function state() {
	const main = read(FILES.main), readme = read(FILES.readme), changelog = read(FILES.changelog);
	const constant = (/define\(\s*'LB_VERSION',\s*'([^']*)'\s*\)/.exec(main) || [])[1] || '';
	return {
		main, readme, changelog, constant,
		version: header(main, 'Version'),
		stable: header(readme, 'Stable tag'),
		requires: header(main, 'Requires at least'),
		requiresPhp: header(main, 'Requires PHP'),
		readmeRequires: header(readme, 'Requires at least'),
		readmeRequiresPhp: header(readme, 'Requires PHP'),
		tested: header(readme, 'Tested up to'),
		updateUri: header(main, 'Update URI'),
	};
}

/* ------------------------------------------------------------------ CHANGELOG */

// Sections in order: [{ name: 'Unreleased' | '1.2.3', date, start, end, body }] (end = index of the next section,
// link reference or EOF, in lines).
function sections(text) {
	const lines = text.split('\n');
	const out = [];
	lines.forEach((l, i) => {
		const m = /^## \[([^\]]+)\](?:\s+-\s+(\d{4}-\d{2}-\d{2}))?\s*$/.exec(l);
		if (m) out.push({ name: m[1], date: m[2] || '', start: i });
	});
	const refs = lines.findIndex((l) => /^\[[^\]]+\]:\s+\S/.test(l));
	out.forEach((s, k) => {
		s.end = k + 1 < out.length ? out[k + 1].start : (refs > s.start ? refs : lines.length);
		s.body = lines.slice(s.start + 1, s.end).join('\n').trim();
	});
	return out;
}

const tidy = (t) => t.replace(/\n{3,}/g, '\n\n').replace(/\s+$/, '') + '\n';

function releaseChangelog(text, version, date) {
	const lines = text.split('\n');
	const secs = sections(text);
	const un = secs.find((s) => /^unreleased$/i.test(s.name));
	if (!un) die(`${FILES.changelog} has no "## [Unreleased]" section`);
	if (!un.body) die(`nothing to release: "## [Unreleased]" in ${FILES.changelog} is empty`);
	if (secs.some((s) => s.name === version)) die(`${FILES.changelog} already has a section for ${version}`);
	const prev = secs.find((s) => s !== un && normalize(s.name));
	const out = [...lines.slice(0, un.start), '## [Unreleased]', '', `## [${version}] - ${date}`, '', un.body, '', ...lines.slice(un.end)];
	let t = out.join('\n');
	const refUn = `[Unreleased]: ${REPO_URL}/compare/v${version}...HEAD`;
	const refNew = `[${version}]: ${prev ? `${REPO_URL}/compare/v${prev.name}...v${version}` : `${REPO_URL}/releases/tag/v${version}`}`;
	if (/^\[Unreleased\]:.*$/m.test(t)) t = t.replace(/^\[Unreleased\]:.*$/m, `${refUn}\n${refNew}`);
	else t = tidy(t) + `\n${refUn}\n${refNew}\n`;
	return tidy(t);
}

// readme.txt's == Changelog ==: the newest non-empty CHANGELOG sections (at most 5) in readme format.
export function readmeChangelog(changelogText, max = 5) {
	const parts = [];
	for (const s of sections(changelogText)) {
		if (!s.body || parts.length >= max) continue;
		const body = s.body.split('\n').map((l) => {
			const h = /^#{3,6}\s+(.+?)\s*$/.exec(l);
			if (h) return `**${h[1]}**\n`;
			const li = /^\s*[-*+]\s+(.*)$/.exec(l);
			return li ? `* ${li[1]}` : l;
		}).join('\n');
		parts.push(`= ${s.name}${s.date ? ' - ' + s.date : ''} =\n\n${body.replace(/\n{3,}/g, '\n\n').trim()}`);
	}
	return parts.join('\n\n'); // The updater's details modal adds the "All releases on GitHub" link itself.
}

function withReadmeChangelog(readme, changelogText) {
	const m = /^== Changelog ==[ \t]*$/m.exec(readme);
	if (!m) die(`${FILES.readme} has no "== Changelog ==" section`);
	const after = readme.slice(m.index + m[0].length);
	const next = /^==\s*[^=].*==[ \t]*$/m.exec(after);
	const rest = next ? '\n\n' + after.slice(next.index) : '';
	return tidy(readme.slice(0, m.index) + '== Changelog ==\n\n' + readmeChangelog(changelogText) + rest);
}

/* ------------------------------------------------------------------ zip */

export function zipEntries(buf) {
	let eocd = -1;
	for (let i = buf.length - 22; i >= Math.max(0, buf.length - 22 - 65535); i--) if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
	if (eocd < 0) throw new Error('not a zip file');
	const count = buf.readUInt16LE(eocd + 10);
	const out = [];
	for (let p = buf.readUInt32LE(eocd + 16), k = 0; k < count; k++) {
		if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error('bad zip central directory');
		const nlen = buf.readUInt16LE(p + 28), xlen = buf.readUInt16LE(p + 30), clen = buf.readUInt16LE(p + 32);
		out.push({ name: buf.toString('utf8', p + 46, p + 46 + nlen), method: buf.readUInt16LE(p + 10), csize: buf.readUInt32LE(p + 20), size: buf.readUInt32LE(p + 24), local: buf.readUInt32LE(p + 42) });
		p += 46 + nlen + xlen + clen;
	}
	return out;
}

export function zipRead(buf, e) {
	if (buf.readUInt32LE(e.local) !== 0x04034b50) throw new Error(`bad zip local header for ${e.name}`);
	const start = e.local + 30 + buf.readUInt16LE(e.local + 26) + buf.readUInt16LE(e.local + 28);
	const data = buf.subarray(start, start + e.csize);
	if (e.method === 0) return data;
	if (e.method === 8) return zlib.inflateRawSync(data);
	throw new Error(`unsupported zip compression ${e.method} for ${e.name}`);
}

const archive = (ref) => git(['archive', '--format=zip', `--prefix=${PREFIX}`, ref], { buffer: true });

// Problems with a zip listing (names relative to the top folder): [] when it is a valid release zip.
export function listingProblems(entries) {
	const problems = [];
	const names = [];
	for (const e of entries) {
		if (!e.name.startsWith(PREFIX)) { problems.push(`outside ${PREFIX}: ${e.name}`); continue; }
		const rel = e.name.slice(PREFIX.length);
		if (!rel || rel.endsWith('/')) continue;
		names.push(rel);
		if (FORBIDDEN.some((re) => re.test(rel))) problems.push(`development file in the zip: ${rel}`);
	}
	for (const f of REQUIRED) if (!names.includes(f)) problems.push(`missing from the zip: ${f}`);
	return problems;
}

/* ------------------------------------------------------------------ commands */

function verify(tagArg) {
	const s = state();
	const errors = verifyErrors(s, tagArg);
	if (errors.length) die('verify failed:\n  ' + errors.join('\n  '));
	say(`verify: OK (version ${s.version}${tagArg ? ', tag ' + String(tagArg).replace(/^refs\/tags\//, '') : ''}; LB_VERSION, readme Stable tag and requirements agree)`);
}

// What `verify [<tag>]` checks, as a list of problems ([] when everything agrees).
function verifyErrors(s, tagArg) {
	const errors = [];
	if (!normalize(s.version)) errors.push(`plugin header Version "${s.version}" is not SemVer`);
	if (s.constant !== s.version) errors.push(`LB_VERSION "${s.constant}" != header Version "${s.version}"`);
	if (s.stable !== s.version) errors.push(`${FILES.readme} Stable tag "${s.stable}" != header Version "${s.version}"`);
	if (s.readmeRequires !== s.requires) errors.push(`${FILES.readme} Requires at least "${s.readmeRequires}" != header "${s.requires}"`);
	if (s.readmeRequiresPhp !== s.requiresPhp) errors.push(`${FILES.readme} Requires PHP "${s.readmeRequiresPhp}" != header "${s.requiresPhp}"`);
	if (s.updateUri.replace(/\/$/, '') !== REPO_URL) errors.push(`header Update URI "${s.updateUri}" != ${REPO_URL}`);
	if (tagArg) {
		const tag = String(tagArg).replace(/^refs\/tags\//, '');
		if (tag !== 'v' + s.version) errors.push(`tag "${tag}" != "v${s.version}" (header Version)`);
		const sec = sections(s.changelog).find((x) => x.name === s.version);
		if (!sec || !sec.body) errors.push(`${FILES.changelog} has no notes under "## [${s.version}]"`);
	}
	return errors;
}

export function notesFor(version, s = state()) {
	const v = normalize(version);
	if (!v) die(`not a version: ${version}`);
	const sec = sections(s.changelog).find((x) => x.name === v);
	if (!sec || !sec.body) die(`${FILES.changelog} has no notes under "## [${v}]"`);
	const meta = { requires: s.requires, requires_php: s.requiresPhp, tested: s.tested };
	return `${sec.body}\n\n<!-- launch-builder: ${JSON.stringify(meta)} -->\n`;
}

function build(ref, outDir) {
	const out = path.resolve(ROOT, outDir);
	fs.mkdirSync(out, { recursive: true });
	const file = path.join(out, ASSET);
	const buf = archive(ref);
	const entries = zipEntries(buf);
	const problems = listingProblems(entries);
	if (problems.length) die(`the zip of ${ref} is not a release zip:\n  ` + problems.join('\n  '));
	fs.writeFileSync(file, buf);
	const files = entries.filter((e) => !e.name.endsWith('/'));
	const tops = [...new Set(files.map((e) => e.name.slice(PREFIX.length).split('/')[0]))].sort();
	say(`build: ${file} (${(buf.length / 1024).toFixed(0)} KB, ${files.length} files from ${ref}): ${tops.join(', ')}`);
	return file;
}

// Hygiene findings in the files that ship (the zip of `ref`): [{ file, line, label, text }].
export function hygiene(ref) {
	const buf = archive(ref);
	const found = [];
	for (const e of zipEntries(buf)) {
		if (e.name.endsWith('/')) continue;
		const data = zipRead(buf, e);
		if (data.subarray(0, 8000).includes(0)) continue; // Binary.
		data.toString('utf8').split('\n').forEach((line, i) => {
			for (const h of HYGIENE) if (h.re.test(line)) found.push({ file: e.name.slice(PREFIX.length), line: i + 1, label: h.label, text: line.trim().slice(0, 140) });
		});
	}
	return found;
}

function check(ref, strict) {
	const found = hygiene(ref);
	const version = header(git(['show', `${ref}:${FILES.main}`]), 'Version');
	const fatal = strict || isStable(version);
	if (!found.length) return say(`check: OK (no findings in the files that ship from ${ref})`);
	const files = new Set(found.map((f) => f.file));
	const lines = found.slice(0, 60).map((f) => `  ${f.file}:${f.line}  ${f.label}: ${f.text}`);
	const msg = `check: ${found.length} finding(s) in ${files.size} shipping file(s) of ${ref}:\n${lines.join('\n')}${found.length > 60 ? `\n  ... and ${found.length - 60} more` : ''}`;
	if (fatal) die(msg + `\n(fatal for stable version ${version})`);
	say(msg + `\n(warning only: version ${version} is a pre-release; these fail from 1.0.0)`);
}

function today() {
	const d = new Date();
	return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function bump(versionArg, { dryRun, date, skipCheck }) {
	const version = normalize(versionArg);
	if (!version) die(`not a SemVer version: ${versionArg} (e.g. 1.0.0 or 1.1.0-rc.1)`);
	if (date && !/^\d{4}-\d{2}-\d{2}$/.test(date)) die('--date must be YYYY-MM-DD');
	date = date || today();
	if (!git(['rev-parse', '--is-inside-work-tree'], { allowFail: true })) die(`${ROOT} is not a git checkout`);
	const dirty = git(['status', '--porcelain', '--untracked-files=no']).trim();
	if (dirty) die('the working tree has uncommitted changes; commit or stash them first:\n' + dirty);
	const s = state();
	if (s.constant !== s.version || s.stable !== s.version) die(`versions disagree (header ${s.version}, LB_VERSION ${s.constant}, Stable tag ${s.stable}); fix that first (node tools/release.mjs verify)`);
	const tagExists = (t) => null !== git(['rev-parse', '-q', '--verify', `refs/tags/${t}`], { allowFail: true });
	const cmp = compare(version, s.version);
	if (cmp === null || cmp < 0) die(`${version} is lower than the current version ${s.version}`);
	if (cmp === 0 && tagExists('v' + s.version)) die(`${version} is already released (tag v${version}); pick a higher version`);
	if (tagExists('v' + version)) die(`tag v${version} already exists`);

	const findings = hygiene('HEAD');
	if (findings.length) {
		const where = [...new Set(findings.map((f) => f.file))].join(', ');
		if (isStable(version) && !skipCheck) die(`${findings.length} hygiene finding(s) in shipping files (${where}); see node tools/release.mjs check (or pass --skip-check)`);
		say(`warning: ${findings.length} hygiene finding(s) in shipping files (${where})${isStable(version) ? ' (--skip-check)' : ''}`);
	}

	// The current version with its CHANGELOG section already committed (notes moved by hand, or a release commit mirrored
	// into another checkout): only the tag is missing, so nothing is written or committed.
	const done = cmp === 0 && sections(s.changelog).find((x) => x.name === version && x.body);
	if (done) {
		const problems = verifyErrors(s, 'v' + version);
		if (problems.length) die('verify failed:\n  ' + problems.join('\n  '));
		const step = `git tag -a v${version} -m "Launch Builder ${version}" on HEAD (version strings and the "## [${version}]" notes are already committed; no file changes)`;
		if (dryRun) {
			say(`release ${version} (dry run, nothing written):\n  ${step}\n\nRelease notes:\n${notesFor(version, s)}`);
			return;
		}
		git(['tag', '-a', `v${version}`, '-m', `Launch Builder ${version}`]);
		say(`release ${version}: annotated tag v${version} created on ${git(['rev-parse', '--short', 'HEAD']).trim()} (no commit needed). Nothing was pushed.\nTo publish, push the tag yourself:\n  git push origin v${version}`);
		return;
	}

	const changelog = releaseChangelog(s.changelog, version, date);
	const main = s.main
		.replace(/^([ \t/*#@]*Version:[ \t]*)\S+/m, `$1${version}`)
		.replace(/(define\(\s*'LB_VERSION',\s*')[^']*('\s*\))/, `$1${version}$2`);
	const readme = withReadmeChangelog(s.readme.replace(/^(Stable tag:[ \t]*)\S+/mi, `$1${version}`), changelog);
	const after = { ...s, main, readme, changelog, version, constant: version, stable: version };
	if (header(main, 'Version') !== version || !main.includes(`define( 'LB_VERSION', '${version}' )`) || header(readme, 'Stable tag') !== version) die('could not update the version strings (unexpected file format)');
	const notes = notesFor(version, after);

	const plan = [
		`${FILES.main}: Version ${s.version} -> ${version}; LB_VERSION ${s.constant} -> ${version}`,
		`${FILES.readme}: Stable tag ${s.stable} -> ${version}; == Changelog == refreshed from ${FILES.changelog}`,
		`${FILES.changelog}: [Unreleased] -> [${version}] - ${date}; new empty [Unreleased]; link references`,
		`git commit -m "Release ${version}"; git tag -a v${version} -m "Launch Builder ${version}"`,
	];
	if (dryRun) {
		say(`release ${version} (dry run, nothing written):\n  ${plan.join('\n  ')}\n\nRelease notes:\n${notes}`);
		return;
	}
	fs.writeFileSync(path.join(ROOT, FILES.main), main);
	fs.writeFileSync(path.join(ROOT, FILES.readme), readme);
	fs.writeFileSync(path.join(ROOT, FILES.changelog), changelog);
	git(['add', '--', FILES.main, FILES.readme, FILES.changelog]);
	git(['commit', '-q', '-m', `Release ${version}`]);
	git(['tag', '-a', `v${version}`, '-m', `Launch Builder ${version}`]);
	const sha = git(['rev-parse', '--short', 'HEAD']).trim();
	const branch = (git(['rev-parse', '--abbrev-ref', 'HEAD']).trim()) || 'main';
	say(`release ${version}: commit ${sha} and annotated tag v${version} created. Nothing was pushed.\n  ${plan.join('\n  ')}\n\nTo publish, review the commit, then push it and the tag yourself:\n  git push origin ${branch}\n  git push origin v${version}\nGitHub Actions (.github/workflows/release.yml) then builds ${ASSET} and publishes the release.`);
}

/* ------------------------------------------------------------------ main */

function parse(argv) {
	const out = { _: [] };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		if (['--dry-run', '--skip-check', '--strict'].includes(a)) out[a.slice(2)] = true;
		else if (['--date', '--ref', '--out'].includes(a)) {
			if (undefined === argv[i + 1]) die(`${a} needs a value`);
			out[a.slice(2)] = argv[++i];
		} else if (a.startsWith('--')) die(`unknown option ${a}`);
		else out._.push(a);
	}
	return out;
}

const same = (a, b) => (process.platform === 'win32' ? a.toLowerCase() === b.toLowerCase() : a === b);
const isMain = !!process.argv[1] && same(path.resolve(process.argv[1]), url.fileURLToPath(import.meta.url));
if (isMain) {
	const args = parse(process.argv.slice(2));
	const [cmd, arg] = args._;
	if (cmd === 'verify') verify(arg);
	else if (cmd === 'notes') process.stdout.write(notesFor(arg || ''));
	else if (cmd === 'build') build(args.ref || 'HEAD', args.out || 'dist');
	else if (cmd === 'check') check(args.ref || 'HEAD', !!args.strict);
	else if (cmd && normalize(cmd)) bump(cmd, { dryRun: !!args['dry-run'], date: args.date, skipCheck: !!args['skip-check'] });
	else die('usage: node tools/release.mjs <version> [--dry-run] [--date YYYY-MM-DD] [--skip-check] | verify [<tag>] | notes <tag|version> | build [--ref <ref>] [--out <dir>] | check [--ref <ref>] [--strict]');
}
