<?php

// Runs-and-breaks report (read-only — assigns no cluster_id).
//
// Sort the rows, then for each row compute its similarity to the PREVIOUS row.
// LCS (abbreviation-aware token longest-common-subsequence) is the primary
// measure and drives the break marker; Prefix (shared leading chars) is shown as
// an advisory column. A "break" is where LCS drops below --lcs.
//
//   php runs.php [options]
//     --sort=key_a|key_b|normalized   adjacency order      (default key_a)
//     --start=STR                     seek to first sort value >= STR
//     --limit=N                       rows to print        (default 150)
//     --lcs=F                         break threshold (LCS below => break, default 0.50)
//     --out=FILE                      write the FULL report to FILE (no limit)

require_once(__DIR__ . '/clean.php');
require_once(__DIR__ . '/measures.php');

$opt = getopt('', array('sort::', 'start::', 'limit::', 'lcs::', 'out::', 'db::'));
$sort   = isset($opt['sort'])  ? $opt['sort']  : 'key_a';
$start  = isset($opt['start']) ? $opt['start'] : null;
$limit  = isset($opt['limit']) ? (int)$opt['limit'] : 150;
$tLcs   = isset($opt['lcs'])   ? (float)$opt['lcs'] : 0.50;
$out    = isset($opt['out'])   ? $opt['out'] : null;
$dbPath = isset($opt['db'])    ? $opt['db']  : __DIR__ . '/containers.db';

$allowed = array('key_a', 'key_b', 'normalized');
if (!in_array($sort, $allowed)) {
	fwrite(STDERR, "--sort must be one of: " . implode(', ', $allowed) . "\n");
	exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "SELECT raw, normalized, tokens, series, $sort AS sk FROM containers WHERE $sort != ''";
if ($start !== null) {
	$sql .= " AND $sort >= " . $db->quote($start);
}
$sql .= " ORDER BY $sort, normalized";

$stmt = $db->query($sql);

$fh = STDOUT;
if ($out !== null) {
	$fh = fopen($out, 'w');
	$limit = PHP_INT_MAX;
}

fwrite($fh, sprintf("# sort=%s  break: LCS < %.2f\n", $sort, $tLcs));
fwrite($fh, sprintf("%-4s %5s %5s  %s\n", 'cut', 'lcs', 'pfx', 'normalized  (raw)'));
fwrite($fh, str_repeat('-', 100) . "\n");

$prev = null;
$count = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
	$tok = $row['tokens'] === '' ? array() : explode(' ', $row['tokens']);

	if ($prev === null) {
		$lcs = $pfx = null;
		$cut = '';
	} else {
		$lcs = sim_lcs($prev['tok'], $tok);
		$pfx = sim_prefix($prev['normalized'], $row['normalized']);
		$cut = $lcs < $tLcs ? '>>' : '';
	}

	$label = $row['normalized'];
	if ($row['series'] !== '') $label .= '  [' . $row['series'] . ']';
	$label = mb_strimwidth($label, 0, 64);
	$rawshort = mb_strimwidth($row['raw'], 0, 40);

	fwrite($fh, sprintf("%-4s %5s %5s  %-64s  (%s)\n",
		$cut,
		$lcs === null ? '' : number_format($lcs, 2),
		$pfx === null ? '' : number_format($pfx, 2),
		$label, $rawshort));

	$prev = array('tok' => $tok, 'normalized' => $row['normalized']);
	if (++$count >= $limit) break;
}

if ($out !== null) {
	fclose($fh);
	fwrite(STDERR, "Wrote $count rows to $out\n");
}
