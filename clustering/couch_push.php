<?php

// Push generated container docs (container_docs.json) into CouchDB via _bulk_docs.
//
//   php couch_push.php [--in=container_docs.json] [--batch=2000]
//
// Idempotency note: docs carry deterministic slug _ids and no _rev, so re-pushing
// the same slugs returns conflicts (reported, not fatal). To replace a prior
// generation, delete it first via the _design/container "manage" view.

ini_set('memory_limit', '1G');

require_once(__DIR__ . '/../couchsimple.php');   // $couch, $config

$opt = getopt('', array('in::', 'batch::'));
$in    = isset($opt['in'])    ? $opt['in']    : __DIR__ . '/container_docs.json';
$batch = isset($opt['batch']) ? (int)$opt['batch'] : 2000;
$dbn   = $config['couchdb_options']['database'];

$data = json_decode(file_get_contents($in));
if (!$data || !isset($data->docs)) {
	fwrite(STDERR, "No docs in $in\n");
	exit(1);
}
$docs = $data->docs;
$total = count($docs);
fwrite(STDERR, "pushing $total docs in batches of $batch...\n");

$ok = 0; $conflict = 0; $error = 0;
$errSamples = array();

for ($i = 0; $i < $total; $i += $batch) {
	$chunk = array_slice($docs, $i, $batch);
	$body = json_encode(array('docs' => $chunk), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$resp = json_decode($couch->send('POST', "/$dbn/_bulk_docs", $body));

	if (!is_array($resp)) {
		$error += count($chunk);
		if (count($errSamples) < 5) $errSamples[] = 'batch failed: ' . substr(json_encode($resp), 0, 160);
		continue;
	}
	foreach ($resp as $r) {
		if (isset($r->ok) && $r->ok) {
			$ok++;
		} elseif (isset($r->error) && $r->error === 'conflict') {
			$conflict++;
			if (count($errSamples) < 8) $errSamples[] = "conflict: " . $r->id;
		} else {
			$error++;
			if (count($errSamples) < 8) $errSamples[] = ($r->id ?? '?') . ": " . ($r->error ?? 'unknown');
		}
	}
	fwrite(STDERR, sprintf("  %d/%d  (ok=%d conflict=%d error=%d)\n", min($i + $batch, $total), $total, $ok, $conflict, $error));
}

echo "\n=== push complete ===\n";
echo "ok ........ $ok\n";
echo "conflict .. $conflict\n";
echo "error ..... $error\n";
if ($errSamples) {
	echo "samples:\n";
	foreach ($errSamples as $s) echo "  $s\n";
}
