<?php

// Extract bibliographic data from HTML
require_once (dirname(dirname(__FILE__)) . '/utilities.php');
require_once (dirname(__FILE__) . '/HtmlDomParser.php');
use Sunra\PhpSimple\HtmlDomParser;

$filename = '';
if ($argc < 2)
{
	echo "Usage: " . basename(__FILE__). " <HTML filename> \n";
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

$html = file_get_contents($filename);

$dom = HtmlDomParser::str_get_html($html);
if ($dom)
{	
	$reference_counter = 0;

	// get information on parent work	
	$identifier = '';
		
	// meta
	foreach ($dom->find('meta') as $meta)
	{		
		switch ($meta->name)
		{				
			case 'citation_doi':
				$identifier = $meta->content;
				break;
				
			case 'citation_abstract_html_url':
			case 'eprints.official_url':
				if ($identifier == '')
				{
					$identifier = $meta->content;
				}
				break;

			default:
				break;
		}
	}
	
	if ($identifier == '')
	{
		// use hash
		$identifier = sha1($html);
	}
	
	// Literature cited
	$references = [];
	
	$citation_reference_count = 0;
	
	$citation_references = $dom->find('meta[name=citation_reference]');
	
	if ($citation_references)
	{
		$citation_reference_count =  count($citation_references);
	}	
	
	foreach ($dom->find('meta') as $meta)
	{		
		switch ($meta->name)
		{				
			case 'citation_reference':
				// this could be one reference, it could be multiple scrunched into one tag 
				// e.g Zootaxa
				
				if ($citation_reference_count == 1)
				{
					// either just one reference, or more likely, a block of them					
					$h = html_entity_decode($meta->content);
					
					$d = HtmlDomParser::str_get_html($h);
					if ($d)
					{
						// Zootaxa
						foreach ($d->find('p[class=HeadingRunIn]') as $p)
						{
							$references[] = $p->plaintext;
						}
					}				
				}
				else
				{
					$references[] = $meta->content;
				}	
				break;
				
			default:
				break;
		}
	}
	
	// process references we have extracted
	foreach ($references as $index => $text)
	{
		$url = 'http://localhost/citation-parsing/api.php?text=' . urlencode($text);
	
		$json = get($url);
	
		//echo $json;
	
		$doc = json_decode($json);
		
		if (isset($doc[0]))
		{
			$csl = $doc[0];
			
			$csl->id = $identifier . '#row=' . $index;
			
			if ($identifier != '')
			{
				$citing_work = new stdclass;
				
				// add link to parent work (e.g., article corresponding to this HTML page)
				if (preg_match('/(https?:\/\/(dx.)?doi.org\/)?(?<doi>10\.\d+.*)/', $identifier, $m))
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
	}	

}

?>
