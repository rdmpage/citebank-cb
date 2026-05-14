<?php

// Upload a CSL-JSON document to CouchDB

require_once (dirname(__FILE__) . '/utilities.php');
require_once (dirname(__FILE__) . '/couchsimple.php');

//----------------------------------------------------------------------------------------
// Upload a CSL-JSON document to CouchDB
function upload($doc, $source='unknown', $force = false)
{
	global $config;
	global $couch;
	
	$go = true;

	// Check whether this record already exists (i.e., have we done this object already?)
	$exists = $couch->exists($doc->_id);

	if ($exists)
	{
		$go = false;

		if ($force)
		{
			$couch->add_update_or_delete_document(null, $doc->_id, 'delete');
			$go = true;		
		}
	}

	if ($go)
	{
		$doc->citebank = new stdclass;
		$doc->citebank->type = 'work';
		$doc->citebank->format = 'application/vnd.citationstyles.csl+json';
		
		$doc->citebank->source = $source;
		
		$doc->citebank->created = date("c", time());
		$doc->citebank->modified = $doc->citebank->created;
		$doc->citebank->fetched  = $doc->citebank->created;
		$doc->citebank->cluster = $doc->_id;
			
		$resp = $couch->send("PUT", "/" . $config['couchdb_options']['database'] . "/" . urlencode($doc->_id), json_encode($doc));
		var_dump($resp);							
	}	

}

?>
