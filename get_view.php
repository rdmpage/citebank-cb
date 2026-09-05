<?php

// Dump the raw container-title / ISSN pairs that feed the container clustering
// pipeline, as container.tsv:
//
//   php get_view.php > container.tsv
//
// Output is one row per distinct (container-title, ISSN) pair seen on a work
// record, tab separated, with an empty second column where no ISSN is known.
// That file is the input to clustering/ (see CONTAINER_CLUSTERING.md), which
// produces container_docs.json, which couch_push.php loads back into CouchDB as
// the container docs.
//
// History, because this bit the project once already: the original version of
// this script read _design/container/_view/containers, a view that was created
// by hand and never committed. On 2026-06-14 _design/container was rewritten to
// hold the container clustering *output* and that view was overwritten, which
// silently broke regeneration -- container.tsv survived only because nobody
// deleted it. The view now lives in its own design document (couchdb/source.js,
// _design/source) precisely so that regenerating the container docs cannot
// clobber the thing needed to regenerate their input.
//
// Note this only works while the works database is populated: container.tsv is
// derived from doc['container-title'] and doc.ISSN across every work record.

ini_set('memory_limit', '-1');

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/couchsimple.php');

$url = '/' . $config['couchdb_options']['database']
	. '/_design/source/_view/container-titles?group_level=2';

$resp = $couch->send("GET", $url);

$obj = json_decode($resp);

if (!$obj || !isset($obj->rows))
{
	fwrite(STDERR, "Failed to read _design/source/_view/container-titles\n");
	fwrite(STDERR, "Response: " . substr($resp, 0, 500) . "\n");
	fwrite(STDERR, "\nIf this 404s, the design document needs (re)loading:\n");
	fwrite(STDERR, "  curl -X PUT \$COUCH/citebank/_design/source \\\n");
	fwrite(STDERR, "       -H 'Content-Type: application/json' --data-binary \@couchdb/source.js\n");
	fwrite(STDERR, "A first build over ~1M documents takes a few minutes.\n");
	exit(1);
}

foreach ($obj->rows as $row)
{
	echo join("\t", $row->key) . "\n";
}

fwrite(STDERR, count($obj->rows) . " rows\n");

?>
