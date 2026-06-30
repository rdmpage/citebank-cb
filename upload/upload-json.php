<?php

// Upload from a JSON array of one or more CSL objects

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/upload.php');

$force = false;
$force = true;

$filename = '';
if ($argc < 2)
{
	echo "Usage: " . basename(__FILE__) . " <CSL as JSON file> \n";
	exit(1);
}
else
{
	$filename = $argv[1];
}

if (!is_readable($filename))
{
	die("couldn't open $filename\n");
}

$dois = array();

$json = file_get_contents($filename);
if (preg_match('/^\[/', $json))
{
	$obj = json_decode($json);
	
	if ($obj)
	{
		$count = 1;
		
		foreach ($obj as $doc)
		{
			if (!isset($doc->_id))
			{
				// Can we use the local id for this record?			
				if (isset($doc->id))
				{
					// Can't use numbers as they are unlikely to be unique
					if (!preg_match('/^(pub:)?\d+$/', $doc->id))
					{
						$doc->_id = $doc->id;
					}
				}
				
				if (!isset($doc->_id))
				{
					$doc->_id = md5($json);
				}
			}
			
			if (isset($doc->DOI))
			{
				$dois[] = $doc->DOI;
			}
			
			upload($doc, $filename, $force);
					
			// Give server a break every 100 items
			if (($count++ % 100) == 0)
			{
				$rand = rand(1000000, 3000000);
				echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
				usleep($rand);
			}				
		}
	}
	else
	{
		echo "Failed to parse JSON\n";
		exit();
	}
		
}
else
{
	echo "Expected a JSON array $json\n";
	exit();
}

print_r($dois);

?>
