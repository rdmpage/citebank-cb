<?php

// Upload CSL (in JSONL format) 

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/upload.php');

$force = false;
$force = true;

$filename = '';
if ($argc < 2)
{
	echo "Usage: upload_jsonl.php <CSL as JSONL file> \n";
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

$count = 1;

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$json = trim(fgets($file_handle));
	
	if ($json == "") break;
	
	if (preg_match('/^\{/', $json))
	{
		$doc = json_decode($json);
				
		if (!isset($doc->_id))
		{
			// Can we use the local id for this record?			
			if (isset($doc->id))
			{
				// Can't use numbers
				if (!is_numeric($doc->id))
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
	else
	{
		echo "Expected JSON object on single line (JSONL) but got: $json\n";
		exit();
	}
}

print_r($dois);


?>
