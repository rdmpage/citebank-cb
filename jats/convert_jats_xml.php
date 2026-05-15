<?php

// Import structured bibliography from JATS XML and output as CSL-JSON in jsonl format

require_once(dirname(__FILE__) . '/jats_mixed_to_csl.php');
require_once(dirname(__FILE__) . '/jats_to_csl.php');

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

//print_r($bibliography);

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
