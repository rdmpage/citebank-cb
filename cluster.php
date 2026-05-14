<?php

// Experiments with clustering

require_once (dirname(__FILE__) . '/utilities.php');
require_once (dirname(__FILE__) . '/couchsimple.php');
require_once (dirname(__FILE__) . '/compare.php');
require_once (dirname(__FILE__) . '/disjoint_set.php');
require_once (dirname(__FILE__) . '/csl_features.php');

// Stamped on each visited record so the queue can re-prioritise records
// processed by an older algorithm version.
define('CLUSTER_ALGORITHM', 'heuristic-v1');

// Ring-buffer cap for the per-record comparison log.
define('COMPARISONS_MAX', 5);

//----------------------------------------------------------------------------------------
// Append an entry to a doc's clustering.comparisons ring buffer, dropping the
// oldest entries once the cap is exceeded.
function push_comparison($doc, $entry)
{
	if (!isset($doc->citebank))
	{
		$doc->citebank = new stdclass;
	}
	if (!isset($doc->citebank->clustering))
	{
		$doc->citebank->clustering = new stdclass;
	}
	if (!isset($doc->citebank->clustering->comparisons))
	{
		$doc->citebank->clustering->comparisons = array();
	}

	$doc->citebank->clustering->comparisons[] = (object)$entry;

	while (count($doc->citebank->clustering->comparisons) > COMPARISONS_MAX)
	{
		array_shift($doc->citebank->clustering->comparisons);
	}
}

//----------------------------------------------------------------------------------------
// Heuristic score: count of fields on which the pair agrees.
function score_from_vector($vector)
{
	$same = 0;
	$n = count($vector);
	for ($i = 0; $i < $n; $i += 2)
	{
		if ($vector[$i] == 1)
		{
			$same++;
		}
	}
	return $same;
}

//----------------------------------------------------------------------------------------
function cluster_candidates($candidates, $tier = 'doi-exact')
{
	global $couch;
	global $config;

	$n = count($candidates);

	if ($n < 2)
	{
		return;
	}

	// Index by _id and use _ids as DSU keys, so find() returns cluster_id directly.
	$by_id = array();
	foreach ($candidates as $doc)
	{
		$by_id[$doc->_id] = $doc;
	}

	$dj = new DisjointSet();
	foreach ($by_id as $id => $doc)
	{
		$dj->makeset($id);
	}

	$ids = array_keys($by_id);
	$count = count($ids);
	$now = date('c', time());

	for ($i = 1; $i < $count; $i++)
	{
		for ($j = 0; $j < $i; $j++)
		{
			$id_i = $ids[$i];
			$id_j = $ids[$j];

			$features = citation_pair_to_feature_vector(array($by_id[$id_i], $by_id[$id_j]), false);
			$vector = $features->vector;

			$match = is_match($vector);
			$score = score_from_vector($vector);
			$decision = $match ? 'match' : 'no-match';

			echo "[" . join(",", $vector) . "] score=$score decision=$decision\n";

			push_comparison($by_id[$id_i], array(
				'id' => $id_j,
				'score' => $score,
				'decision' => $decision,
				'features' => $vector,
				'tier' => $tier,
				'timestamp' => $now,
			));
			push_comparison($by_id[$id_j], array(
				'id' => $id_i,
				'score' => $score,
				'decision' => $decision,
				'features' => $vector,
				'tier' => $tier,
				'timestamp' => $now,
			));

			if ($match)
			{
				$dj->union($id_i, $id_j);
			}
		}
	}

	// Stamp cluster + visited + algorithm and write back.
	foreach ($by_id as $id => $doc)
	{
		$cluster_id = $dj->find($id);

		$doc->citebank->cluster = $cluster_id;
		$doc->citebank->clustering->visited = $now;
		$doc->citebank->clustering->algorithm = CLUSTER_ALGORITHM;

		$resp = $couch->send(
			"PUT",
			"/" . $config['couchdb_options']['database'] . "/" . urlencode($id),
			json_encode($doc)
		);

		echo $id . " -> cluster " . $cluster_id . "\n";
	}
}

//----------------------------------------------------------------------------------------
// Inline test driver: only runs when cluster.php is executed directly, not
// when included by another script (e.g. worker.php).
if (realpath(__FILE__) !== realpath($_SERVER['SCRIPT_FILENAME']))
{
	return;
}


if (1)
{
	//-------
	// same DOI

	$doi = '10.5479/si.00963801.61-2434.1';
	$doi = '10.1111/j.1096-3642.1922.tb01493.x';

	$parameters = array(
		'key' 			=> '"' . $doi . '"',
		'reduce' 		=> 'false',
		'include_docs' 	=> 'true',
	);

	$url = '_design/matching/_view/doi?' . http_build_query($parameters);
}

/*
if (1)
{
	//-------
	$hash = [ 2009, 2231, 1 ];
	$hash = [ 1983, 19, 69 ];

	$parameters = array(
		'key' 			=> json_encode($hash),
		'reduce' 		=> 'false',
		'include_docs' 	=> 'true',
	);

	$url = '_design/matching/_view/hash?' . http_build_query($parameters);
}
*/

echo $url . "\n";

//-------


$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
$response_obj = json_decode($resp);



$candidates = [];

foreach ($response_obj->rows as $row)
{
	$candidates[] = $row->doc;
}


cluster_candidates($candidates);


?>
