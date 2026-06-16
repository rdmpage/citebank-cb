<?php

// DEV-ONLY endpoint: serve the BioStor coverage match-map for a container from the
// side SQLite store (biostor/biostor_coverage.db). NOT part of the public release —
// it reads exploration data, never the CouchDB work docs. If the store is absent it
// returns an empty map, so a public deploy that omits this file/data is harmless.
//
//   biostor.php?cid=container:<slug>
//   -> { cid, checked, matches: { <cluster_id>: { matched, biostor_id, score } } }

header('Content-Type: application/json');

$dbPath = __DIR__ . '/biostor/biostor_coverage.db';

$out = new stdclass;
$out->cid = isset($_GET['cid']) ? $_GET['cid'] : '';
$out->checked = null;
$out->matches = new stdclass;

if ($out->cid !== '' && file_exists($dbPath))
{
	try
	{
		$db = new PDO('sqlite:' . $dbPath);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$stmt = $db->prepare('SELECT cluster_id, biostor_id, score, matched, checked FROM coverage WHERE cid = :cid');
		$stmt->execute(array(':cid' => $out->cid));
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
