<?php

// Extract citations as text strings. This is useful if XML markup is incomplete

error_reporting(E_ALL);

// Example files
// fevo-11-00651.xml

//----------------------------------------------------------------------------------------
// Recursively traverse DOM and process tags
function dive($dom, $node, &$text)
{	
	
	if ($node->nodeName == '#text')
	{
		$text[] = $node->nodeValue;
	}
		
	if ($node->hasChildNodes())
	{
		foreach ($node->childNodes as $children) 
		{
			dive ($dom, $children, $text);
		}
	}
}

//----------------------------------------------------------------------------------------
// Extract mixed citations and parse
function jats_mixed_to_csl($xml)
{
	$bibliography = array();

	$dom= new DOMDocument;
	$dom->loadXML($xml);
	$xpath = new DOMXPath($dom);

	$xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

	// identifier for article
	$work_id = '';

	// DOI of parent article
	$work_doi = '';

	$xpath_query = '//article/front/article-meta/article-id[@pub-id-type="doi"]';
	$nodeCollection = $xpath->query ($xpath_query);
	foreach($nodeCollection as $node)
	{
		$work_doi = $node->firstChild->nodeValue;
		$work_id = 'https://doi.org/' . $work_doi;
	}
	
	// If no DOI we will need another way to create a unique identifier for this article
	if ($work_id == '')
	{
		$work_id = md5($xml); // hash the XML
	}

	$xpath_query = '//back/ref-list/ref';
	$nodeCollection = $xpath->query ($xpath_query);
	foreach($nodeCollection as $node)
	{
		if ($node->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $node->attributes; 
		
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
		
			$key = $attributes['id'];
		}
		
		$citation = new stdclass;
	
		// identifier as fragment of work id
		$citation->id = '#' . $key;
		$citation->id = $work_id . $citation->id;	
	
		//echo $node->textContent . "\n";
	
		//echo $node->textContent . "\n";
		
		// we need to grab the content and not everything will be tagged :(
		
		$text = array();
		
		dive($dom, $node, $text);
		
		$unstructured = join(' ', $text);
		
		$unstructured = trim($unstructured);
		$unstructured = preg_replace('/\(\s+/', '(', $unstructured);
		$unstructured = preg_replace('/\s+\)/', ')', $unstructured);
		$unstructured = preg_replace('/\s\s+/', ' ', $unstructured);
		$unstructured = preg_replace('/ – /', '–', $unstructured);
		$unstructured = preg_replace('/\s+\./', '.', $unstructured);
		
		$citation->unstructured = $unstructured;
		
		$bibliography[] = $citation;
	
	
	}
	
	return $bibliography;
}

?>
