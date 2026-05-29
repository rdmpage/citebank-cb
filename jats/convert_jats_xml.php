<?php

// Import structured bibliography from JATS XML and output as CSL-JSON in jsonl format

require_once(dirname(__FILE__) . '/jats_mixed_to_csl.php');
require_once(dirname(__FILE__) . '/jats_to_csl.php');

require_once (dirname(dirname(__FILE__)) . '/utilities.php');

//--------------------------------------------------------------------------------------------------
$filename = '';
if ($argc < 2)
{
	echo "Usage: convert_jats_xml.php <JATS XML file> \n";
	exit(1);
}
else
{
	$filename = $argv[1];
}

$file = @fopen($filename, "r") or die("couldn't open $filename");
fclose($file);

$xml = file_get_contents($filename);

$bibliography = jats_to_csl($xml);

// sanity check, it's not unknown for mixed-citation in particualr to miss key
// items about a reference, in which case we parse the unstructured reference to_neuron
// try and fix things.
foreach ($bibliography as &$item)
{
	//print_r($item);
	
	// sanity check
	$ok = true;
	
	if ($item->type == 'article-journal')
	{
		if (!isset($item->title))
		{
			$ok = false;
		}
		
		if (!isset($item->{'container-title'}))
		{
			$ok = false;
		}
	}
	
	if ($item->type == 'book')
	{
		if (!isset($item->title))
		{
			$ok = false;
		}
	}
	
	
	if (!$ok)
	{
		//print_r($item);
		
		// can we fix it? yes we can 		
		if (isset($item->unstructured))
		{	
			$url = 'http://localhost/citation-parsing/api.php?text=' . urlencode($item->unstructured);
		
			$json = get($url);
		
			//echo $json;
		
			$doc = json_decode($json);
			
			if (isset($doc[0]))
			{
				$csl = $doc[0];
				
				// only use some values as we expect JATS got some thing correct ;)
				$keys = ['title', 'container-title', 'volume', 'issue', 'page', 'publisher', 'DOI', 'URL'];
				
				foreach ($keys as $key)
				{
					if (isset($csl->{$key}))
					{
						$item->{$key} = $csl->{$key};
					}
				}
			}
		}		
	}
	
	// clean up
	unset($item->unstructured);
}

foreach ($bibliography as $item)
{
	if (0)
	{
		// formatted for debugging
		echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
	else
	{
		// formated as single line of JSON
		echo json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
	echo "\n";
}

?>
