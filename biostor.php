<?php

// DEV-ONLY endpoint for the BioStor coverage view. NOT part of the public release.
// Coverage is per work-cluster, so it serves containers and authors alike.
//
//   POST biostor.php           body {"ids":[...]}     -> match map for those clusters
//   POST biostor.php?check=1&cid=container:<slug>     -> reconcile a container now (dev)
//   POST biostor.php?check=1&family=<name>            -> reconcile an author now (dev)
//
// Reads are a plain SQLite lookup (no CouchDB); only the dev-gated check gathers and
// reconciles. The match data lives only in the side store, never in the work docs.

header('Content-Type: application/json');

$dbPath = __DIR__ . '/biostor/biostor_coverage.db';

$isCheck = isset($_GET['check']);
$cid     = isset($_GET['cid'])    ? $_GET['cid']    : '';
$family  = isset($_GET['family']) ? $_GET['family'] : '';

$out = new stdclass;
$out->checked = null;
$out->matches = new stdclass;

$ids = array();

if ($isCheck && $_SERVER['REQUEST_METHOD'] === 'POST' && ($cid !== '' || $family !== ''))
{
	// --- on-demand reconcile (dev instances only) ---------------------------
	require_once(__DIR__ . '/couchsimple.php');          // $couch, $config
	require_once(__DIR__ . '/biostor/reconcile_lib.php');

	if (empty($config['dev']))
	{
		http_response_code(403);
		echo json_encode(array('error' => 'on-demand check is disabled (not a dev instance)'));
		exit;
	}

	set_time_limit(0);   // a large scope can take a while
	$dbn = $config['couchdb_options']['database'];

	if ($cid !== '')
	{
		$clusters = bio_gather_clusters($couch, $dbn, $cid);
		$label = $cid;
	}
	else
	{
		$clusters = bio_gather_clusters_by_author($couch, $dbn, $family);
		$label = 'author:' . $family;
	}

	$store = bio_open_store($dbPath);
	bio_run_reconcile($clusters, $store, $label, array('sleep' => 0.1));
	$ids = array_keys($clusters);   // return the freshly-written rows
}
else
{
	// --- pure read: cluster ids supplied by the client ----------------------
	$body = json_decode(file_get_contents('php://input'));
	if ($body && isset($body->ids) && is_array($body->ids))
	{
		$ids = $body->ids;
	}
}

// --- look up matches for the requested work-clusters ------------------------
if ($ids && file_exists($dbPath))
{
	try
	{
		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		foreach (array_chunk($ids, 400) as $chunk)
		{
			$place = implode(',', array_fill(0, count($chunk), '?'));
			$stmt = $db->prepare("SELECT cluster_id, biostor_id, score, matched, checked FROM coverage WHERE cluster_id IN ($place)");
			$stmt->execute(array_values($chunk));
			foreach ($stmt as $r)
			{
				$m = new stdclass;
				$m->matched    = ((int)$r['matched']) === 1;
				$m->biostor_id = $r['biostor_id'];
				$m->score      = $r['score'] !== null ? (float)$r['score'] : null;
				$out->matches->{$r['cluster_id']} = $m;
				if ($out->checked === null || $r['checked'] > $out->checked)
				{
					$out->checked = $r['checked'];
				}
			}
		}
	}
	catch (Exception $e)
	{
		$out->error = $e->getMessage();
	}
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
