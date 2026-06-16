<?php

// CLI: probe BioStor coverage for one container cluster and store matches in a
// side SQLite (biostor_coverage.db). See reconcile_lib.php for the core.
//
//   php reconcile.php --cid container:<slug> [--db ...] [--batch 20] [--limit N] [--sleep 0.3]
//
// EXPLORATION TOOL — results never go into the CouchDB work docs.

ini_set('memory_limit', '1G');

require_once(__DIR__ . '/../couchsimple.php');   // $couch, $config
require_once(__DIR__ . '/reconcile_lib.php');

$opt = getopt('', array('cid:', 'db:', 'batch:', 'limit:', 'sleep:', 'endpoint:'));
if (!isset($opt['cid']))
{
	fwrite(STDERR, "usage: php reconcile.php --cid container:<slug> [--db ...] [--batch 20] [--limit N] [--sleep 0.3]\n");
	exit(1);
}
$cid    = $opt['cid'];
$dbPath = isset($opt['db']) ? $opt['db'] : __DIR__ . '/biostor_coverage.db';
$dbn    = $config['couchdb_options']['database'];

$store = bio_open_store($dbPath);

$opts = array(
	'batch'    => isset($opt['batch'])    ? (int)$opt['batch']   : 20,
	'limit'    => isset($opt['limit'])    ? (int)$opt['limit']   : 0,
	'sleep'    => isset($opt['sleep'])    ? (float)$opt['sleep'] : 0.3,
	'endpoint' => isset($opt['endpoint']) ? $opt['endpoint']     : 'https://biostor.org/reconcile',
);

fwrite(STDERR, "reconciling $cid ...\n");
$stats = bio_run_reconcile($couch, $dbn, $cid, $store, $opts, function ($done, $total, $m, $u) {
	fwrite(STDERR, sprintf("  %d/%d  (matched=%d unmatched=%d)\n", $done, $total, $m, $u));
});

// --- summary ----------------------------------------------------------------
$total = $stats['matched'] + $stats['unmatched'];
echo "\n=== BioStor coverage: $cid ===\n";
echo "work-clusters checked .. " . $stats['checked'] . ($stats['skipped'] ? " ({$stats['skipped']} skipped, no citation)" : "") . "\n";
printf("matched in BioStor ..... %d (%.1f%%)\n", $stats['matched'], $total ? 100.0 * $stats['matched'] / $total : 0);
printf("not matched (gaps) ..... %d\n", $stats['unmatched']);

$scores = $store->prepare('SELECT score FROM coverage WHERE matched=1 AND cid=:cid AND score IS NOT NULL ORDER BY score');
$scores->execute(array(':cid' => $cid));
$s = $scores->fetchAll(PDO::FETCH_COLUMN);
if ($s)
{
	printf("match score: min %.3f · median %.3f · max %.3f\n", $s[0], $s[(int)(count($s) / 2)], end($s));
	$low = $store->prepare('SELECT score, title, biostor_id FROM coverage WHERE matched=1 AND cid=:cid ORDER BY score ASC LIMIT 5');
	$low->execute(array(':cid' => $cid));
	echo "lowest-confidence matches:\n";
	foreach ($low as $r) printf("  %.3f  biostor/%s  %s\n", $r['score'], $r['biostor_id'], mb_strimwidth($r['title'], 0, 55));
}

$gaps = $store->prepare('SELECT title FROM coverage WHERE matched=0 AND cid=:cid LIMIT 20');
$gaps->execute(array(':cid' => $cid));
$g = $gaps->fetchAll(PDO::FETCH_COLUMN);
if ($g)
{
	echo "sample gaps (not in BioStor):\n";
	foreach ($g as $t) echo "  " . mb_strimwidth($t, 0, 65) . "\n";
}
echo "\nstored in $dbPath\n";
