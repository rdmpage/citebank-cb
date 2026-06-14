<?php

// Shared cluster reporting. report($db) prints cluster stats and ISSN-based
// quality metrics against the current cluster_id assignment.

require_once(__DIR__ . '/disjoint_set.php');

//----------------------------------------------------------------------------------------
// Group ISSNs that co-occur on the same normalised title into one "journal".
// A print + online ISSN pair appears on the same title, so this treats them as a
// single journal and avoids counting that pair as a false split.
function issn_journal_groups($db)
{
	$dj = new DisjointSet();
	$stmt = $db->query("
		SELECT normalized, issn FROM containers
		WHERE issn IS NOT NULL AND issn != ''
		GROUP BY normalized, issn");
	$byName = array();
	foreach ($stmt as $r) {
		$dj->exists($r['issn']) || $dj->makeset($r['issn']);
		$byName[$r['normalized']][] = $r['issn'];
	}
	foreach ($byName as $name => $issns) {
		for ($i = 1; $i < count($issns); $i++) {
			$dj->union($issns[$i], $issns[0]);
		}
	}
	// issn => group root
	$group = array();
	foreach (array_keys($dj->parent) as $issn) {
		$group[$issn] = $dj->find($issn);
	}
	return $group;
}

//----------------------------------------------------------------------------------------
function report($db)
{
	$one = function ($sql) use ($db) { return $db->query($sql)->fetchColumn(); };

	$total    = $one('SELECT COUNT(*) FROM containers');
	$clusters = $one('SELECT COUNT(DISTINCT cluster_id) FROM containers');
	$single   = $one('SELECT COUNT(*) FROM (SELECT cluster_id FROM containers GROUP BY cluster_id HAVING COUNT(*)=1)');

	echo "rows .................. $total\n";
	echo "clusters .............. $clusters\n";
	echo "singleton clusters .... $single\n";
	echo "multi-row clusters .... " . ($clusters - $single) . "\n\n";

	echo "largest clusters:\n";
	$big = $db->query("
		SELECT cluster_id, COUNT(*) n,
		       (SELECT normalized FROM containers c2 WHERE c2.cluster_id=c1.cluster_id ORDER BY LENGTH(normalized) DESC LIMIT 1) rep
		FROM containers c1 GROUP BY cluster_id ORDER BY n DESC LIMIT 12");
	foreach ($big as $r) {
		printf("  %6d  %-55s\n", $r['n'], mb_strimwidth($r['rep'], 0, 55));
	}

	// --- journal-level purity (ISSN groups = print/online aware) ------------
	$group = issn_journal_groups($db);

	// cluster ids carried by each row that has an ISSN
	$rowStmt = $db->query("SELECT issn, cluster_id FROM containers WHERE issn IS NOT NULL AND issn != ''");
	$journalClusters = array();   // group root => set of cluster ids
	$journalRows = array();        // group root => row count
	$clusterGroups = array();      // cluster id => set of group roots
	foreach ($rowStmt as $r) {
		$g = $group[$r['issn']];
		$journalClusters[$g][$r['cluster_id']] = true;
		$journalRows[$g] = ($journalRows[$g] ?? 0) + 1;
		$clusterGroups[$r['cluster_id']][$g] = true;
	}

	$multi = 0; $pure = 0; $split = 0; $splits = array();
	foreach ($journalClusters as $g => $cids) {
		if ($journalRows[$g] < 2) continue;   // only journals seen on >1 row
		$multi++;
		if (count($cids) == 1) $pure++;
		else { $split++; $splits[$g] = count($cids); }
	}

	echo "\nISSN journal purity (print/online grouped):\n";
	printf("  journals on >1 row .... %d\n", $multi);
	printf("  kept in one cluster ... %d (%.1f%%)\n", $pure, $multi ? 100.0 * $pure / $multi : 0);
	printf("  split across clusters . %d\n", $split);
	if ($splits) {
		arsort($splits);
		echo "  most-split journals:\n";
		$i = 0;
		foreach ($splits as $g => $c) {
			printf("    %s -> %d clusters\n", $g, $c);
			if (++$i >= 6) break;
		}
	}

	// --- over-merge signal: clusters holding >1 distinct journal ------------
	$overmerged = 0; $worst = array();
	foreach ($clusterGroups as $cid => $groups) {
		if (count($groups) > 1) { $overmerged++; $worst[$cid] = count($groups); }
	}
	echo "\nover-merge signal:\n";
	printf("  clusters with >1 distinct journal (ISSN group) ... %d\n", $overmerged);
	if ($worst) {
		arsort($worst);
		$i = 0;
		foreach ($worst as $cid => $c) {
			$rep = $db->query("SELECT normalized FROM containers WHERE cluster_id=" . (int)$cid . " ORDER BY LENGTH(normalized) DESC LIMIT 1")->fetchColumn();
			printf("    cluster %d holds %d journals: %s\n", $cid, $c, mb_strimwidth($rep, 0, 45));
			if (++$i >= 6) break;
		}
	}
}
