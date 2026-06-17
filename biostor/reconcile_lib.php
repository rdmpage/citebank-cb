<?php

// Shared BioStor reconciliation core, used by both the CLI (reconcile.php) and the
// dev "Check now" endpoint (../biostor.php). No output of its own — callers report.

//----------------------------------------------------------------------------------------
// Open (creating if needed) the side coverage store.
function bio_open_store($dbPath)
{
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
	return $db;
}

//----------------------------------------------------------------------------------------
// Gather a container's works grouped by work-cluster (one representative each).
function bio_gather_clusters($couch, $dbn, $cid)
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
			if (!isset($byCluster[$cl]) || $doc->_id === $cl)
			{
				$byCluster[$cl] = $doc;
			}
		}
	}
	return $byCluster;
}

//----------------------------------------------------------------------------------------
// Gather an author's works grouped by work-cluster (one representative each).
function bio_gather_clusters_by_author($couch, $dbn, $family)
{
	$params = array(
		'startkey'     => json_encode(array($family, 0), JSON_UNESCAPED_UNICODE),
		'endkey'       => json_encode(array($family, 2030, new stdclass), JSON_UNESCAPED_UNICODE),
		'reduce'       => 'false',
		'include_docs' => 'true',
	);
	$url = '_design/interface/_view/family-year?' . http_build_query($params);
	$resp = json_decode($couch->send("GET", "/$dbn/" . $url));

	$byCluster = array();
	if (isset($resp->rows))
	{
		foreach ($resp->rows as $row)
		{
			$doc = $row->doc;
			$cl = isset($doc->citebank->cluster) ? $doc->citebank->cluster : $doc->_id;
			if (!isset($byCluster[$cl]) || $doc->_id === $cl)
			{
				$byCluster[$cl] = $doc;
			}
		}
	}
	return $byCluster;
}

//----------------------------------------------------------------------------------------
// Citation string BioStor matches on: "Title. Journal Vol: Pages. Year".
function bio_citation_string($doc)
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
function bio_reconcile_batch($endpoint, $queries)
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
	curl_close($ch);
	return $resp === false ? null : json_decode($resp);
}

//----------------------------------------------------------------------------------------
// Reconcile a set of work-clusters into the store. $clusters is cluster_id => doc
// (from bio_gather_clusters / bio_gather_clusters_by_author); $cidLabel is stored
// as provenance in the `cid` column (the match itself is per work-cluster). Returns
// counts. $onProgress($done,$total,$matched,$unmatched) is called per batch.
function bio_run_reconcile($clusters, $store, $cidLabel, $opts = array(), $onProgress = null)
{
	$batch    = isset($opts['batch'])    ? (int)$opts['batch']   : 20;
	$limit    = isset($opts['limit'])    ? (int)$opts['limit']   : 0;
	$sleep    = isset($opts['sleep'])    ? (float)$opts['sleep'] : 0.3;
	$endpoint = isset($opts['endpoint']) ? $opts['endpoint']     : 'https://biostor.org/reconcile';

	$ids = array_keys($clusters);
	if ($limit > 0) $ids = array_slice($ids, 0, $limit);

	$upsert = $store->prepare('
		INSERT INTO coverage (cluster_id, cid, title, query, biostor_id, biostor_name, score, matched, checked)
		VALUES (:cluster_id, :cid, :title, :query, :biostor_id, :biostor_name, :score, :matched, :checked)
		ON CONFLICT(cluster_id) DO UPDATE SET
			cid=:cid, title=:title, query=:query, biostor_id=:biostor_id, biostor_name=:biostor_name,
			score=:score, matched=:matched, checked=:checked');

	$now = date('c', time());
	$matched = 0; $unmatched = 0; $skipped = 0;
	$total = count($ids);

	for ($i = 0; $i < $total; $i += $batch)
	{
		$slice = array_slice($ids, $i, $batch);
		$queries = array();
		$qidToCluster = array();
		$n = 0;
		foreach ($slice as $cl)
		{
			$qstr = bio_citation_string($clusters[$cl]);
			if ($qstr === '') { $skipped++; continue; }
			$qid = 'q' . $n++;
			$queries[$qid] = $qstr;
			$qidToCluster[$qid] = $cl;
		}
		if (!$queries) continue;

		$resp = bio_reconcile_batch($endpoint, $queries);

		$store->beginTransaction();
		foreach ($qidToCluster as $qid => $cl)
		{
			$doc = $clusters[$cl];
			$best = ($resp && isset($resp->$qid->result) && count($resp->$qid->result) > 0) ? $resp->$qid->result[0] : null;
			$isMatch = $best && isset($best->match) && $best->match ? 1 : 0;

			$upsert->execute(array(
				':cluster_id'   => $cl,
				':cid'          => $cidLabel,
				':title'        => isset($doc->title) ? $doc->title : '',
				':query'        => $queries[$qid],
				':biostor_id'   => $best ? $best->id : null,
				':biostor_name' => $best ? $best->name : null,
				':score'        => $best ? (float)$best->score : null,
				':matched'      => $isMatch,
				':checked'      => $now,
			));
			if ($isMatch) $matched++; else $unmatched++;
		}
		$store->commit();

		if ($onProgress) call_user_func($onProgress, min($i + $batch, $total), $total, $matched, $unmatched);
		if ($sleep > 0 && $i + $batch < $total) usleep((int)($sleep * 1e6));
	}

	return array('checked' => $total, 'matched' => $matched, 'unmatched' => $unmatched, 'skipped' => $skipped);
}
