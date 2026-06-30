<?php

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/upload.php');

// Visit all references and do something with them

$sourcedir = '/Users/rpage/Development/zootaxa-cites-data/articles';

$files1 = scandir($sourcedir);

// debugging
//$files1 = array('1409');
//$files1 = array('434');

//$files1 = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100];

foreach ($files1 as $directory)
{
	if (preg_match('/^\d+$/', $directory))
	{	
		echo $directory . "\n";		
		
		$files2 = scandir($sourcedir . '/' . $directory);
		
		//$files2 = array('1409.1.1.json');

		foreach ($files2 as $filename)
		{
			if (preg_match('/\.json$/', $filename))
			{
				echo $filename . "\n";
				
				$full_filename = $sourcedir . '/' . $directory . '/' . $filename;
				
				$json = file_get_contents($full_filename);
				
				echo $json . "\n";
				
				$command = 'php ' . dirname(__FILE__) . '/upload-json.php ' . $full_filename;
				
				echo $command . "\n";
				
				system($command);
			}
		}
	}
}

?>
