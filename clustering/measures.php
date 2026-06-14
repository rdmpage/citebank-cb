<?php

// Adjacency similarity measures shared by runs.php (report) and cluster_runs.php
// (assignment). Requires clean.php for is_abbreviation().

require_once(__DIR__ . '/clean.php');

//----------------------------------------------------------------------------------------
// Abbreviation-aware ordered token LCS, normalised to 0..1 (2*L/(m+n)).
// Two tokens match if one is a prefix of the other (is_abbreviation) or they
// differ by a single character (levenshtein <= 1).
function sim_lcs($t1, $t2)
{
	$m = count($t1);
	$n = count($t2);
	if ($m === 0 || $n === 0) return 0.0;   // contentless names never match

	$dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
	for ($i = 1; $i <= $m; $i++) {
		for ($j = 1; $j <= $n; $j++) {
			$match = is_abbreviation($t1[$i - 1], $t2[$j - 1])
				|| (levenshtein($t1[$i - 1], $t2[$j - 1]) <= 1);
			if ($match) {
				$dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
			} else {
				$dp[$i][$j] = max($dp[$i - 1][$j], $dp[$i][$j - 1]);
			}
		}
	}
	return 2.0 * $dp[$m][$n] / ($m + $n);
}

//----------------------------------------------------------------------------------------
// Shared leading-character fraction of two strings.
function sim_prefix($s1, $s2)
{
	$len = min(strlen($s1), strlen($s2));
	$i = 0;
	while ($i < $len && $s1[$i] === $s2[$i]) $i++;
	$max = max(strlen($s1), strlen($s2));
	return $max ? $i / $max : 1.0;
}
