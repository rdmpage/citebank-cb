<?php

// Prototype: probe BioStor coverage for one container cluster via the BioStor
// reconciliation service (https://biostor.org/reconcile).
//
//   php reconcile.php --cid container:novititates-zoologicae
//        [--db biostor_coverage.db] [--batch 20] [--limit N] [--sleep 0.3]
//
// EXPLORATION TOOL. Results go into a side SQLite store, never into the CouchDB
// work docs — BioStor coverage is meant to fall out of clustering once BioStor is
// ingested, so we don't bake a BioStor-specific flag into the database.
//
// BioStor's reconcile matches a *citation string* (title + journal + volume:pages +
// year), not a bare title; it returns id / name / score / match per query. POST is
// form-encoded `queries=` (a JSON body just returns the service manifest).

ini_set('memory_limit', '1G');

require_once(__DIR__ . '/../couchsimple.php');   // $couch, $config

// Single colon (required-value) so the space form "--limit 20" works; omit an
// option entirely to take its default.
$opt = getopt('', array('cid:', 'db:', 'batch:', 'limit:', 'sleep:', 'endpoint:'));
if (!isset($opt['cid']))
{
	fwrite(STDERR, "usage: php reconcile.php --cid container:<slug> [--db ...] [--batch 20] [--limit N] [--sleep 0.3]\n");
	exit(1);
}
$cid      = $opt['cid'];
$dbPath   = isset($opt['db'])       ? $opt['db']            : __DIR__ . '/biostor_coverage.db';
$batch    = isset($opt['batch'])    ? (int)$opt['batch']    : 20;
$limit    = isset($opt['limit'])    ? (int)$opt['limit']    : 0;        // 0 = all
$sleep    = isset($opt['sleep'])    ? (float)$opt['sleep']  : 0.3;      // seconds between batches
$endpoint = isset($opt['endpoint']) ? $opt['endpoint']      : 'https://biostor.org/reconcile';

$dbn = $config['couchdb_options']['database'];

//----------------------------------------------------------------------------------------
// Gather the container's works, grouped by work-cluster (citebank.cluster). One
// representative doc per cluster, so we reconcile each distinct article once.
function gather_clusters($couch, $dbn, $cid)
{
	$container = json_decode($couch->send("GET", "/$dbn/" . urlencode($cid)));
	if (!$container || !isset($container->variants) || !is_array($container->variants))
	{
		return array();
	}

	$byCluster = array();
	foreach ($container->variants as $variant)
	{
		$params = array(
			'startkey'     => json_encode(array($variant, 0), JSON_UNESCAPED_UNICODE),
			'endkey'       => json_encode(array($variant, 2030, new stdclass), JSON_UNESCAPED_UNICODE),
			'reduce'       => 'false',
			'include_docs' => 'true',
		);
		$url = '_design/interface/_view/container-year-page?' . http_build_query($params);
		$resp = json_decode($couch->send("GET", "/$dbn/" . $url));
		if (!isset($resp->rows)) continue;

		foreach ($resp->rows as $row)
		{
			$doc = $row->doc;
			$cl = isset($doc->citebank->cluster) ? $doc->citebank->cluster : $doc->_id;
			// Prefer the cluster representative (doc whose _id is the cluster id).
			if (!isset($byCluster[$cl]) || $doc->_id === $cl)
			{
				$byCluster[$cl] = $doc;
			}
		}
	}
	return $byCluster;
}

//----------------------------------------------------------------------------------------
// Build the citation string BioStor matches on: "Title. Journal Vol: Pages. Year".
function citation_string($doc)
{
	$title = isset($doc->title) ? trim($doc->title) : '';
	$container = '';
	if (isset($doc->{'container-title'}))
	{
		$container = $doc->{'container-title'};
		if (is_array($container)) $container = isset($container[0]) ? $container[0] : '';
	}
	$vol = isset($doc->volume) ? (string)$doc->volume : '';
	$page = '';
	if (isset($doc->page)) $page = (string)$doc->page;
	else if (isset($doc->{'page-first'})) $page = (string)$doc->{'page-first'};
	$year = isset($doc->issued->{'date-parts'}[0][0]) ? (string)$doc->issued->{'date-parts'}[0][0] : '';

	$s = '';
	if ($title !== '')     $s .= rtrim($title, '. ') . '. ';
	if ($container !== '') $s .= $container . ' ';
	if ($vol !== '')       $s .= $vol;
	if ($page !== '')      $s .= ($vol !== '' ? ': ' : 'pp. ') . $page;
	if ($vol !== '' || $page !== '') $s .= '. ';
	if ($year !== '')      $s .= $year;
	return trim(preg_replace('/\s+/u', ' ', $s));
}

//----------------------------------------------------------------------------------------
// POST a batch of citation strings (qid => string) to the reconcile endpoint.
function reconcile_batch($endpoint, $queries)
{
	$q = array();
	foreach ($queries as $qid => $str)
	{
		$q[$qid] = array('query' => $str);
	}
	$payload = 'queries=' . urlencode(json_encode($q, JSON_UNESCAPED_UNICODE));

	$ch = curl_init($endpoint);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => $payload,
		CURLOPT_TIMEOUT        => 90,
		CURLOPT_HTTPHEADER     => array('Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'),
		CURLOPT_USERAGENT      => 'CiteBank-BioStor-coverage/0.1',
	));
	$resp = curl_exec($ch);
	$err = curl_error($ch);
	curl_close($ch);

	if ($resp === false)
	{
		fwrite(STDERR, "  curl error: $err\n");
		return null;
	}
	return json_decode($resp);
}

//----------------------------------------------------------------------------------------

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('
	CREATE TABLE IF NOT EXISTS coverage (
		cluster_id   TEXT PRIMARY KEY,
		cid          TEXT,
		title        TEXT,
		query        TEXT,
		biostor_id   TEXT,
		biostor_name TEXT,
		score        REAL,
		matched      INTEGER,
		checked      TEXT
	)');
$upsert = $db->prepare('
	INSERT INTO coverage (cluster_id, cid, title, query, biostor_id, biostor_name, score, matched, checked)
	VALUES (:cluster_id, :cid, :title, :query, :biostor_id, :biostor_name, :score, :matched, :checked)
	ON CONFLICT(cluster_id) DO UPDATE SET
		cid=:cid, title=:title, query=:query, biostor_id=:biostor_id, biostor_name=:biostor_name,
		score=:score, matched=:matched, checked=:checked');

$clusters = gather_clusters($couch, $dbn, $cid);
$ids = array_keys($clusters);
if ($limit > 0) $ids = array_slice($ids, 0, $limit);
fwrite(STDERR, count($ids) . " work-clusters to check for $cid\n");

$now = date('c', time());
$matched = 0; $unmatched = 0; $skipped = 0;
$scores = array();
$gaps = array();

for ($i = 0; $i < count($ids); $i += $batch)
{
	$slice = array_slice($ids, $i, $batch);
	$queries = array();
	$qidToCluster = array();
	$n = 0;
	foreach ($slice as $cl)
	{
		$qstr = citation_string($clusters[$cl]);
		if ($qstr === '') { $skipped++; continue; }
		$qid = 'q' . $n++;
		$queries[$qid] = $qstr;
		$qidToCluster[$qid] = $cl;
	}
	if (!$queries) continue;

	$resp = reconcile_batch($endpoint, $queries);

	$db->beginTransaction();
	foreach ($qidToCluster as $qid => $cl)
	{
		$doc = $clusters[$cl];
		$title = isset($doc->title) ? $doc->title : '';
		$best = null;
		if ($resp && isset($resp->$qid->result) && count($resp->$qid->result) > 0)
		{
			$best = $resp->$qid->result[0];
		}

		$bid = $best ? $best->id : null;
		$bname = $best ? $best->name : null;
		$score = $best ? (float)$best->score : null;
		$isMatch = $best && isset($best->match) ? ($best->match ? 1 : 0) : 0;

		$upsert->execute(array(
			':cluster_id'   => $cl,
			':cid'          => $cid,
			':title'        => $title,
			':query'        => $queries[$qid],
			':biostor_id'   => $bid,
			':biostor_name' => $bname,
			':score'        => $score,
			':matched'      => $isMatch,
			':checked'      => $now,
		));

		if ($isMatch) { $matched++; $scores[] = $score; }
		else { $unmatched++; if (count($gaps) < 20) $gaps[] = $title; }
	}
	$db->commit();

	fwrite(STDERR, sprintf("  %d/%d  (matched=%d unmatched=%d)\n", min($i + $batch, count($ids)), count($ids), $matched, $unmatched));
	if ($sleep > 0 && $i + $batch < count($ids)) usleep((int)($sleep * 1e6));
}

// --- summary ----------------------------------------------------------------
$total = $matched + $unmatched;
echo "\n=== BioStor coverage: $cid ===\n";
echo "work-clusters checked .. " . count($ids) . ($skipped ? " ($skipped skipped, no citation)" : "") . "\n";
printf("matched in BioStor ..... %d (%.1f%%)\n", $matched, $total ? 100.0 * $matched / $total : 0);
printf("not matched (gaps) ..... %d\n", $unmatched);

if ($scores)
{
	sort($scores);
	$min = $scores[0];
	$max = end($scores);
	$mid = $scores[(int)(count($scores) / 2)];
	printf("match score: min %.3f · median %.3f · max %.3f\n", $min, $mid, $max);

	// low-confidence matches worth eyeballing
	$low = $db->query("SELECT score, title, biostor_id FROM coverage WHERE matched=1 ORDER BY score ASC LIMIT 5");
	echo "lowest-confidence matches:\n";
	foreach ($low as $r) printf("  %.3f  biostor/%s  %s\n", $r['score'], $r['biostor_id'], mb_strimwidth($r['title'], 0, 55));
}

if ($gaps)
{
	echo "sample gaps (not in BioStor):\n";
	foreach ($gaps as $g) echo "  " . mb_strimwidth($g, 0, 65) . "\n";
}
echo "\nstored in $dbPath\n";
