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
	if (!isset($doc->issued->{'date-parts'}[0][0]))
	{
		return null;
	}
	$year = (int)$doc->issued->{'date-parts'}[0][0];
	if ($year === 0)
	{
		return null;
	}

	if (!isset($doc->volume) || !preg_match('/(\d+)/', (string)$doc->volume, $vm))
	{
		return null;
	}
	$volume = (int)$vm[1];

	$page_first = null;
	if (isset($doc->{'page-first'}))
	{
		$page_first = (int)$doc->{'page-first'};
	}
	else if (isset($doc->page) && preg_match('/(\d+)/', (string)$doc->page, $pm))
	{
		$page_first = (int)$pm[1];
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

$doc = top_of_queue();

if ($doc === null)
{
	echo "queue empty\n";
	exit(0);
}

echo "visiting " . $doc->_id . "\n";

// Tier 1: DOI exact.
if (isset($doc->DOI))
{
	$candidates = tier1_candidates($doc->DOI);
	if (count($candidates) >= 2)
	{
		cluster_candidates($candidates, 'doi-exact');
		exit(0);
	}
}

// Tier 2: year + volume + first-page hash.
$hash = compute_hash($doc);
if ($hash !== null)
{
	$candidates = tier2_candidates($hash);
	if (count($candidates) >= 2)
	{
		cluster_candidates($candidates, 'hash-y-v-p');
		exit(0);
	}
}

// No tier hit (singleton at every tier, or no usable blocking key). Stamp
// visited so we advance. Tier 3 (Nouveau) will fit above this once it exists.
mark_visited($doc);

?>
