<?php

// One iteration of the clustering worker. Pulls the most-stale work from the
// queue, runs it through the tier ladder, and advances it. Designed to be
// invoked repeatedly by cron / launchd / a shell loop.

require_once (dirname(__FILE__) . '/cluster.php');

//----------------------------------------------------------------------------------------
// Pull the most-stale record from the queue (sorted ascending by visited;
// nulls first, so never-visited records come first).
function top_of_queue()
{
	global $couch;
	global $config;

	$parameters = array(
		'limit'        => 1,
		'include_docs' => 'true',
		'reduce'       => 'false',
	);
	$url = '_design/queue/_view/visited?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$obj = json_decode($resp);

	if (!isset($obj->rows) || count($obj->rows) === 0)
	{
		return null;
	}
	return $obj->rows[0]->doc;
}

//----------------------------------------------------------------------------------------
// Fetch all docs sharing this DOI (Tier 1).
function tier1_candidates($doi)
{
	global $couch;
	global $config;

	$parameters = array(
		'key'          => '"' . $doi . '"',
		'reduce'       => 'false',
		'include_docs' => 'true',
	);
	$url = '_design/matching/_view/doi?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$obj = json_decode($resp);

	$candidates = array();
	if (isset($obj->rows))
	{
		foreach ($obj->rows as $row)
		{
			$candidates[] = $row->doc;
		}
	}
	return $candidates;
}

//----------------------------------------------------------------------------------------
// Compute the [year, volume, first-page] blocking hash for a doc. Returns null
// if any component is missing or unparseable. Mirrors the JS get_hash() in
// index.html so the worker and the matching/hash view agree on key shape.
function compute_hash($doc)
{
	// Year.
	if (!isset($doc->issued->{'date-parts'}[0][0]))
	{
		return null;
	}
	$year = (int)$doc->issued->{'date-parts'}[0][0];
	if ($year === 0)
	{
		return null;
	}

	// Volume. (int) cast mirrors parseInt: takes leading digits only, so
	// "62" → 62 but "Fasc. 62" → 0 (rejected). Matches the view's strict rule.
	if (!isset($doc->volume))
	{
		return null;
	}
	$volume = (int)$doc->volume;
	if ($volume === 0)
	{
		return null;
	}

	// First page. Try page-first, then page-as-range, then page-as-int.
	$page_first = null;
	if (isset($doc->{'page-first'}))
	{
		$p = (int)$doc->{'page-first'};
		if ($p !== 0) { $page_first = $p; }
	}
	else if (isset($doc->page))
	{
		$page = (string)$doc->page;
		if (preg_match('/(\d+)[-–−|](\d+)/u', $page, $m))
		{
			$p = (int)$m[1];
			if ($p !== 0) { $page_first = $p; }
		}
		else
		{
			$p = (int)$page;
			if ($p !== 0) { $page_first = $p; }
		}
	}
	if ($page_first === null)
	{
		return null;
	}

	return array($year, $volume, $page_first);
}

//----------------------------------------------------------------------------------------
// Fetch all docs sharing this [year, volume, first-page] hash (Tier 2).
function tier2_candidates($hash)
{
	global $couch;
	global $config;

	$parameters = array(
		'key'          => json_encode($hash),
		'reduce'       => 'false',
		'include_docs' => 'true',
	);
	$url = '_design/matching/_view/hash?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$obj = json_decode($resp);

	$candidates = array();
	if (isset($obj->rows))
	{
		foreach ($obj->rows as $row)
		{
			$candidates[] = $row->doc;
		}
	}
	return $candidates;
}

//----------------------------------------------------------------------------------------
// Stamp visited + algorithm and PUT. Used when there's nothing to cluster
// (singleton at every tier, or no usable blocking key), so the record still
// advances in the queue.
function mark_visited($doc)
{
	global $couch;
	global $config;

	if (!isset($doc->citebank))
	{
		$doc->citebank = new stdclass;
	}
	if (!isset($doc->citebank->clustering))
	{
		$doc->citebank->clustering = new stdclass;
	}

	$doc->citebank->clustering->visited = date('c', time());
	$doc->citebank->clustering->algorithm = CLUSTER_ALGORITHM;

	$couch->send(
		"PUT",
		"/" . $config['couchdb_options']['database'] . "/" . urlencode($doc->_id),
		json_encode($doc)
	);
}

//----------------------------------------------------------------------------------------

// Run cluster_candidates if there's something to cluster. Always folds the
// head-of-queue doc into the candidate set so its visited stamp gets advanced
// even when the tier view didn't emit a matching key for it (e.g. JS/PHP
// divergence in the hash computation). cluster_candidates dedupes by _id, so
// adding the head doc is safe even when the view did emit it.
function try_cluster($doc, $candidates, $tier_label)
{
	$by_id = array();
	foreach ($candidates as $c)
	{
		$by_id[$c->_id] = $c;
	}
	if (!isset($by_id[$doc->_id]))
	{
		$by_id[$doc->_id] = $doc;
	}

	if (count($by_id) < 2)
	{
		return false;
	}

	cluster_candidates(array_values($by_id), $tier_label);
	return true;
}

//----------------------------------------------------------------------------------------

$doc = top_of_queue();

if ($doc === null)
{
	echo "queue empty\n";
	exit(0);
}

echo "visiting " . $doc->_id . "\n";

// Tier 1: DOI exact.
if (isset($doc->DOI) && try_cluster($doc, tier1_candidates($doc->DOI), 'doi-exact'))
{
	exit(0);
}

// Tier 2: year + volume + first-page hash.
$hash = compute_hash($doc);
if ($hash !== null && try_cluster($doc, tier2_candidates($hash), 'hash-y-v-p'))
{
	exit(0);
}

// No candidates at any tier, or singleton. Stamp visited so we advance.
// Tier 3 (Nouveau) will fit above this once it exists.
mark_visited($doc);

?>
