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
function get_works_by_container($container)
{
	global $config;
	global $couch;

	$result = array();
	$counts = array();
	$kept_is_rep = array();

	$startkey = array($container, 0);
	$endkey = array($container, 2030, new stdclass);

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
// Pull works across every variant spelling of a single canonical container.
// Returns the same {year → {cluster_id → {csl, cluster_size}}} envelope as
// get_works_by_container; cluster_size is the true count across all variants.
function get_works_by_container_id($cid)
{
	global $config;
	global $couch;

	// Fetch the container doc to read its variants list.
	$resp = $couch->send("GET", "/" . $config['couchdb_options']['database'] . "/" . urlencode($cid));
	$container = json_decode($resp);
	if (!$container || !isset($container->variants) || !is_array($container->variants))
	{
		return array();
	}

	$result      = array();
	$counts      = array();
	$kept_is_rep = array();

	foreach ($container->variants as $variant)
	{
		$startkey = array($variant, 0);
		$endkey   = array($variant, 2030, new stdclass);

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
function display_works_by_container_id($cid, $callback = '')
{
	$status = 404;

	$result = get_works_by_container_id($cid);

	if (count($result) > 0)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
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
function display_works_by_container($container, $callback = '')
{
	$status = 404;

	$result = get_works_by_container($container);

	if (count($result) > 0)
	{
		$status = 200;
	}

	api_output($result, $callback, $status);
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
function default_display()
{
	echo "hi";
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
function main()
{
	global $config;

	$callback = '';
	$handled = false;
	
	$post_content = file_get_contents('php://input');
	
	// If no query parameters 
	if (count($_GET) == 0 && $post_content == '')
	{
		default_display();
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
			
			if (isset($_GET['title']))
			{
				$title = $_GET['title'];
				display_works_by_container($title, $callback);
				$handled = true;
			}

			if (isset($_GET['cid']))
			{
				$cid = $_GET['cid'];
				display_works_by_container_id($cid, $callback);
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
				display_works_by_family($family, $callback);				
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
		default_display();
	}

}


main();

?>
