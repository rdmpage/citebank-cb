<?php

// Parse a text file with one reference per line and export as CSL-JSON in JSONL format

require_once (dirname(dirname(__FILE__)) . '/utilities.php');

$filename = '';
$identifier = ''; // optional DOI or other identifier

if ($argc < 2)
{
    echo "Usage: " . basename(__FILE__) . "  <text file with references> [identifier]\n";
    exit(1);
}
else
{
    $filename = $argv[1];

    // Optional second argument
    if ($argc >= 3)
    {
        $identifier = $argv[2];
    }
}

// echo "Filename: $filename\n";

if (!is_readable($filename))
{
	die("couldn't open $filename\n");
}

if ($identifier !== '')
{
    // echo "Identifier: $identifier\n";
}

// read lines and parse

$row_counter = 0;

$file_handle = fopen($filename, "r");
while (!feof($file_handle)) 
{
	$text = trim(fgets($file_handle));
	
	$url = 'http://localhost/citation-parsing/api.php?text=' . urlencode($text);

	$json = get($url);

	//echo $json;

	$doc = json_decode($json);
	
	if (isset($doc[0]))
	{
		$csl = $doc[0];
		
		$csl->id = $filename . '#row=' . $row_counter;
		
		if ($identifier != '')
		{
			$citing_work = new stdclass;
			
			if (preg_match('/(https?:\/\/(dx.)?doi.org\/)(?<doi>.*)/', $identifier, $m))
			{				
				$citing_work->DOI = strtolower($m['doi']);
			}
			elseif (preg_match('/^(http|urn)/', $identifier))
			{
				$citing_work->URL = $identifier;
			}
			
			if (isset($citing_work->DOI) || isset($citing_work->URL))
			{						
				$csl->{'is-referenced-by'} = [$citing_work];
			}
		}
		
		echo json_encode($csl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
	}	
	
	$row_counter++;			
}	


