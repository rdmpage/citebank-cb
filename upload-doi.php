<?php

require_once (dirname(__FILE__) . '/upload.php');

//----------------------------------------------------------------------------------------
function doi_to_identifier($doi)
{
	$doi = strtolower($doi);
	$identifier = 'https://doi.org/' . $doi;
	return $identifier;
}

//----------------------------------------------------------------------------------------
// Content negotiation
function get_work($doi)
{
	$doc = null;
	
	$url = 'https://doi.org/' . $doi;
	
	$json = get($url, 'application/vnd.citationstyles.csl+json');
	
	if ($json != '')
	{
		$doc = json_decode($json);
		
		$doc->_id = doi_to_identifier($doi);
	}

	return $doc;
}

//----------------------------------------------------------------------------------------


$dois=array(
//'10.34434/za000199',
'10.1080/21686351.1922.12280292',
);

$force = false;
//$force = true;

$count = 1;

foreach ($dois as $doi)
{
	$identifier = doi_to_identifier($doi);

	$go = true;

	if (!$force)
	{
		$go = !$couch->exists($identifier);
	}

	if ($go)
	{
		$doc = get_work($doi);
	
		if ($doc)
		{
			upload($doc, $force);
		}
		else
		{
			echo "DOI $doi not found\n";
		}
		
		// Give server a break every 100 items
		if (($count++ % 10) == 0)
		{
			$rand = rand(1000000, 3000000);
			echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
			usleep($rand);
		}		
		
	}
	else
	{
		echo "We have $doi already\n";
	}

}

?>
