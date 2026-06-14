<?php

// Pass 2 — block union.
//
// Pass 1 (cluster_runs.php) chains rows that sort adjacent under key_a. Pass 2
// merges those clusters across the OTHER blocking keys, recovering variants that
// sort apart: word-order transpositions (key_b), trailing-tail families (key_c),
// shared-initials forms (key_d). It also applies ISSN must-links.
//
// To stay cheap, we don't compare all row pairs. Each pass-1 cluster gets one
// representative (its longest member); within a block we compare the distinct
// clusters' representatives and union them when LCS >= threshold.
//
//   php block_union.php [--lcs=0.50] [--cap=600] [--keys=key_b,key_c,key_d] [--db=...]

ini_set('memory_limit', '1G');

require_once(__DIR__ . '/measures.php');
require_once(__DIR__ . '/disjoint_set.php');

$opt = getopt('', array('lcs::', 'cap::', 'keys::', 'db::', 'noissn'));
$useIssn = !isset($opt['noissn']);   // --noissn: skip must-links to measure string-only quality
// Pass 2 compares whole-cluster representatives across blocks, so a false match
// cascades via single-linkage. Use a much stricter threshold than pass 1 (0.50),
// and keep only the precise keys — key_d (initials) is too loose and is left to a
// dedicated, evidence-gated acronym rule.
$tLcs   = isset($opt['lcs'])  ? (float)$opt['lcs'] : 0.85;
$cap    = isset($opt['cap'])  ? (int)$opt['cap'] : 600;
$keys   = isset($opt['keys']) ? explode(',', $opt['keys']) : array('key_b', 'key_c');
$dbPath = isset($opt['db'])   ? $opt['db'] : __DIR__ . '/containers.db';

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA synchronous = OFF');

// --- load rows --------------------------------------------------------------
$rows = array();
$repCount = array();   // cluster_id => token count of its representative
$repTokens = array();  // cluster_id => representative token array
$stmt = $db->query("SELECT id, cluster_id, tokens, issn, normalized, key_b, key_c, key_d FROM containers");
foreach ($stmt as $r) {
	$cid = (int)$r['cluster_id'];
	$tok = $r['tokens'] === '' ? array() : explode(' ', $r['tokens']);
	$rows[] = array('cid' => $cid, 'issn' => $r['issn'],
		'key_b' => $r['key_b'], 'key_c' => $r['key_c'], 'key_d' => $r['key_d']);

	// representative = member with most tokens (most informative for matching)
	$c = count($tok);
	if (!isset($repCount[$cid]) || $c > $repCount[$cid]) {
		$repCount[$cid] = $c;
		$repTokens[$cid] = $tok;
	}
}
fwrite(STDERR, count($rows) . " rows, " . count($repTokens) . " pass-1 clusters loaded.\n");

$dj = new DisjointSet();
foreach (array_keys($repTokens) as $cid) {
	$dj->makeset($cid);
}

// --- ISSN must-links --------------------------------------------------------
$issnClusters = array();
foreach ($rows as $r) {
	if ($r['issn'] !== null && $r['issn'] !== '') {
		$issnClusters[$r['issn']][$r['cid']] = true;
	}
}
if ($useIssn) {
	foreach ($issnClusters as $issn => $cids) {
		$cids = array_keys($cids);
		for ($i = 1; $i < count($cids); $i++) {
			$dj->union($cids[$i], $cids[0]);
		}
	}
	fwrite(STDERR, "ISSN must-links applied.\n");
} else {
	fwrite(STDERR, "ISSN must-links SKIPPED (--noissn).\n");
}

// --- block union over each key ----------------------------------------------
foreach ($keys as $key) {
	// block value => set of distinct cluster ids present
	$blocks = array();
	foreach ($rows as $r) {
		$v = $r[$key];
		if ($v === '') continue;
		$blocks[$v][$r['cid']] = true;
	}

	$merges = 0; $skipped = 0;
	foreach ($blocks as $v => $cidset) {
		$cids = array_keys($cidset);
		$m = count($cids);
		if ($m < 2) continue;
		if ($m > $cap) { $skipped++; continue; }   // oversize block: handled elsewhere

		for ($i = 0; $i < $m - 1; $i++) {
			for ($j = $i + 1; $j < $m; $j++) {
				if ($dj->find($cids[$i]) === $dj->find($cids[$j])) continue;
				if (sim_lcs($repTokens[$cids[$i]], $repTokens[$cids[$j]]) >= $tLcs) {
					$dj->union($cids[$i], $cids[$j]);
					$merges++;
				}
			}
		}
	}
	fwrite(STDERR, sprintf("  %s: %d unions (%d blocks over cap %d skipped)\n", $key, $merges, $skipped, $cap));
	unset($blocks);
}

// --- relabel cluster_id from merged components ------------------------------
// Build an old->new map table once, then remap in a single UPDATE (the old
// per-cluster UPDATE was O(clusters * full-table-scan)).
$root2new = array();
$next = 0;
$db->exec('DROP TABLE IF EXISTS cluster_map');
$db->exec('CREATE TABLE cluster_map (old INTEGER PRIMARY KEY, new INTEGER)');
$ins = $db->prepare('INSERT INTO cluster_map (old, new) VALUES (:old, :new)');
$db->beginTransaction();
foreach (array_keys($repTokens) as $cid) {
	$root = $dj->find($cid);
	if (!isset($root2new[$root])) $root2new[$root] = ++$next;
	$ins->execute(array(':old' => $cid, ':new' => $root2new[$root]));
}
$db->commit();
$db->exec('UPDATE containers SET cluster_id = (SELECT new FROM cluster_map WHERE old = containers.cluster_id)');
$db->exec('DROP TABLE cluster_map');
$db->exec('CREATE INDEX IF NOT EXISTS idx_cluster ON containers(cluster_id)');
fwrite(STDERR, "Relabelled into $next clusters.\n\n");

require_once(__DIR__ . '/report.php');
report($db);
