<?php

// Build CouchDB container docs (one per cluster) from containers.db.
//
//   php couch_export.php [--run=YYYYMMDD] [--out=container_docs.json] [--db=...]
//
// Dry run: writes a _bulk_docs-ready JSON file and prints a summary. Does NOT
// touch CouchDB except to READ the existing curated docs (a later --push step
// will write).
//
// Curation: any existing container doc with "curated": true is authoritative.
// Clusters whose variants overlap a curated doc's variants are skipped entirely
// (the curated doc owns that journal), so regeneration never clobbers curation.
//
// Each generated doc (matches the hand-built template shape):
//   { "_id": "container:<slug>", "citebank": {"type":"container"},
//     "name": <canonical>, "ISSN": [...], "variants": [...raw...],
//     "cluster_run": "<run>", "junk": true|false }
//
// junk = the cluster has no "clean" variant (raw starts with a letter and has a
// significant token). junk docs are generated but excluded from listing views.

ini_set('memory_limit', '1G');

require_once(__DIR__ . '/clean.php');               // unaccent()
require_once(__DIR__ . '/../couchsimple.php');      // $couch, $config

$opt = getopt('', array('run::', 'out::', 'db::'));
$run    = isset($opt['run']) ? preg_replace('/\D/', '', $opt['run']) : date('Ymd');
$dbPath = isset($opt['db'])  ? $opt['db']  : __DIR__ . '/containers.db';
$out    = isset($opt['out']) ? $opt['out'] : __DIR__ . '/container_docs.json';

//----------------------------------------------------------------------------------------
function slugify($name)
{
	$s = unaccent($name);
	$s = mb_convert_case($s, MB_CASE_LOWER);
	$s = preg_replace('/[^a-z0-9]+/', '-', $s);
	$s = trim($s, '-');
	if ($s === '') $s = 'x';
	if (strlen($s) > 80) $s = substr($s, 0, 80) . '-' . substr(md5($name), 0, 6);
	return $s;
}

// A raw string is "clean" if it starts with a letter and has significant tokens.
function is_clean($raw, $key_a)
{
	if ($key_a === '') return false;
	return (bool)preg_match('/^\p{L}/u', preg_replace('/^\s+/u', '', $raw));
}

//----------------------------------------------------------------------------------------
// Fetch curated container docs from CouchDB: variants (to absorb) and slugs (to reserve).
function fetch_curated($couch, $dbn)
{
	$variants = array();   // raw string => owning curated _id
	$slugs = array();      // reserved slugs
	try {
		$q = json_encode(array(
			'selector' => array('citebank.type' => 'container', 'curated' => true),
			'fields'   => array('_id', 'variants'),
			'limit'    => 100000,
		));
		$resp = json_decode($couch->send('POST', "/$dbn/_find", $q));
		if (isset($resp->docs)) {
			foreach ($resp->docs as $d) {
				$slugs[$d->_id] = true;
				if (isset($d->variants)) {
					foreach ($d->variants as $v) $variants[$v] = $d->_id;
				}
			}
		}
		fwrite(STDERR, "curated docs loaded: " . count($slugs) . " (" . count($variants) . " owned variants)\n");
	} catch (Exception $e) {
		fwrite(STDERR, "WARNING: could not read curated docs (" . $e->getMessage() . "); generating without curation guard.\n");
	}
	return array($variants, $slugs);
}

//----------------------------------------------------------------------------------------

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

list($curatedVariants, $usedSlugs) = fetch_curated($couch, $config['couchdb_options']['database']);

// gather variants per cluster
$stmt = $db->query("SELECT cluster_id, raw, key_a, COUNT(*) AS n FROM containers GROUP BY cluster_id, raw");
$clusters = array();
foreach ($stmt as $r) {
	$clusters[(int)$r['cluster_id']][] = array('raw' => $r['raw'], 'key_a' => $r['key_a'], 'n' => (int)$r['n']);
}
// distinct ISSNs per cluster
$issns = array();
foreach ($db->query("SELECT cluster_id, issn FROM containers WHERE issn IS NOT NULL AND issn != '' GROUP BY cluster_id, issn") as $r) {
	$issns[(int)$r['cluster_id']][] = $r['issn'];
}

$docs = array();
$nJunk = 0; $nAbsorbed = 0; $nCollision = 0;
$junkSamples = array(); $absorbedSamples = array(); $partialSamples = array();

foreach ($clusters as $cid => $variants) {
	// curation absorption: does any variant belong to a curated doc?
	$owners = array();
	foreach ($variants as $v) {
		if (isset($curatedVariants[$v['raw']])) $owners[$curatedVariants[$v['raw']]] = true;
	}
	if ($owners) {
		$nAbsorbed++;
		$claimed = 0;
		foreach ($variants as $v) if (isset($curatedVariants[$v['raw']])) $claimed++;
		// partial overlap = cluster has variants the curated doc does NOT list (review these)
		if ($claimed < count($variants) && count($partialSamples) < 15) {
			$partialSamples[] = implode(' | ', array_keys($owners)) . "  <- also has "
				. ($claimed) . "/" . count($variants) . " claimed; e.g. " . $variants[0]['raw'];
		}
		if (count($absorbedSamples) < 8) $absorbedSamples[] = implode(', ', array_keys($owners));
		continue;
	}

	$clean = array_filter($variants, function ($v) { return is_clean($v['raw'], $v['key_a']); });
	$junk = (count($clean) === 0);

	$pool = $junk ? $variants : array_values($clean);
	usort($pool, function ($a, $b) {
		if ($a['n'] !== $b['n']) return $b['n'] - $a['n'];
		return mb_strlen($b['raw']) - mb_strlen($a['raw']);
	});
	$canonical = $pool[0]['raw'];

	usort($variants, function ($a, $b) { return $b['n'] - $a['n']; });
	$variantList = array_map(function ($v) { return $v['raw']; }, $variants);

	// slug id with collision handling (curated slugs already reserved)
	$base = 'container:' . slugify($canonical);
	$id = $base; $k = 1;
	while (isset($usedSlugs[$id])) { $id = $base . '-' . (++$k); $nCollision++; }
	$usedSlugs[$id] = true;

	$doc = array(
		'_id'         => $id,
		'citebank'    => array('type' => 'container'),
		'name'        => $canonical,
		'variants'    => $variantList,
		'cluster_run' => $run,
		'junk'        => $junk,
	);
	if (isset($issns[$cid])) $doc['ISSN'] = $issns[$cid];
	$docs[] = $doc;

	if ($junk) { $nJunk++; if (count($junkSamples) < 20) $junkSamples[] = $canonical; }
}

file_put_contents($out, json_encode(array('docs' => $docs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// summary
$total = count($docs);
fwrite(STDERR, sprintf("run=%s  wrote %s\n", $run, $out));
printf("\nclusters .............. %d\n", count($clusters));
printf("  absorbed by curation  %d  (skipped — curated doc owns them)\n", $nAbsorbed);
printf("  generated docs ...... %d\n", $total);
printf("    clean (listed) .... %d\n", $total - $nJunk);
printf("    junk (review) ..... %d\n", $nJunk);
printf("slug collisions ....... %d (disambiguated with -2, -3, ...)\n", $nCollision);
printf("file size ............. %.1f MB\n", filesize($out) / 1048576);

if ($absorbedSamples) {
	echo "\nabsorbed by curated docs (sample):\n";
	foreach ($absorbedSamples as $s) echo "  $s\n";
}
if ($partialSamples) {
	echo "\nPARTIAL overlaps — cluster has variants the curated doc lacks (review/add):\n";
	foreach ($partialSamples as $s) echo "  " . mb_strimwidth($s, 0, 90) . "\n";
}
echo "\nsample JUNK clusters (flagged for parser review):\n";
foreach ($junkSamples as $s) echo "  " . mb_strimwidth($s, 0, 70) . "\n";
