<?php

// DEV-ONLY endpoint for the BioStor coverage view. NOT part of the public release.
//
//   GET  biostor.php?cid=container:<slug>            -> match map from the side store
//   POST biostor.php?cid=container:<slug>&check=1    -> reconcile this container now
//                                                       (dev only), then return the map
//
// The match data lives only in the side SQLite store, never in the CouchDB work docs.

header('Content-Type: application/json');

$dbPath = __DIR__ . '/biostor/biostor_coverage.db';
$cid = isset($_GET['cid']) ? $_GET['cid'] : '';

// --- on-demand "Check now" (dev instances only) -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['check']) && $cid !== '')
{
	require_once(__DIR__ . '/couchsimple.php');          // $couch, $config
	require_once(__DIR__ . '/biostor/reconcile_lib.php');

	if (empty($config['dev']))
	{
		http_response_code(403);
		echo json_encode(array('error' => 'on-demand check is disabled (not a dev instance)'));
		exit;
	}

	set_time_limit(0);   // a large container can take a while
	$store = bio_open_store($dbPath);
	bio_run_reconcile($couch, $config['couchdb_options']['database'], $cid, $store, array('sleep' => 0.1));
	// fall through to return the freshly-written map
}

// --- read + return the match map --------------------------------------------
$out = new stdclass;
$out->cid = $cid;
$out->checked = null;
$out->matches = new stdclass;

if ($cid !== '' && file_exists($dbPath))
{
	try
	{
		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$stmt = $db->prepare('SELECT cluster_id, biostor_id, score, matched, checked FROM coverage WHERE cid = :cid');
		$stmt->execute(array(':cid' => $cid));
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
	catch (Exception $e)
	{
		$out->error = $e->getMessage();
	}
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
