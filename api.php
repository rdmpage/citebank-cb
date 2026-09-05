<?php

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/api_utils.php');
require_once (dirname(__FILE__) . '/couchsimple.php');
require_once (dirname(__FILE__) . '/csl_features.php');
require_once (dirname(__FILE__) . '/merge_csl.php');


//--------------------------------------------------------------------------------------------------
// Parse JSON and return any errors
function parse_json($json)
{
	$doc = json_decode($json);
	
	$error = new stdclass;
	$error->code = json_last_error();
		
	switch ($error->code) 
	{
		case JSON_ERROR_NONE:
			$error->msg = 'No errors';
			break;
		case JSON_ERROR_DEPTH:
			$error->msg = 'Maximum stack depth exceeded';
			break;
		case JSON_ERROR_STATE_MISMATCH:
			$error->msg = 'Underflow or the modes mismatch';
			break;
		case JSON_ERROR_CTRL_CHAR:
			$error->msg = 'Unexpected control character found';
			break;
		case JSON_ERROR_SYNTAX:
			$error->msg = 'Syntax error, malformed JSON';
			break;
		case JSON_ERROR_UTF8:
			$error->msg = 'Malformed UTF-8 characters, possibly incorrectly encoded';
			break;
		default:
			$error->msg = 'Unknown error';
			break;
	}
	
	return $error;
}

//--------------------------------------------------------------------------------------------------
function get_one_record($id)
{
	global $config;
	global $couch;
	
	$obj = null;	
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . urlencode($id));
	
	$obj = json_decode($resp);
	
	return $obj;
}

//--------------------------------------------------------------------------------------------------
function get_multiple_records($ids)
{
	global $config;
	global $couch;
	
	$result = array();

	foreach ($ids as $id)
	{
		$obj = get_one_record($id);
		
		if ($obj)
		{
			$result[] = $obj;
		}
	}
	
	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_consensus($records)
{
	$result = merge($records, []);
	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_from_doi($doi)
{
	global $config;
	global $couch;
	
	$result = array();
	
	// clean
	$doi = preg_replace('/https?:\/\/(dx\.)?doi.org\//i', '', $doi);
	
	$doi = strtolower($doi);
	
	$parameters = array(
		'key' 			=> '"' . $doi . '"',
		'reduce' 		=> 'false',
		'include_docs' 	=> 'true',
	);

	$url = '_design/interface/_view/doi?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[] = $row->doc;
		}
	}
	
	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_containers_first_letters()
{
	global $config;
	global $couch;
	
	$result = array();

	// Clustered container docs, grouped by (folded) first letter.
	$parameters = array(
		'reduce' 		=> 'true',
		'group_level' 	=> 1,
	);

	$url = '_design/container/_view/container-list?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj && isset($response_obj->rows))
	{
		foreach ($response_obj->rows as $row)
		{
			$result[$row->key[0]] = $row->value;
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_containers_by_letter($letter)
{
	global $config;
	global $couch;
	
	$result = array();
	
	$startkey = array($letter);
	$endkey = array($letter, new stdclass);

	// List the clustered container docs under this letter: id (for ?cid=),
	// canonical name, and variant count.
	$parameters = array(
		'startkey' 		=> json_encode($startkey, JSON_UNESCAPED_UNICODE),
		'endkey'		=> json_encode($endkey, JSON_UNESCAPED_UNICODE),
		'reduce' 		=> 'false',
	);

	$url = '_design/container/_view/container-list?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj && isset($response_obj->rows))
	{
		foreach ($response_obj->rows as $row)
		{
			$entry = new stdclass;
			$entry->id    = $row->id;
			$entry->name  = $row->key[1];
			$entry->count = $row->value;
			$result[] = $entry;
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_family_first_letters()
{
	global $config;
	global $couch;
	
	$result = array();
	
	$parameters = array(
		'reduce' 		=> 'true',
		'group_level' 	=> 2,
	);

	$url = '_design/interface/_view/family-letter-first?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[$row->key] = $row->value;
		}
	}
	
	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_authors_by_letter($letter)
{
	global $config;
	global $couch;
	
	$result = array();
	
	$startkey = array($letter);
	$endkey = array($letter, new stdclass);
	
	$parameters = array(
		'startkey' 		=> json_encode($startkey),
		'endkey'		=> json_encode($endkey),
		'reduce' 		=> 'true',
		'group_level' 	=> 2,
	);

	$url = '_design/interface/_view/family-letter?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[] = $row->key[1];
		}
	}
	
	return $result;
}

//--------------------------------------------------------------------------------------------------
function simplify_csl($csl)
{
	$keys = array('_id', 'author', 'title', 'container-title', 'ISSN', 'volume', 'issue', 'page', 'issued', 'DOI');
	
	foreach ($csl as $k => $v)
	{
		if (!in_array($k, $keys))
		{
			unset($csl->{$k});
		}
	}

	return $csl;
}

//--------------------------------------------------------------------------------------------------
// A container this size cannot be fetched in one go: Zootaxa alone is ~48,000
// works, which is 61MB of CouchDB response before json_decode() expands it, and
// far more rows than the page can usefully render. Above this threshold callers
// are expected to ask for a single year at a time (see get_container_year_counts).
define('CONTAINER_WORKS_MAX', 5000);

//--------------------------------------------------------------------------------------------------
// Number of works per year for one or more raw container titles, summed across
// them. Uses the reduce side of container-year-page, so this stays cheap even
// for the largest containers and lets the caller decide whether it can afford
// to fetch the works themselves.
function get_container_year_counts($variants)
{
	global $config;
	global $couch;

	$counts = array();

	foreach ((array)$variants as $variant)
	{
		$parameters = array(
			'startkey'		=> json_encode(array($variant, 0), JSON_UNESCAPED_UNICODE),
			'endkey'		=> json_encode(array($variant, 2030, new stdclass), JSON_UNESCAPED_UNICODE),
			'reduce'		=> 'true',
			'group_level'	=> 2
		);

		$url = '_design/interface/_view/container-year-page?' . http_build_query($parameters);

		$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

		$response_obj = json_decode($resp);

		if (!$response_obj || !isset($response_obj->rows))
		{
			continue;
		}

		foreach ($response_obj->rows as $row)
		{
			$year = $row->key[1];
			$counts[$year] = ($counts[$year] ?? 0) + $row->value;
		}
	}

	ksort($counts);

	return $counts;
}

//--------------------------------------------------------------------------------------------------
// $year restricts the result to a single publication year; without it every year
// is returned, which is only safe for containers under CONTAINER_WORKS_MAX.
function get_works_by_container($container, $year = null)
{
	global $config;
	global $couch;

	$result = array();
	$counts = array();
	$kept_is_rep = array();

	if ($year === null)
	{
		$startkey = array($container, 0);
		$endkey = array($container, 2030, new stdclass);
	}
	else
	{
		$startkey = array($container, (Integer)$year);
		$endkey = array($container, (Integer)$year, new stdclass);
	}

	$parameters = array(
		'startkey' 		=> json_encode($startkey, JSON_UNESCAPED_UNICODE),
		'endkey'		=> json_encode($endkey, JSON_UNESCAPED_UNICODE),
		'reduce' 		=> 'false',
		//'group_level' 	=> 2,
		'include_docs'	=> 'true'
	);

	$url = '_design/interface/_view/container-year-page?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$year    = $row->key[1];
			$cluster = $row->doc->citebank->cluster;
			$is_rep  = ($row->doc->_id === $cluster);

			if (!isset($result[$year]))
			{
				$result[$year]       = array();
				$counts[$year]       = array();
				$kept_is_rep[$year]  = array();
			}

			$counts[$year][$cluster] = ($counts[$year][$cluster] ?? 0) + 1;

			// keep the representative (doc whose _id matches cluster id);
			// fall back to whatever we saw first if the rep is absent from this slice
			if (!isset($result[$year][$cluster]) || ($is_rep && !$kept_is_rep[$year][$cluster]))
			{
				$result[$year][$cluster]      = simplify_csl($row->doc);
				$kept_is_rep[$year][$cluster] = $is_rep;
			}
		}

		foreach ($result as $year => $clusters)
		{
			foreach ($clusters as $cluster => $csl)
			{
				$envelope = new stdclass;
				$envelope->csl          = $csl;
				$envelope->cluster_size = $counts[$year][$cluster];
				$result[$year][$cluster] = $envelope;
			}
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
// Reverse lookup: which container cluster (if any) lists this exact raw spelling?
// Uses the _design/container "variant" view (raw string -> container _id).
function get_container_for_variant($variant)
{
	global $config;
	global $couch;

	$result = null;

	$parameters = array(
		'key'    => json_encode($variant, JSON_UNESCAPED_UNICODE),
		'reduce' => 'false',
	);
	$url = '_design/container/_view/variant?' . http_build_query($parameters);
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$obj = json_decode($resp);

	if ($obj && isset($obj->rows) && count($obj->rows) > 0)
	{
		$cid = $obj->rows[0]->value;
		$doc = json_decode($couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . urlencode($cid)));

		$result = new stdclass;
		$result->id   = $cid;
		$result->name = isset($doc->name) ? $doc->name : $cid;
		$result->junk = isset($doc->junk) ? $doc->junk : false;
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
// The raw container titles clustered under a container doc, or an empty array
// if $cid is not a container doc.
function get_container_variants($cid)
{
	global $config;
	global $couch;

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . urlencode($cid));

	$container = json_decode($resp);

	if (!$container || !isset($container->variants) || !is_array($container->variants))
	{
		return array();
	}

	return $container->variants;
}

//--------------------------------------------------------------------------------------------------
// Pull works across every variant spelling of a single canonical container.
// Returns the same {year → {cluster_id → {csl, cluster_size}}} envelope as
// get_works_by_container; cluster_size is the true count across all variants.
// $year restricts the result to a single publication year; see get_works_by_container.
function get_works_by_container_id($cid, $year = null)
{
	global $config;
	global $couch;

	$variants = get_container_variants($cid);

	if (count($variants) == 0)
	{
		return array();
	}

	$result      = array();
	$counts      = array();
	$kept_is_rep = array();

	foreach ($variants as $variant)
	{
		if ($year === null)
		{
			$startkey = array($variant, 0);
			$endkey   = array($variant, 2030, new stdclass);
		}
		else
		{
			$startkey = array($variant, (Integer)$year);
			$endkey   = array($variant, (Integer)$year, new stdclass);
		}

		$parameters = array(
			'startkey'     => json_encode($startkey, JSON_UNESCAPED_UNICODE),
			'endkey'       => json_encode($endkey, JSON_UNESCAPED_UNICODE),
			'reduce'       => 'false',
			'include_docs' => 'true',
		);

		$url = '_design/interface/_view/container-year-page?' . http_build_query($parameters);
		$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
		$response_obj = json_decode($resp);

		if (!$response_obj || !isset($response_obj->rows))
		{
			continue;
		}

		foreach ($response_obj->rows as $row)
		{
			$year    = $row->key[1];
			$cluster = $row->doc->citebank->cluster;
			$is_rep  = ($row->doc->_id === $cluster);

			if (!isset($result[$year]))
			{
				$result[$year]      = array();
				$counts[$year]      = array();
				$kept_is_rep[$year] = array();
			}

			$counts[$year][$cluster] = ($counts[$year][$cluster] ?? 0) + 1;

			if (!isset($result[$year][$cluster]) || ($is_rep && !$kept_is_rep[$year][$cluster]))
			{
				$result[$year][$cluster]      = simplify_csl($row->doc);
				$kept_is_rep[$year][$cluster] = $is_rep;
			}
		}
	}

	foreach ($result as $year => $clusters)
	{
		foreach ($clusters as $cluster => $csl)
		{
			$envelope = new stdclass;
			$envelope->csl          = $csl;
			$envelope->cluster_size = $counts[$year][$cluster];
			$result[$year][$cluster] = $envelope;
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
function get_works_by_family($family)
{
	global $config;
	global $couch;

	$result = array();
	$counts = array();
	$kept_is_rep = array();

	$startkey = array($family, 0);
	$endkey = array($family, 2030, new stdclass);

	$parameters = array(
		'startkey' 		=> json_encode($startkey, JSON_UNESCAPED_UNICODE),
		'endkey'		=> json_encode($endkey, JSON_UNESCAPED_UNICODE),
		'reduce' 		=> 'false',
		//'group_level' 	=> 2,
		'include_docs'	=> 'true'
	);

	$url = '_design/interface/_view/family-year?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$year    = $row->key[1];
			$cluster = $row->doc->citebank->cluster;
			$is_rep  = ($row->doc->_id === $cluster);

			if (!isset($result[$year]))
			{
				$result[$year]      = array();
				$counts[$year]      = array();
				$kept_is_rep[$year] = array();
			}

			$counts[$year][$cluster] = ($counts[$year][$cluster] ?? 0) + 1;

			if (!isset($result[$year][$cluster]) || ($is_rep && !$kept_is_rep[$year][$cluster]))
			{
				$result[$year][$cluster]      = simplify_csl($row->doc);
				$kept_is_rep[$year][$cluster] = $is_rep;
			}
		}

		foreach ($result as $year => $clusters)
		{
			foreach ($clusters as $cluster => $csl)
			{
				$envelope = new stdclass;
				$envelope->csl          = $csl;
				$envelope->cluster_size = $counts[$year][$cluster];
				$result[$year][$cluster] = $envelope;
			}
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
// Get unstructured references for a given $id (typically a DOI)
// Intention is that these be parsed and converted into CSL
function get_unstructured_references($id)
{
	global $config;
	global $couch;
	
	$result = array();
	
	$parameters = array(
		'key' 			=> '"' . $id . '"'
	);

	$url = '_design/interface/_view/unstructured-simple?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[$row->value->key] = $row->value->unstructured;
		}
	}
	
	return $result;
}


//--------------------------------------------------------------------------------------------------
// get members of cluster
function get_cluster_members($id)
{
	global $config;
	global $couch;
	
	$result = array();
	
	$parameters = array(
		'key' 			=> '"' . $id . '"'
	);

	$url = '_design/interface/_view/cluster?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[] = $row->value;
		}
	}
	
	return $result;
}



//--------------------------------------------------------------------------------------------------
function display_containers_first_letters($callback = '')
{
	$status = 404;
	
	$result = get_containers_first_letters();
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
function display_containers_by_letter($letter, $callback = '')
{
	$status = 404;
	
	$result = get_containers_by_letter($letter);
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
function display_works_by_container_id($cid, $callback = '', $year = null)
{
	$status = 404;

	$result = get_works_by_container_id($cid, $year);

	if (count($result) > 0)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// Works per year for a container, plus whether the whole container is small
// enough to be fetched in one request. The UI asks for this first so it knows
// whether to load everything or offer a year at a time.
function display_container_year_counts($variants, $callback = '')
{
	$counts = get_container_year_counts($variants);

	$obj = new stdclass;
	$obj->years = new stdclass;

	$total = 0;

	foreach ($counts as $year => $count)
	{
		if (!is_plausible_year($year))
		{
			continue;
		}

		$obj->years->{$year} = $count;
		$total += $count;
	}

	$obj->total = $total;
	$obj->max   = CONTAINER_WORKS_MAX;

	// When false the caller must supply &year=<y> rather than asking for
	// every work at once.
	$obj->complete_fetch_allowed = ($total <= CONTAINER_WORKS_MAX);

	api_output($obj, $callback, count($counts) > 0 ? 200 : 404);
}

//--------------------------------------------------------------------------------------------------
function display_container_for_variant($variant, $callback = '')
{
	$status = 404;

	$result = get_container_for_variant($variant);

	if ($result)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
function display_works_by_container($container, $callback = '', $year = null)
{
	$status = 404;

	$result = get_works_by_container($container, $year);

	if (count($result) > 0)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// Can every work in these container titles be returned in a single response?
// $counts is filled in with the per-year counts so the caller can reuse them
// without repeating the query.
function container_is_fetchable($variants, &$counts = null)
{
	$counts = get_container_year_counts($variants);

	return (array_sum($counts) <= CONTAINER_WORKS_MAX);
}

//--------------------------------------------------------------------------------------------------
// Refuse an unbounded fetch that we know would exhaust memory, and tell the
// caller how to ask for the same data a year at a time. $counts comes from
// container_is_fetchable.
function refuse_oversized_container($counts, $callback = '')
{
	$obj = new stdclass;
	$obj->status = 413;
	$obj->error  = 'This container holds ' . array_sum($counts) . ' works, more than the '
		. CONTAINER_WORKS_MAX . ' that can be returned in one request. Add &year=<year>, '
		. 'or use &years to list the available years.';
	$obj->total  = array_sum($counts);
	$obj->max    = CONTAINER_WORKS_MAX;

	api_output($obj, $callback, 413);
}

//--------------------------------------------------------------------------------------------------
function display_family_first_letters($callback = '')
{
	$status = 404;
	
	$result = get_family_first_letters();
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}


//--------------------------------------------------------------------------------------------------
function display_authors_by_letter($letter, $callback = '')
{
	$status = 404;
	
	$result = get_authors_by_letter($letter);
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
function display_works_by_family($family, $callback = '')
{
	$status = 404;
	
	$result = get_works_by_family($family);
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
function get_volumes_by_year($year)
{
	global $config;
	global $couch;
	
	$result = array();
	
	$startkey = array((Integer)$year);
	$endkey = array((Integer)$year, new stdclass);
	
	$parameters = array(
		'startkey' 		=> json_encode($startkey, JSON_UNESCAPED_UNICODE),
		'endkey'		=> json_encode($endkey, JSON_UNESCAPED_UNICODE),
		'reduce' 		=> 'true',
		'group_level' 	=> 2,
	);

	$url = '_design/matching/_view/hash?' . http_build_query($parameters);
	
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	
	$response_obj = json_decode($resp);
	
	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$result[] = $row->key[1];
		}
	}
	
	return $result;
}

//--------------------------------------------------------------------------------------------------
// Is this a year we are prepared to show in the year browser?
//
// The _design/interface/years view does parseInt(...) on whatever is in
// doc.issued, so malformed dates arrive here as keys such as "" (NaN), 0, 13,
// 2102 and 18311829 (two years run together). Around 2,500 of ~954,000 records
// are affected, but because those keys sort first they dominate the top of the
// year list. Filtering here rather than in the view avoids reindexing the whole
// database; the underlying records are untouched and still reachable by other
// routes.
function is_plausible_year($year)
{
	if (!preg_match('/^\d{4}$/', (string)$year))
	{
		return false;
	}

	// 1500 is comfortably below the earliest genuine imprint we hold (1525);
	// everything under it is one-off parsing noise.
	return ((Integer)$year >= 1500) && ((Integer)$year <= ((Integer)date('Y') + 1));
}

//--------------------------------------------------------------------------------------------------
function get_year_list()
{
	global $config;
	global $couch;

	$result = array();

	$parameters = array(
		'reduce' 		=> 'true',
		'group_level'	=> 2
	);

	$url = '_design/interface/_view/years?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			if (!is_plausible_year($row->key))
			{
				continue;
			}

			$result[$row->key] = $row->value;
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
function display_year_list($callback = '')
{
	$status = 404;
	
	$result = get_year_list();
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}


//--------------------------------------------------------------------------------------------------
function get_works_by_volume_by_year($year, $volume)
{
	global $config;
	global $couch;

	$result = array();
	$counts = array();
	$kept_is_rep = array();

	$startkey = array((Integer)$year, (Integer)$volume);
	$endkey = array((Integer)$year, (Integer)$volume, new stdclass);

	$parameters = array(
		'startkey' 		=> json_encode($startkey, JSON_UNESCAPED_UNICODE),
		'endkey'		=> json_encode($endkey, JSON_UNESCAPED_UNICODE),
		'reduce' 		=> 'false',
		'include_docs' 	=> 'true',
	);

	$url = '_design/matching/_view/hash?' . http_build_query($parameters);

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);

	$response_obj = json_decode($resp);

	if ($response_obj)
	{
		foreach ($response_obj->rows as $row)
		{
			$page    = $row->key[2];
			$cluster = $row->doc->citebank->cluster;
			$is_rep  = ($row->doc->_id === $cluster);

			if (!isset($result[$page]))
			{
				$result[$page]      = array();
				$counts[$page]      = array();
				$kept_is_rep[$page] = array();
			}

			$counts[$page][$cluster] = ($counts[$page][$cluster] ?? 0) + 1;

			if (!isset($result[$page][$cluster]) || ($is_rep && !$kept_is_rep[$page][$cluster]))
			{
				$result[$page][$cluster]      = simplify_csl($row->doc);
				$kept_is_rep[$page][$cluster] = $is_rep;
			}
		}

		foreach ($result as $page => $clusters)
		{
			foreach ($clusters as $cluster => $csl)
			{
				$envelope = new stdclass;
				$envelope->csl          = $csl;
				$envelope->cluster_size = $counts[$page][$cluster];
				$result[$page][$cluster] = $envelope;
			}
		}
	}

	return $result;
}

//--------------------------------------------------------------------------------------------------
// part of [year, volume, spage] hash
function display_volumes_by_year($year, $callback = '')
{
	$status = 404;
	
	$result = get_volumes_by_year($year);
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// part of [year, volume, spage] hash
function display_works_by_volume_by_year($year, $volume, $callback = '')
{
	$status = 404;
	
	$result = get_works_by_volume_by_year($year, $volume);
	
	if (count($result) > 0)
	{
		$status = 200;
	}
	
	api_output($result, $callback, $status);
}


//--------------------------------------------------------------------------------------------------
// Shown when the API is called with no parameters (status 200, a self-describing
// index) or with parameters we don't recognise (status 400). Both cases used to
// emit a bare "hi", which told a caller nothing about what the API accepts.
function default_display($callback = '', $status = 200)
{
	$obj = new stdclass;

	$obj->name = 'CiteBank API';
	$obj->description = 'Bibliographic records for the taxonomic literature, stored as CSL-JSON.';

	$obj->endpoints = array(
		'?id=<id>'					=> 'One record by id. Add &format=ris|bibtex|csl to export.',
		'?doi=<doi>'				=> 'Records matching a DOI.',
		'?ids=<id,id,...>'			=> 'Several records. Add &consensus=1 for a merged record.',
		'?clusterid=<id>'			=> 'Consensus record for a cluster.',
		'?q=<text>'					=> 'Full-text title search. Add &limit=<n>.',
		'?container&first'			=> 'First letters of container (journal) titles.',
		'?container&letter=<x>'		=> 'Containers starting with one letter.',
		'?container&cid=<id>'		=> 'Works in a container cluster. Exportable.',
		'?container&title=<title>'	=> 'Works in an exact container title. Exportable.',
		'?container&variant=<title>'=> 'Which container cluster holds this exact spelling.',
		'?author&first'				=> 'First letters of author family names.',
		'?author&letter=<x>'		=> 'Author family names starting with one letter.',
		'?author&family=<name>'		=> 'Works by an author family name. Exportable.',
		'?dates'					=> 'Publication years and the number of works in each.',
		'?hash&year=<y>'			=> 'Volumes for a year; add &volume=<v> for works.',
	);

	$obj->parameters = array(
		'callback'	=> 'Wrap the response as JSONP.',
		'format'	=> 'ris, bibtex or csl on record endpoints; triggers a file download.',
		'limit'		=> 'Maximum number of results (search).',
	);

	if ($status != 200)
	{
		$obj->error = 'Unrecognised request. See "endpoints" for what this API accepts.';
	}

	api_output($obj, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// URL (e.g., PDF) exists
function display_head ($url, $callback)
{
	$obj = new stdclass;
	$obj->url = $url;
	$obj->found = false;

	$status = 404;
	
	if (api_head($url))
	{
		$status = 200;
		$obj->found = true;
	}
		
	api_output($obj, $callback, $status);
}	
	
//--------------------------------------------------------------------------------------------------
// One record (as array)
function display_one_record ($id, $format= '', $callback = '')
{
	$status = 404;
	
	$obj = array();
	
	$record = get_one_record($id);
	
	if ($record)
	{
		$status = 200;
		
		switch ($format)
		{
			default:
				$obj[] = $record;
				break;
		}

	}
	else
	{
		$obj = new stdclass;
		$obj->error = "Record $id not found";
	}
		
	api_output($obj, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// Record(s) with DOI
function display_records_with_doi ($doi, $format= '', $callback = '')
{
	$status = 404;
	
	$obj = array();
	
	$records = get_from_doi($doi);
	
	$result = array();
	
	foreach ($records as $obj)
	{
		$result[] = $obj;
	}
	
	if (count($records) > 0)
	{
		$status = 200;
	}
		
	api_output($result, $callback, $status);
}

//--------------------------------------------------------------------------------------------------
// Multiple records
function display_multiple_records ($ids, $format= '', $callback = '')
{
	$status = 404;
	$result = array();
	
	$records = get_multiple_records($ids);
	
	$result = array();
	
	foreach ($records as $obj)
	{
		$result[] = $obj;
	}
	
	if (count($records) > 0)
	{
		$status = 200;
	}	
		
	api_output($result, $callback, $status);
}	

//--------------------------------------------------------------------------------------------------
// Get consensus of multiple records that are listed by id
function display_consensus_for_records ($ids, $callback = '')
{	
	$status = 404;
	
	$obj = null;
	
	$records = get_multiple_records($ids);
	
	//print_r($records);
	
	if (count($records) > 0)
	{
		$obj = merge($records, []);
	}
		
	api_output($obj, $callback, 200);
}

//--------------------------------------------------------------------------------------------------
// Get consensus of multiple records provided as array of CSL-JSON
function display_consensus_for_csl ($records, $callback = '', $debug = false)
{	
	$status = 404;
	
	$obj = null;
	
	if (count($records) > 0)
	{
		$obj = merge($records, []);
		
		if (!$debug)
		{
			$obj = $obj->consensus;
		}
	}
		
	api_output($obj, $callback, 200);
}

//--------------------------------------------------------------------------------------------------
// Compare two records by computing feature vector
function display_features_for_records ($ids, $callback = '', $debug = false)
{	
	$status = 404;
	
	$obj = array();
	
	// records for ids
	$records = get_multiple_records($ids);
	
	if (count($records) == 2)
	{	
		$obj = citation_pair_to_feature_vector($records, $debug);
	}

	api_output($obj, $callback, 200);	
}


//--------------------------------------------------------------------------------------------------
// Compare two records by computing feature vector
function display_features_for_csl ($records, $callback = '', $debug = false)
{	
	$status = 404;
	
	$obj = array();
		
	if (count($records) == 2)
	{	
		$obj = citation_pair_to_feature_vector($records, $debug);
	}

	api_output($obj, $callback, 200);	
}

/*
//--------------------------------------------------------------------------------------------------
// Cluster a set of ids
function display_cluster ($ids, $callback = '')
{	
	$status = 404;
	
	$obj = array();
	
	// records for ids
	$records = get_records_for_ids($ids);
		
	// simplest approach is simply to cluster the supplied ids (but testing as we do it)
	$obj = update_clusters_for_records($records);

	api_output($obj, $callback, 200);	
}


*/

//--------------------------------------------------------------------------------------------------
// Unstructured citations as array of strings (that we can process)
function display_unstructured ($id, $callback = '')
{
	$status = 404;
	$result = array();
	
	$result = get_unstructured_references($id);
	
	if (count($result) > 0)
	{
		$status = 200;
	}	
		
	api_output($result, $callback, $status);
}	


//--------------------------------------------------------------------------------------------------
// Both branches terminate via api_output (which exits), so there is no
// fall-through path. If you add another branch, it must also call api_output.
function display_cluster ($id, $callback = '')
{
	$members = get_cluster_members($id);

	if (count($members) === 0)
	{
		$err = new stdclass;
		$err->error = "No cluster found for $id";
		api_output($err, $callback, 404);
	}

	display_consensus_for_records($members, $callback);
}

//--------------------------------------------------------------------------------------------------
function display_search($query, $limit = 20, $callback)
{
	global $config;
	global $couch;

	$status = 404;
	$result = array();

	$query = trim($query);
	$query = preg_replace('/\s\s+/', ' ', $query);

	if ($query === '')
	{
		api_output($result, $callback, $status);
		return;
	}

	$query_parts = explode(' ', $query);

	foreach ($query_parts as &$part)
	{
		$part = 'title:' . $part; // consider adding "~" suffix for fuzzy matching
	}
	unset($part);

	$q = join(' AND ', $query_parts);

	$url  = '_design/search/_nouveau/full-text?q=' . rawurlencode($q);
	$url .= '&limit=' . $limit;
	$url .= '&include_docs=true';

	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . $url);
	$resp_obj = json_decode($resp);

	// Full-text search is served by Nouveau, which is a separate Java process
	// alongside CouchDB. If it is not running, CouchDB answers
	// {"error":"service unavailable"} and there is no hits array. Say so plainly
	// rather than emitting PHP notices and an empty result, which looks like
	// "no matches" and hides the real problem. See README for starting Nouveau.
	if (!$resp_obj || !isset($resp_obj->hits) || !is_array($resp_obj->hits))
	{
		$err = new stdclass;
		$err->error = 'Search is unavailable.';

		if ($resp_obj && isset($resp_obj->reason))
		{
			$err->reason = $resp_obj->reason;
		}

		api_output($err, $callback, 503);
	}

	$clusters  = array();
	$reps_seen = array();

	foreach ($resp_obj->hits as $hit)
	{
		$cluster_id = $hit->doc->citebank->cluster;

		// Nouveau wraps each order element as { "@type": "...", "value": N };
		// older builds returned a bare number — handle both.
		$score = 0.0;
		if (isset($hit->order[0]))
		{
			$first = $hit->order[0];
			$score = is_object($first) ? (float)$first->value : (float)$first;
		}

		$is_rep = ($hit->doc->_id === $cluster_id);

		if (!isset($clusters[$cluster_id]))
		{
			$item = new stdclass;
			$item->cluster_id   = $cluster_id;
			$item->csl          = simplify_csl($hit->doc);
			$item->score        = $score;
			$item->cluster_size = 1;

			$clusters[$cluster_id]  = $item;
			$reps_seen[$cluster_id] = $is_rep;
		}
		else
		{
			$item = $clusters[$cluster_id];
			$item->cluster_size++;
			if ($score > $item->score)
			{
				$item->score = $score;
			}
			// prefer the cluster representative if we encounter it
			if ($is_rep && !$reps_seen[$cluster_id])
			{
				$item->csl              = simplify_csl($hit->doc);
				$reps_seen[$cluster_id] = true;
			}
		}
	}

	$result = array_values($clusters);
	usort($result, function($a, $b) {
		return $b->score <=> $a->score;
	});

	if (count($result) > 0)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
}


//--------------------------------------------------------------------------------------------------
// Export helpers: flatten a {year => {cluster => {csl,...}}} works envelope and
// convert the CSL-JSON records to RIS / BibTeX / CSL-JSON for download.

function flatten_works($envelope)
{
	$list = array();
	foreach ($envelope as $year => $clusters)
	{
		foreach ($clusters as $cid => $entry)
		{
			if (isset($entry->csl))
			{
				$list[] = $entry->csl;
			}
		}
	}
	return $list;
}

function csl_year($csl)
{
	if (isset($csl->issued->{'date-parts'}[0][0]))
	{
		return (int)$csl->issued->{'date-parts'}[0][0];
	}
	if (isset($csl->issued) && is_string($csl->issued) && preg_match('/\d{4}/', $csl->issued, $m))
	{
		return (int)$m[0];
	}
	return null;
}

// [start, end] page strings ('' when absent).
function csl_pages($csl)
{
	if (!isset($csl->page))
	{
		return array('', '');
	}
	$p = (string)$csl->page;
	if (preg_match('/(\w+)\s*[-\x{2010}-\x{2015}]\s*(\w+)/u', $p, $m))
	{
		return array($m[1], $m[2]);
	}
	return array(trim($p), '');
}

function csl_authors($csl)
{
	$out = array();
	if (isset($csl->author) && is_array($csl->author))
	{
		foreach ($csl->author as $a)
		{
			if (isset($a->family))
			{
				$out[] = isset($a->given) ? ($a->family . ', ' . $a->given) : $a->family;
			}
			else if (isset($a->literal))
			{
				$out[] = $a->literal;
			}
		}
	}
	return $out;
}

function csl_container($csl)
{
	if (!isset($csl->{'container-title'}))
	{
		return '';
	}
	$ct = $csl->{'container-title'};
	return is_array($ct) ? (isset($ct[0]) ? $ct[0] : '') : (string)$ct;
}

function csl_issns($csl)
{
	if (!isset($csl->ISSN))
	{
		return array();
	}
	return is_array($csl->ISSN) ? $csl->ISSN : array($csl->ISSN);
}

function one_line($s)
{
	return trim(preg_replace('/\s+/u', ' ', (string)$s));
}

function compare_csl_for_export($a, $b)
{
	$ya = csl_year($a); $yb = csl_year($b);
	$ya = ($ya === null) ? 999999 : $ya;
	$yb = ($yb === null) ? 999999 : $yb;
	if ($ya !== $yb) return $ya - $yb;

	$va = (int)preg_replace('/\D/', '', isset($a->volume) ? (string)$a->volume : '');
	$vb = (int)preg_replace('/\D/', '', isset($b->volume) ? (string)$b->volume : '');
	if ($va !== $vb) return $va - $vb;

	list($pa) = csl_pages($a);
	list($pb) = csl_pages($b);
	return ((int)$pa) - ((int)$pb);
}

function csl_to_ris($csl)
{
	$map = array('article-journal' => 'JOUR', 'article' => 'JOUR', 'book' => 'BOOK',
		'chapter' => 'CHAP', 'paper-conference' => 'CPAPER', 'thesis' => 'THES', 'report' => 'RPRT');
	$container = csl_container($csl);
	$type = isset($csl->type) && isset($map[$csl->type]) ? $map[$csl->type] : ($container !== '' ? 'JOUR' : 'GEN');

	$L = array();
	$L[] = "TY  - $type";
	foreach (csl_authors($csl) as $au) { $L[] = "AU  - $au"; }
	$y = csl_year($csl); if ($y) { $L[] = "PY  - $y"; }
	if (isset($csl->title)) { $L[] = "TI  - " . one_line($csl->title); }
	if ($container !== '') { $L[] = "JO  - " . one_line($container); }
	if (isset($csl->volume)) { $L[] = "VL  - " . one_line($csl->volume); }
	if (isset($csl->issue)) { $L[] = "IS  - " . one_line($csl->issue); }
	list($sp, $ep) = csl_pages($csl);
	if ($sp !== '') { $L[] = "SP  - $sp"; }
	if ($ep !== '') { $L[] = "EP  - $ep"; }
	if (isset($csl->DOI)) { $L[] = "DO  - " . $csl->DOI; }
	foreach (csl_issns($csl) as $sn) { $L[] = "SN  - $sn"; }
	$L[] = "ER  - ";
	return implode("\r\n", $L) . "\r\n\r\n";
}

function csl_to_bibtex($csl)
{
	$map = array('article-journal' => 'article', 'article' => 'article', 'book' => 'book',
		'chapter' => 'incollection', 'paper-conference' => 'inproceedings', 'thesis' => 'phdthesis', 'report' => 'techreport');
	$container = csl_container($csl);
	$type = isset($csl->type) && isset($map[$csl->type]) ? $map[$csl->type] : ($container !== '' ? 'article' : 'misc');

	$key = isset($csl->_id) ? preg_replace('/[^A-Za-z0-9]/', '', $csl->_id) : '';
	if ($key === '') { $key = 'ref' . substr(md5(json_encode($csl)), 0, 8); }

	$esc = function ($s) { return str_replace(array('{', '}'), array('\{', '\}'), one_line($s)); };

	$fields = array();
	$au = csl_authors($csl);
	if ($au) { $fields['author'] = implode(' and ', $au); }
	if (isset($csl->title)) { $fields['title'] = $csl->title; }
	if ($container !== '') { $fields['journal'] = $container; }
	if (isset($csl->volume)) { $fields['volume'] = $csl->volume; }
	if (isset($csl->issue)) { $fields['number'] = $csl->issue; }
	list($sp, $ep) = csl_pages($csl);
	if ($sp !== '') { $fields['pages'] = ($ep !== '') ? "$sp--$ep" : $sp; }
	$y = csl_year($csl); if ($y) { $fields['year'] = $y; }
	if (isset($csl->DOI)) { $fields['doi'] = $csl->DOI; }
	$iss = csl_issns($csl); if ($iss) { $fields['issn'] = implode(', ', $iss); }

	$parts = array();
	foreach ($fields as $k => $v) { $parts[] = "  $k = {" . $esc($v) . "}"; }
	return "@$type{" . $key . ",\n" . implode(",\n", $parts) . "\n}\n";
}

function is_export_format($format)
{
	return in_array(strtolower($format), array('ris', 'bibtex', 'bib', 'csl'));
}

function safe_filename($s)
{
	$s = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)$s), '-');
	return $s === '' ? 'export' : $s;
}

function export_works($envelope, $format, $basename)
{
	$list = flatten_works($envelope);
	usort($list, 'compare_csl_for_export');
	$base = safe_filename($basename);

	switch (strtolower($format))
	{
		case 'ris':
			$out = '';
			foreach ($list as $csl) { $out .= csl_to_ris($csl); }
			api_output_download($out, 'application/x-research-info-systems', "$base.ris");
			break;

		case 'bib':
		case 'bibtex':
			$out = '';
			foreach ($list as $csl) { $out .= csl_to_bibtex($csl) . "\n"; }
			api_output_download($out, 'application/x-bibtex', "$base.bib");
			break;

		case 'csl':
		default:
			api_output_download(
				json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
				'application/json', "$base.json");
			break;
	}
}

//--------------------------------------------------------------------------------------------------
function main()
{
	global $config;

	$callback = '';
	$handled = false;
	
	$post_content = file_get_contents('php://input');
	
	// If no query parameters 
	if (count($_GET) == 0 && $post_content == '')
	{
		default_display('', 200);
		exit(0);
	}
	
	if (isset($_GET['callback']))
	{	
		$callback = $_GET['callback'];
	}
	
	$debug = false;			
	if (isset($_GET['debug']))
	{
		$debug = true;
	}	
	
	$limit = 20;
	if (isset($_GET['limit']))
	{
		$limit = $_GET['limit'];
	}

	// Optional output format. For the work-list selectors (cid/title/family) the
	// export formats (ris/bibtex/csl) trigger a file download instead of JSON.
	$format = isset($_GET['format']) ? $_GET['format'] : '';

	// Environment probe: lets the (static) UI gate dev-only features.
	if (!$handled && isset($_GET['env']))
	{
		$env = new stdclass;
		$env->dev = !empty($config['dev']);
		api_output($env, $callback, 200);
		$handled = true;
	}

	// Submit job

	// get one record from doc id
	if (!$handled)
	{
		if (isset($_GET['id']))
		{	
			$id = $_GET['id'];
			
			$format = '';
			
			if (isset($_GET['format']))
			{
				$format = $_GET['format'];
			}			
			
			if (isset($_GET['cites']))
			{		
				display_unstructured($id, $callback);	
				$handled = true;			
			}
						
			if (!$handled)
			{
				display_one_record($id, $format, $callback);
				$handled = true;
			}			
		}
	}

	// get information on a cluster
	if (!$handled)
	{
		if (isset($_GET['clusterid']))
		{	
			$clusterid = $_GET['clusterid'];
						
			if (!$handled)
			{		
				display_cluster($clusterid, $callback);	
				$handled = true;			
			}
		}
	}
	
	// get record(s) by external identifier
	if (!$handled)
	{
		if (isset($_GET['doi']))
		{	
			$doi = $_GET['doi'];
			
			$format = '';
			
			if (isset($_GET['format']))
			{
				$format = $_GET['format'];
			}			
			
			if (!$handled)
			{
				display_records_with_doi($doi, $format, $callback);
				$handled = true;
			}
			
		}
	}
	
	
	// multiple records by delimited list of doc ids
	if (!$handled)
	{
		if (isset($_GET['ids']))
		{		
			$delimited_ids = $_GET['ids'];
			
			$ids = preg_split('/[\||,]\s*/', $delimited_ids);
			
			if (isset($_GET['consensus']))
			{			
				display_consensus_for_records($ids, $callback);
				$handled = true;
			}	
			
			if (isset($_GET['features']))
			{			
				display_features_for_records($ids, $callback, $debug);
				$handled = true;
			}	
						
			/*
			if (!$handled)
			{
				display_versions($cluster_id, $callback);
				$handled = true;
			}
			*/
			
			// return a list of records
			if (!$handled)
			{
				$format = '';			
				if (isset($_GET['format']))
				{
					$format = $_GET['format'];
				}			

				display_multiple_records($ids, $format, $callback);
				$handled = true;
			}
		}
	}	
	
	// handle set of documents
	if (!$handled)
	{
		if ($post_content != '')
		{
		
			$error = parse_json($post_content);
			
			if ($error->code == 0)
			{
				// OK
				$docs = json_decode($post_content);
				
				if (is_array($docs))
				{
					if (!$handled)
					{				
						if (isset($_GET['consensus']))
						{
							display_consensus_for_csl ($docs, $callback, $debug);
							$handled = true;
						}
					}	

					if (!$handled)
					{				
						if (isset($_GET['features']))
						{
							display_features_for_csl ($docs, $callback, $debug);
							$handled = true;
						}
					}	
					
				}
				else
				{
					$obj = new stdclass;
					$obj->status = 400;
					$obj->msg = "Expecting array of CSL-JSON documents";
					api_output($error, $callback);	
				}			
			}
			else
			{
				// Bad JSON
				$error->status = 400;
				api_output($error, $callback);					
			}
		}
	}
	
	// containers
	if (!$handled)
	{
		if (isset($_GET['container']))
		{		
			if (isset($_GET['first']))
			{
				display_containers_first_letters($callback);
				$handled = true;
			}			

			if (isset($_GET['letter']))
			{
				$letter = $_GET['letter'];
				display_containers_by_letter($letter, $callback);
				
				$handled = true;
			}	
			
			// Optional single publication year, used to keep large containers
			// within a fetchable size.
			$container_year = isset($_GET['year']) ? $_GET['year'] : null;

			if (isset($_GET['title']))
			{
				$title = $_GET['title'];

				if (isset($_GET['years']))
				{
					display_container_year_counts(array($title), $callback);
				}
				elseif ($container_year === null && !container_is_fetchable(array($title), $counts))
				{
					refuse_oversized_container($counts, $callback);
				}
				elseif (is_export_format($format))
				{
					export_works(get_works_by_container($title, $container_year), $format, $title);
				}
				else
				{
					display_works_by_container($title, $callback, $container_year);
				}
				$handled = true;
			}

			if (isset($_GET['cid']))
			{
				$cid = $_GET['cid'];

				if (isset($_GET['years']))
				{
					display_container_year_counts(get_container_variants($cid), $callback);
				}
				elseif ($container_year === null && !container_is_fetchable(get_container_variants($cid), $counts))
				{
					refuse_oversized_container($counts, $callback);
				}
				elseif (is_export_format($format))
				{
					export_works(get_works_by_container_id($cid, $container_year), $format, preg_replace('/^container:/', '', $cid));
				}
				else
				{
					display_works_by_container_id($cid, $callback, $container_year);
				}
				$handled = true;
			}

			if (isset($_GET['variant']))
			{
				$variant = $_GET['variant'];
				display_container_for_variant($variant, $callback);
				$handled = true;
			}

		}
	}

	// authors
	if (!$handled)
	{
		if (isset($_GET['author']))
		{		
			if (isset($_GET['first']))
			{
				display_family_first_letters($callback);
				$handled = true;
			}			
		
			if (isset($_GET['letter']))
			{
				$letter = $_GET['letter'];
				display_authors_by_letter($letter, $callback);
				
				$handled = true;
			}	
			
			if (isset($_GET['family']))
			{
				$family = $_GET['family'];
				if (is_export_format($format))
				{
					export_works(get_works_by_family($family), $format, $family);
				}
				else
				{
					display_works_by_family($family, $callback);
				}
				$handled = true;
			}
			
		}
	}	
	
	// year, volume, page hash
	if (!$handled)
	{
		if (isset($_GET['hash']))
		{		
			if (isset($_GET['year']))
			{
				$year = $_GET['year'];
								
				if (isset($_GET['volume']))
				{
					$volume = $_GET['volume'];
					display_works_by_volume_by_year($year, $volume, $callback);				
					$handled = true;
				}
				
				if (!$handled)
				{
					display_volumes_by_year($year, $callback);
					$handled = true;
				}
			}			
			
		}
	}
	
	// range of publication dates	
	if (!$handled)
	{
		if (isset($_GET['dates']))
		{		
			display_year_list($callback);
			$handled = true;
		}							
	}
		
		
	// simple search	
	if (!$handled)
	{
		if (isset($_GET['q']))
		{		
			$query = $_GET['q'];
			display_search($query, $limit, $callback);
			$handled = true;
		}							
	}
	
	if (!$handled)
	{
		default_display($callback, 400);
	}

}


main();

?>
