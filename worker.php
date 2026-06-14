<?php

// One iteration of the clustering worker. Pulls the most-stale work from the
// queue, runs it through the tier ladder, and advances it. Designed to be
// invoked repeatedly by cron / launchd / a shell loop.

require_once (dirname(__FILE__) . '/cluster.php');

//----------------------------------------------------------------------------------------
// Pull the most-stale record from a queue view. Defaults to the global queue
// (sorted ascending by visited, nulls first). For facet-scoped queues, pass
// the view name plus a key range — e.g. by-container keyed [container, visited]
// uses startkey=[name], endkey=[name, {}] to bracket one journal.
function top_of_queue($view = 'visited', $startkey = null, $endkey = null)
{
	global $couch;
	global $config;

	$parameters = array(
		'limit'        => 1,
		'include_docs' => 'true',
		'reduce'       => 'false',
	);
	if ($startkey !== null)
	{
		$parameters['startkey'] = json_encode($startkey, JSON_UNESCAPED_UNICODE);
	}
	if ($endkey !== null)
	{
		$parameters['endkey'] = json_encode($endkey, JSON_UNESCAPED_UNICODE);
	}
	$url = '_design/queue/_view/' . $view . '?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$obj = json_decode($resp);

	if (!isset($obj->rows) || count($obj->rows) === 0)
	{
		return null;
	}
	return $obj->rows[0]->doc;
}

//----------------------------------------------------------------------------------------
// Like top_of_queue('by-container', ...) but spanning every variant spelling of a
// container cluster (a _design/container doc). Returns the stalest work across all
// the cluster's variant names, so the worker can work a whole journal regardless of
// which spelling each work happened to use.
function top_of_queue_for_container($cid)
{
	global $couch;
	global $config;

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . urlencode($cid));
	$container = json_decode($resp);
	if (!$container || !isset($container->variants) || !is_array($container->variants))
	{
		return null;
	}

	$best = null;
	$best_visited = false;   // false = no candidate yet; null = never-visited (stalest)

	foreach ($container->variants as $variant)
	{
		$doc = top_of_queue('by-container', array($variant), array($variant, new stdclass));
		if ($doc === null)
		{
			continue;
		}

		$visited = isset($doc->citebank->clustering->visited)
			? $doc->citebank->clustering->visited
			: null;

		// Stalest wins: never-visited (null) beats any date; otherwise the
		// earliest ISO-8601 timestamp (which sorts lexicographically).
		if ($best_visited === false
			|| ($visited === null && $best_visited !== null)
			|| ($visited !== null && $best_visited !== null && $visited < $best_visited))
		{
			$best = $doc;
			$best_visited = $visited;
		}

		if ($best_visited === null)
		{
			break;   // can't get staler than never-visited
		}
	}

	return $best;
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

// Run the tier ladder on one doc: DOI exact, then year+volume+first-page hash,
// else just stamp it visited so it advances. (Tier 3 Nouveau will slot in above
// the mark_visited fallback once it exists.)
function process_doc($doc)
{
	echo "visiting " . $doc->_id . "\n";

	if (isset($doc->DOI) && try_cluster($doc, tier1_candidates($doc->DOI), 'doi-exact'))
	{
		return;
	}

	$hash = compute_hash($doc);
	if ($hash !== null && try_cluster($doc, tier2_candidates($hash), 'hash-y-v-p'))
	{
		return;
	}

	mark_visited($doc);
}

//----------------------------------------------------------------------------------------

// Parse CLI args. Scope the iteration with one of:
//   --cid "container:<slug>"   whole container cluster (all variant spellings)
//   --container "X"            a single exact container-title spelling
//   --author "Family"          a single author family
// --once drains the chosen scope in a single run (visit every work once, then
// exit) instead of the default one-doc-per-invocation behaviour.
$cid       = null;
$container = null;
$author    = null;
$once      = false;
for ($i = 1; $i < $argc; $i++)
{
	if ($argv[$i] === '--cid' && $i + 1 < $argc)
	{
		$cid = $argv[$i + 1];
		$i++;
	}
	else if ($argv[$i] === '--container' && $i + 1 < $argc)
	{
		$container = $argv[$i + 1];
		$i++;
	}
	else if ($argv[$i] === '--author' && $i + 1 < $argc)
	{
		$author = $argv[$i + 1];
		$i++;
	}
	else if ($argv[$i] === '--once')
	{
		$once = true;
	}
}

// Returns the stalest unprocessed doc for the chosen scope (null if none).
$select = function () use ($cid, $container, $author)
{
	if ($cid !== null)
	{
		return top_of_queue_for_container($cid);
	}
	if ($container !== null)
	{
		return top_of_queue('by-container', array($container), array($container, new stdclass));
	}
	if ($author !== null)
	{
		return top_of_queue('by-author-family', array($author), array($author, new stdclass));
	}
	return top_of_queue();
};

if ($cid !== null)            { echo "container cluster: $cid\n"; }
else if ($container !== null) { echo "container: $container\n"; }
else if ($author !== null)    { echo "author: $author\n"; }

if ($once)
{
	// One full pass: keep visiting the stalest doc in scope until the stalest has
	// already been visited during THIS run (we've wrapped around) or nothing is
	// left. Each visit stamps visited=now, so the pass always converges.
	$run_start = date('c', time());
	$seen = array();
	$processed = 0;

	while (true)
	{
		$doc = $select();
		if ($doc === null)
		{
			break;
		}

		$visited = isset($doc->citebank->clustering->visited)
			? $doc->citebank->clustering->visited
			: null;

		// Stalest is already done this run => full pass complete.
		if ($visited !== null && $visited >= $run_start)
		{
			break;
		}
		// Safety net: if the stalest is one we already handled this run but its
		// stamp did not advance, stop rather than spin forever.
		if (isset($seen[$doc->_id]))
		{
			echo "stopping: " . $doc->_id . " did not advance\n";
			break;
		}
		$seen[$doc->_id] = true;

		process_doc($doc);
		$processed++;
	}

	echo "drained: $processed work(s) visited in one pass\n";
	exit(0);
}

// Default: one doc per invocation.
$doc = $select();
if ($doc === null)
{
	echo "queue empty\n";
	exit(0);
}

process_doc($doc);

?>
