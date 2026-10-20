<?php
// Usage: php refscan.php <plugin dir>. Lists Class::member references whose member does not exist in the class file.
$dir = $argv[1];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/includes'));
foreach ($it as $f) if (substr($f, -4) === '.php') $files[] = (string) $f;
$files[] = $dir . '/uninstall.php';
$files[] = $dir . '/launch-builder.php';
// Map class/trait => members.
$members = [];
$uses = [];
foreach ($files as $f) {
	if (!is_file($f)) continue;
	$src = file_get_contents($f);
	if (preg_match('/^(?:final\s+|abstract\s+)?(class|trait)\s+(\w+)/m', $src, $m)) {
		$cls = $m[2];
		preg_match_all('/function\s+&?(\w+)\s*\(/', $src, $fm);
		preg_match_all('/\bconst\s+(\w+)\s*=/', $src, $cm);
		preg_match_all('/(?:public|private|protected)\s+static\s+(?:\??\w+\s+)?\$(\w+)/', $src, $pm);
		$members[$cls] = array_merge($members[$cls] ?? [], $fm[1], $cm[1], array_map(fn($x) => '$' . $x, $pm[1]), ['class']);
		preg_match_all('/^\s*use\s+(\w+)\s*;/m', $src, $um);
		$uses[$cls] = $um[1];
	}
}
// Merge trait members into classes.
foreach ($uses as $cls => $traits) foreach ($traits as $t) if (isset($members[$t])) $members[$cls] = array_merge($members[$cls], $members[$t]);
foreach ($files as $f) {
	if (!is_file($f)) continue;
	$src = file_get_contents($f);
	preg_match_all('/\b([A-Z]\w+)::(\$?\w+)/', $src, $m, PREG_OFFSET_CAPTURE);
	foreach ($m[1] as $i => $c) {
		$cls = $c[0]; $mem = $m[2][$i][0];
		if (in_array($cls, ['self', 'static', 'parent'], true)) continue;
		$line = substr_count(substr($src, 0, $c[1]), "\n") + 1;
		if (!isset($members[$cls])) {
			if (!class_exists($cls) && preg_match('/^(WP_|RWMB|MBB|Kadence|Rank|PHP|WP|MB)/', $cls) === 0) echo str_replace($dir . '/', '', $f) . ":$line missing class $cls::$mem\n";
			continue;
		}
		if (!in_array($mem, $members[$cls], true)) echo str_replace($dir . '/', '', $f) . ":$line $cls::$mem\n";
	}
}
