<?php

// Re-derive feature vectors for the candidate pairs already sitting in the
// clustering comparison logs, using the continuous features (v2) instead of the
// binary same/diff ones (v1).
//
//   php derive_features.php [--limit=N] [--out=pairs.jsonl] [--batch=N] [--all] [--regress]
//
//     --limit=N   stop after N source documents (default 5000; --all for every one)
//     --out=FILE  output path (default pairs.jsonl); a FILE.names.json sidecar
//                 records the v2 column names in vector order
//     --batch=N   documents fetched per CouchDB request (default 500)
//     --regress   also recompute the v1 vector with the current code, and what
//                 is_match() decides from it, as v1_now/match_now. Comparing
//                 those against the stored vector and decision shows exactly
//                 which pairs a change to the matching code flips, with the
//                 citations alongside to judge whether the flip is right.
//                 Roughly 17x slower: it runs Smith-Waterman per pair.
//
// Why this exists
// ---------------
// Every record the worker visits leaves a ring buffer of recent comparisons in
// citebank.clustering.comparisons: the candidate's id, the v1 feature vector,
// and the heuristic's match/no-match decision. Those pairs are the expensive
// part of assembling a training set -- tiered blocking already did the work of
// finding plausible near-duplicates.
//
// The labels, however, are heuristic-v1's own output, and the decision is a
// deterministic function of the v1 vector (measured: 87 distinct vectors across
// the database, zero of them ever labelled both ways). Training on them
// reproduces the heuristic exactly and learns nothing. So what this emits is a
// labelling worklist, not a training set:
//
//   * v2   the continuous vector, which is what a real model should see
//   * v1   the old vector, for comparison
//   * decision/tier/score  what heuristic-v1 said, as a starting point and a
//          regression baseline -- NOT as ground truth
//   * label  always null: fill this in by hand
//
// Pairs are emitted once each (A logging B and B logging A collapse to one row).

ini_set('memory_limit', '-1');

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/couchsimple.php');
require_once(dirname(__FILE__) . '/csl_features.php');

//----------------------------------------------------------------------------------------
$opt = getopt('', array('limit::', 'out::', 'batch::', 'all', 'regress'));

$limit  = isset($opt['limit']) ? (int)$opt['limit'] : 5000;
$out    = isset($opt['out'])   ? $opt['out']        : dirname(__FILE__) . '/pairs.jsonl';
$batch  = isset($opt['batch']) ? (int)$opt['batch'] : 500;

if (isset($opt['all']))
{
	$limit = PHP_INT_MAX;
}

// Also recompute the v1 vector with the current code, for regression testing.
// Off by default: it runs Smith-Waterman twice per pair (title and container),
// which costs ~5ms each and makes a full run around 17x slower -- 39 minutes
// against 2.
$regress = isset($opt['regress']);

$dbn = $config['couchdb_options']['database'];

//----------------------------------------------------------------------------------------
// Fetch a set of documents by id in one request.
function fetch_docs($ids)
{
	global $couch, $dbn;

	$docs = array();

	if (count($ids) === 0)
	{
		return $docs;
	}

	$payload = json_encode(array('keys' => array_values($ids)), JSON_UNESCAPED_UNICODE);

	$resp = $couch->send("POST", "/$dbn/_all_docs?include_docs=true", $payload);

	$obj = json_decode($resp);

	if (!$obj || !isset($obj->rows))
	{
		return $docs;
	}

	foreach ($obj->rows as $row)
	{
		if (isset($row->doc) && $row->doc !== null)
		{
			$docs[$row->doc->_id] = $row->doc;
		}
	}

	return $docs;
}

//----------------------------------------------------------------------------------------
// A compact, human-readable rendering of a record. The output of this script is
// meant to be labelled by hand, and nobody can judge whether two records are the
// same work from a pair of ids and 22 floats.
function display_citation($doc)
{
	$bits = array();

	if (isset($doc->author[0]))
	{
		$a = $doc->author[0];
		if (isset($a->family))
		{
			$bits[] = $a->family;
		}
		elseif (isset($a->literal))
		{
			$bits[] = $a->literal;
		}
	}

	if (isset($doc->issued->{'date-parts'}[0][0]))
	{
		$bits[] = '(' . $doc->issued->{'date-parts'}[0][0] . ')';
	}

	$title = csl_first_string(isset($doc->title) ? $doc->title : null);
	if ($title !== null)
	{
		$bits[] = $title;
	}

	$container = csl_first_string(isset($doc->{'container-title'}) ? $doc->{'container-title'} : null);
	if ($container !== null)
	{
		$bits[] = $container;
	}

	if (isset($doc->volume))
	{
		$bits[] = 'vol ' . csl_first_string($doc->volume);
	}

	if (isset($doc->page))
	{
		$bits[] = 'pp ' . csl_first_string($doc->page);
	}

	return trim(preg_replace('/\s+/u', ' ', join(' ', $bits)));
}

//----------------------------------------------------------------------------------------
$names = continuous_feature_names();

file_put_contents($out . '.names.json',
	json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$fh = fopen($out, 'w');
if (!$fh)
{
	fwrite(STDERR, "Cannot write to $out\n");
	exit(1);
}

// Walk the queue newest-visited first: those are the documents that actually
// carry comparison logs.
//
// Descending, the view runs from the newest timestamp down to null (never
// visited). Two thirds of the database has never been visited and so has no
// comparisons at all, so stop at the boundary rather than walking ~647,000
// documents that cannot contribute a pair: null sorts below every string, so
// an endkey of "" ends the walk exactly where the logs run out.
$processed = 0;
$emitted   = 0;
$skipped   = 0;
$seen_pair = array();
$last_key  = null;
$last_id   = null;

while ($processed < $limit)
{
	$want = min($batch, $limit - $processed);

	$parameters = array(
		'descending'   => 'true',
		'endkey'       => '""',        // stop at the never-visited documents
		'limit'        => $want + 1,   // +1 to page without re-emitting the boundary
		'include_docs' => 'true',
		'reduce'       => 'false',
	);

	if ($last_key !== null)
	{
		$parameters['startkey']    = json_encode($last_key, JSON_UNESCAPED_UNICODE);
		$parameters['startkey_docid'] = $last_id;
		$parameters['skip']        = 1;
	}

	$url  = '_design/queue/_view/visited?' . http_build_query($parameters);
	$resp = $couch->send("GET", "/$dbn/" . $url);
	$obj  = json_decode($resp);

	if (!$obj || !isset($obj->rows) || count($obj->rows) === 0)
	{
		break;
	}

	$rows = $obj->rows;
	if (count($rows) > $want)
	{
		$rows = array_slice($rows, 0, $want);
	}

	// Collect this page's candidate ids so they can be fetched in one request.
	$wanted = array();

	foreach ($rows as $row)
	{
		$doc = isset($row->doc) ? $row->doc : null;
		if (!$doc || !isset($doc->citebank->clustering->comparisons))
		{
			continue;
		}
		foreach ($doc->citebank->clustering->comparisons as $c)
		{
			if (isset($c->id))
			{
				$wanted[$c->id] = $c->id;
			}
		}
	}

	$candidates = fetch_docs($wanted);

	foreach ($rows as $row)
	{
		$last_key = $row->key;
		$last_id  = $row->id;
		$processed++;

		$doc = isset($row->doc) ? $row->doc : null;
		if (!$doc || !isset($doc->citebank->clustering->comparisons))
		{
			continue;
		}

		foreach ($doc->citebank->clustering->comparisons as $c)
		{
			if (!isset($c->id) || !isset($candidates[$c->id]))
			{
				$skipped++;
				continue;
			}

			// One row per unordered pair.
			$pair = array($doc->_id, $c->id);
			sort($pair);
			$pair_key = md5($pair[0] . "\0" . $pair[1]);

			if (isset($seen_pair[$pair_key]))
			{
				continue;
			}
			$seen_pair[$pair_key] = true;

			$other = $candidates[$c->id];

			$v2 = citation_pair_to_feature_vector_continuous(array($doc, $other));

			// Recompute the v1 vector with the code as it stands now. Comparing
			// this against the stored vector and decision turns the file into a
			// regression harness: any change to the matching code (comparators,
			// normalisation, thresholds) shows up as pairs whose verdict flips,
			// with the citations right there to judge whether the flip is right.
			//
			// The second argument must match cluster.php, which passes false and
			// so emits no date feature at all -- the worker does not currently
			// compare publication years. Passing the default 1 here instead adds
			// a year pair, making the vector 16 dimensions against the stored 14
			// and manufacturing flips that the code change did not cause.
			$v1_now = $regress
				? citation_pair_to_feature_vector(array($doc, $other), false)
				: null;

			$record = array(
				'a'        => $doc->_id,
				'b'        => $other->_id,
				// For the human doing the labelling.
				'a_text'   => display_citation($doc),
				'b_text'   => display_citation($other),
				'tier'     => isset($c->tier) ? $c->tier : null,
				'decision' => isset($c->decision) ? $c->decision : null,
				'score'    => isset($c->score) ? $c->score : null,
				'v1'       => isset($c->features) ? $c->features : null,
				// v1 recomputed with the current code, and what it decides now
				// (null unless --regress).
				'v1_now'    => $v1_now ? $v1_now->vector : null,
				'match_now' => $v1_now ? (is_match($v1_now->vector) ? 'match' : 'no-match') : null,
				'v2'       => $v2->vector,
				// Ground truth goes here. The decision above is heuristic-v1's
				// own output and must not be used as a label.
				'label'    => null,
			);

			fwrite($fh, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$emitted++;
		}
	}

	if (count($obj->rows) <= $want)
	{
		break;   // ran off the end of the view
	}

	fwrite(STDERR, "  $processed docs, $emitted pairs\r");
}

fclose($fh);

fwrite(STDERR, "\n");
fwrite(STDERR, "documents walked : $processed\n");
fwrite(STDERR, "pairs emitted    : $emitted\n");
fwrite(STDERR, "pairs skipped    : $skipped (candidate document missing)\n");
fwrite(STDERR, "output           : $out\n");
fwrite(STDERR, "columns          : $out.names.json (" . count($names) . " dimensions)\n");

?>
