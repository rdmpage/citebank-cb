<?php

// Show the top of the clustering queue. Read-only sanity check for watching
// records move through. Default top 5; pass an integer to show more.
//
//   php queue_status.php       # top 5
//   php queue_status.php 20    # top 20

require_once (dirname(__FILE__) . '/couchsimple.php');

$limit = isset($argv[1]) ? (int)$argv[1] : 5;

$parameters = array(
	'limit'        => $limit,
	'include_docs' => 'true',
	'reduce'       => 'false',
);
$url = '_design/queue/_view/visited?' . http_build_query($parameters);

$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
$obj = json_decode($resp);

if (!isset($obj->rows))
{
	echo "Queue view not installed, or empty.\n";
	echo $resp . "\n";
	exit(1);
}

echo "Top $limit of " . $obj->total_rows . " work records (oldest visited first):\n\n";

foreach ($obj->rows as $row)
{
	$doc       = $row->doc;
	$visited   = ($row->key === null) ? '(never)                  ' : $row->key;
	$algorithm = isset($doc->citebank->clustering->algorithm) ? $doc->citebank->clustering->algorithm : '-';
	$cluster   = isset($doc->citebank->cluster) ? $doc->citebank->cluster : '-';
	$cluster_label = ($cluster === $doc->_id) ? 'self' : $cluster;
	$comparisons = isset($doc->citebank->clustering->comparisons) ? count($doc->citebank->clustering->comparisons) : 0;

	echo "  $visited  [$algorithm]  " . $doc->_id . "\n";
	echo "    cluster: $cluster_label\n";
	echo "    comparisons logged: $comparisons\n";
}

?>
