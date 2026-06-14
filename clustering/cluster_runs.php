<?php

// Runs-and-breaks clustering: walk the sorted rows and chain each into the
// previous row's cluster while LCS >= threshold; start a new cluster on a break.
// Writes cluster_id for EVERY row, then reports cluster stats + ISSN purity.
//
//   php cluster_runs.php [--sort=key_a] [--lcs=0.50] [--db=containers.db]
//
// This is single-linkage along ONE sort order — a cheap first pass. Variants that
// sort apart are not merged here; a later block-union phase can combine clusters.

require_once(__DIR__ . '/measures.php');

$opt = getopt('', array('sort::', 'lcs::', 'db::'));
$sort   = isset($opt['sort']) ? $opt['sort'] : 'key_a';
$tLcs   = isset($opt['lcs'])  ? (float)$opt['lcs'] : 0.50;
$dbPath = isset($opt['db'])   ? $opt['db']  : __DIR__ . '/containers.db';

if (!in_array($sort, array('key_a', 'key_b', 'normalized'))) {
	fwrite(STDERR, "--sort must be key_a, key_b or normalized\n");
	exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA synchronous = OFF');

// --- main pass: chain rows that share a key (sorted) -------------------------
$db->exec('UPDATE containers SET cluster_id = NULL');

$rows = $db->query(
	"SELECT id, tokens FROM containers WHERE $sort != '' ORDER BY $sort, normalized"
);

$update = $db->prepare('UPDATE containers SET cluster_id = :cid WHERE id = :id');
$db->beginTransaction();

$cluster = 0;
$prevTok = null;
$n = 0;
foreach ($rows as $row) {
	$tok = $row['tokens'] === '' ? array() : explode(' ', $row['tokens']);

	if ($prevTok === null || sim_lcs($prevTok, $tok) < $tLcs) {
		$cluster++;          // break => new cluster
	}
	$update->execute(array(':cid' => $cluster, ':id' => $row['id']));

	$prevTok = $tok;
	if (++$n % 20000 === 0) {
		$db->commit();
		$db->beginTransaction();
		fwrite(STDERR, "  $n rows...\n");
	}
}
$db->commit();

// --- singletons: rows with no usable sort key get their own cluster ----------
$db->beginTransaction();
$leftover = $db->query('SELECT id FROM containers WHERE cluster_id IS NULL');
foreach ($leftover as $row) {
	$cluster++;
	$update->execute(array(':cid' => $cluster, ':id' => $row['id']));
}
$db->commit();

fwrite(STDERR, "Assigned $cluster clusters over " . ($n) . " keyed rows (sort=$sort, lcs>=$tLcs).\n\n");

//----------------------------------------------------------------------------------------
// Report
function one($db, $sql) { return $db->query($sql)->fetchColumn(); }

$total    = one($db, 'SELECT COUNT(*) FROM containers');
$clusters = one($db, 'SELECT COUNT(DISTINCT cluster_id) FROM containers');
$single   = one($db, 'SELECT COUNT(*) FROM (SELECT cluster_id FROM containers GROUP BY cluster_id HAVING COUNT(*)=1)');

echo "rows .................. $total\n";
echo "clusters .............. $clusters\n";
echo "singleton clusters .... $single\n";
echo "multi-row clusters .... " . ($clusters - $single) . "\n\n";

echo "largest clusters:\n";
$big = $db->query("
	SELECT cluster_id, COUNT(*) n,
	       (SELECT normalized FROM containers c2 WHERE c2.cluster_id=c1.cluster_id ORDER BY normalized LIMIT 1) rep
	FROM containers c1 GROUP BY cluster_id ORDER BY n DESC LIMIT 12");
foreach ($big as $r) {
	printf("  %6d  %-55s\n", $r['n'], mb_strimwidth($r['rep'], 0, 55));
}

// ISSN purity: of ISSNs appearing on >1 row, how many stay in a single cluster?
echo "\nISSN purity (ground truth):\n";
$issnStmt = $db->query("
	SELECT issn, COUNT(*) rows, COUNT(DISTINCT cluster_id) clusters
	FROM containers WHERE issn IS NOT NULL AND issn != '' GROUP BY issn");
$multi = 0; $pure = 0; $split = 0;
$splits = array();
foreach ($issnStmt as $r) {
	if ($r['rows'] < 2) continue;
	$multi++;
	if ($r['clusters'] == 1) $pure++;
	else { $split++; $splits[$r['issn']] = $r['clusters']; }
}
printf("  ISSNs on >1 row ....... %d\n", $multi);
printf("  kept in one cluster ... %d (%.1f%%)\n", $pure, $multi ? 100.0*$pure/$multi : 0);
printf("  split across clusters . %d\n", $split);
if ($splits) {
	arsort($splits);
	echo "  most-split ISSNs:\n";
	$i = 0;
	foreach ($splits as $issn => $c) {
		printf("    %s -> %d clusters\n", $issn, $c);
		if (++$i >= 8) break;
	}
}
