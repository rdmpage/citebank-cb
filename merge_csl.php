<?php

// Merge an array of CSL-JSON records into a single record

error_reporting(E_ALL);

//----------------------------------------------------------------------------------------
function add_unique_value(&$unique_values, $key, $value)
{
	if (!isset($unique_values[$key]))
	{
		$unique_values[$key] = array();
	}
	if (!in_array($value, $unique_values[$key]))
	{
		$unique_values[$key][] = $value;
	}	
}

//----------------------------------------------------------------------------------------
function add_value(&$values, $key, $value, $index)
{
	if (!isset($values[$key]))
	{
		$values[$key] = array();
	}
	$values[$key][$index] = $value;
}

//----------------------------------------------------------------------------------------
// Merge an array of CSL-JSON records, for most keys we are interested in a consensus
// of multiple values, but for some keys such as is-referrenced-by we are combining
// information from each record.
function merge ($objs, $confidence = array())
{
	$result = new stdclass;
	$result->debug = false;

	$keys = array('author', 'title', 'container-title', 'volume', 'issue', 'page', 
		'issued','DOI');

	$unique_values = array();
	$values = array();
	
	if (count($confidence) == 0)
	{
		foreach ($objs as $index => $obj)
		{
			$confidence[] = 0.8;
		}		
	}
	
	$result->confidence	= $confidence;
	
	$result->{'is_referenced-by'} = [];

	//----------------------------------------------------------------------------------------
	// clean and simplify object, make sure title and container-title are strings, and
	// replace author array by a string
	foreach ($objs as $index => $obj)
	{
		foreach ($keys as $k)
		{
			if (isset($obj->$k))
			{
				// echo "k=$k\n";
		
				switch ($k)
				{			
					case 'author':
						$authors = array();
						foreach ($obj->$k as $author)
						{
							if (isset($author->literal))
							{
								if (preg_match('/(.*),\s+(.*)/', $author->literal, $m))
								{
									$authors[] = $m[2] . ' ' . $m[1];
								}
								else
								{						
									$authors[] = $author->literal;
								}
							}
							else
							{
								$name_parts = array();
								if (isset($author->given))
								{
									$name_parts[] = $author->given;
								}
								if (isset($author->family))
								{
									$name_parts[] = $author->family;
								}
								$name = trim(join(' ', $name_parts));
								if ($name != '')
								{
									$authors[] = $name;
								}
							}
						}
					
						if (count($authors) > 0)
						{
							$value = join(';', $authors);
							$objs[$index]->{$k} = $value;				
						}
						break;
			
					case 'title':
					case 'container-title':
						$value = $obj->{$k};
						if (is_array($value))
						{
							$value = $value[0];
							$objs[$index]->{$k} = $value;
						}
						break;
					
					case 'DOI':
						$value = strtolower($obj->{$k});
						$objs[$index]->{$k} = $value;
						break;
					
					
					default:
						break;
				}
			}
		}
		
		if (isset($obj->{'is-referenced-by'}))
		{
			foreach ($obj->{'is-referenced-by'} as $reference)
			{
				if (isset($reference->DOI))
				{
					$result->{'is-referenced-by'}[] = $reference->DOI;
				}
			}
		}					
		
	}

	foreach ($objs as $index => $obj)
	{
		// echo "index=$index\n";	
		foreach ($keys as $k)
		{
			if (isset($obj->$k))
			{
				// echo "k=$k\n";
		
				switch ($k)
				{			
					case 'author':
					case 'title':
					case 'container-title':
					case 'volume':
					case 'issue':
					case 'page':
					case 'DOI':
						$value = $obj->{$k};					
						add_unique_value($unique_values, $k, $value);
						add_value($values, $k, $value, $index);
						break;
					
						// complicated data structure so extract year, month and day, and
						// add them to the CSL-JSON as extra fields so we can still build 
						// our consensus object.
					case 'issued':					
						$n = count($obj->{$k}->{'date-parts'}[0]);
					
						if ($n >= 1)
						{
							$value = $obj->{$k}->{'date-parts'}[0][0];
						
							add_unique_value($unique_values, 'issued-year', $value);
							add_value($values, 'issued-year', $value, $index);
						
							$objs[$index]->{'issued-year'} = $value; // hack
						}

						if ($n >= 2)
						{
							$value = $obj->{$k}->{'date-parts'}[0][1];
						
							add_unique_value($unique_values, 'issued-month', $value);
							add_value($values, 'issued-month', $value, $index);
						
							$objs[$index]->{'issued-month'} = $value; // hack
						}

						if ($n >=3)
						{
							$value = $obj->{$k}->{'date-parts'}[0][2];
						
							add_unique_value($unique_values, 'issued-day', $value);
							add_value($values, 'issued-day', $value, $index);
						
							$objs[$index]->{'issued-day'} = $value; // hack
						}
						break;


					default:
						break;
				}
			}
		}
	}

	if ($result->debug)
	{
		echo "\nUnique values\n";
		print_r($unique_values);

		echo "\nValues\n";
		print_r($values);
	}
	
	$vectors = array();

	$keys = array_keys($values);

	foreach ($keys as $k)
	{
		$num_records = count($objs);
		for ($i = 0; $i < $num_records; $i++)
		{
			$n = count($unique_values[$k]);
		
			if ($n > 0)
			{
				$vector = array();
				for ($j = 0; $j < $n; $j++)
				{
					$vector[$j] = 0;
				}
			
				if (isset($objs[$i]->{$k}))
				{
					$pos = array_search($objs[$i]->{$k}, $unique_values[$k]);
					$vector[$pos] = 1;
					$vectors[$k][$i] = $vector;
				}
				else
				{
					$vectors[$k][$i] = null;
				}
		
			}
		

	
		}
	}

	if ($result->debug)
	{
		echo "\nVectors\n\n";
		foreach ($vectors as $k => $v)
		{
			echo str_pad($k, 20, ' ', STR_PAD_LEFT) . ' ' . json_encode($v) . "\n";
		}
		echo "\n";
	}
	
	// log
	$result->log[] =   "Vectors";
	foreach ($vectors as $k => $v)
	{
		$result->log[] =  str_pad($k, 20, ' ', STR_PAD_LEFT) . ' ' . json_encode($v);
	}

	//----------------------------------------------------------------------------------------

	$result->consensus = new stdclass;
	
	if ($result->debug)
	{
		echo "\nBelief\n\n";
	}
	
	$result->log[] =  'Belief';

	foreach ($keys as $k)
	{
		if (isset($vectors[$k]))
		{	
			$belief = array();
		
			$num_records = count($objs);
			for ($i = 0; $i < $num_records; $i++)
			{
				if (isset($vectors[$k][$i]))
				{
					$b = array();
				
					$n = count($vectors[$k][$i]);
				
					for ($j = 0; $j < $n; $j++)
					{
						if ($vectors[$k][$i][$j] == 1)
						{
							$b[$j] = $confidence[$i];
						}
						else
						{
							$b[$j] = (1 - $confidence[$i]) / ($n - 1);
						}
					}
				
					//echo json_encode($b) . "\n";
				
					$num_beliefs = count($belief);
					if ($num_beliefs == 0)
					{
						$belief = $b;
					}
					else
					{
						$sum = 0;
						for ($m = 0; $m < $num_beliefs; $m++)
						{
							$belief[$m] = $belief[$m] * $b[$m];
							$sum += $belief[$m];
						}
						for ($m = 0; $m < $num_beliefs; $m++)
						{
							$belief[$m] = round($belief[$m] / $sum, 2);
						}
					
					}
				}		
			}
		
			if ($result->debug)
			{
				echo str_pad($k, 20, ' ', STR_PAD_LEFT)  . ' ' . json_encode($belief) . "\n";
			}
			
			$result->log[] = str_pad($k, 20, ' ', STR_PAD_LEFT)  . ' ' . json_encode($belief);
			
			$best_value = $best_value  = $unique_values[$k][0];
			$max_belief = 0;
		
			foreach ($belief as $pos => $belief_value)
			{
				if ($belief_value > $max_belief)
				{
					$max_belief = $belief_value;
					$best_value  = $unique_values[$k][$pos];
				}
			}
		
			if ($result->debug)
			{
				echo str_pad($k, 20, ' ', STR_PAD_LEFT)  . ' ' . $best_value . "\n";
			}
			
			$result->log[] = str_pad($k, 20, ' ', STR_PAD_LEFT)  . ' ' . $best_value;
		
			switch ($k)
			{
				case 'issued-year':
				case 'issued-month':
				case 'issued-day':
					if (!isset($result->consensus->issued))
					{
						$result->consensus->issued = new stdclass;
						$result->consensus->issued->{'date-parts'} = array(); 	
						$result->consensus->issued->{'date-parts'}[] = array();
					}
					switch ($k)
					{
						case 'issued-year':
							$result->consensus->issued->{'date-parts'}[0][0] = $best_value;
							break;

						case 'issued-month':
							$result->consensus->issued->{'date-parts'}[0][1] = $best_value;
							break;

						case 'issued-day':
							$result->consensus->issued->{'date-parts'}[0][2] = $best_value;
							break;
						
						default:
							break;				
					}
					break;
				
				case 'author':
					$names = explode(';', $best_value);
				
					$result->consensus->author = array();
					foreach ($names as $name)
					{
						$author = new stdclass;
						if (preg_match('/(.*),\s+(.*)/', $name, $m))
						{
							$author->family = $m[1];
							$author->given = $m[2];
						}
						else
						{
							$parts = explode(' ', $name);
							$n = count($parts);
							if ($n == 1)
							{
								$author->family = $name;
							}
							else
							{
								$author->family = $parts[$n - 1];
								array_pop($parts);
								$author->given = join(' ', $parts);								
							}						
						}
					
						$result->consensus->author[] = $author;
					}
					break;
		
				case 'page':
					if (count($belief) > 1)
					{
						$result->warnings[] = "More than one set of pages";
					}
					$result->consensus->{$k} = $best_value;
					break;
					
		
				default:
					$result->consensus->{$k} = $best_value;
					break;					
			}
		}
	}
	
	$result->consensus->{'is-referenced-by'} = $result->{'is-referenced-by'};
		
	if ($result->debug)
	{
		echo "\n";
	}	
		
	return $result;
}

//----------------------------------------------------------------------------------------

if (0)
{

	$json = '[

		  {
			"type": "article-journal",
			"author": [
			  {
				"family": "Naggs",
				"given": "F."
			  }
			],
			"title": "William Benson and the early study of land snails in British India and Ceylon",
			"container-title": "Archives of Natural History",
			"volume": "24",
			"page": "37-88",
			"issued": {
			  "date-parts": [
				[
				  1997
				]
			  ]
			}
		  },
		  {
			  "issue": "1",
			 "short-container-title": [
			  "Archives of Natural History"
			],
			"published-print": {
			  "date-parts": [
				[
				  1997,
				  2
				]
			  ]
			},
			"DOI": "10.3366/anh.1997.24.1.37",
			"type": "journal-article",
			"created": {
			  "date-parts": [
				[
				  2010,
				  7,
				  28
				]
			  ],
			  "date-time": "2010-07-28T11:30:41Z",
			  "timestamp": 1280316641000
			},
			"page": "37-88",
			"source": "Crossref",
			"is-referenced-by-count": 16,
			"title": [
			  "William Benson and the early study of land snails in British India and Ceylon"
			],
			"prefix": "10.3366",
			"volume": "24",
			"author": [
			  {
				"given": "FRED",
				"family": "NAGGS",
				"sequence": "first",
				"affiliation": []
			  }
			],
			"container-title": [
			  "Archives of Natural History"
			],
			"original-title": [],
			"language": "en",
			"deposited": {
			  "date-parts": [
				[
				  2021,
				  2,
				  15
				]
			  ],
			  "date-time": "2021-02-15T03:42:48Z",
			  "timestamp": 1613360568000
			},

			"issued": {
			  "date-parts": [
				[
				  1997,
				  2
				]
			  ]
			},


			"alternative-id": [
			  "10.3366/anh.1997.24.1.37"
			],
			"URL": "http://dx.doi.org/10.3366/anh.1997.24.1.37",
			"ISSN": [
			  "0260-9541",
			  "1755-6260"
			],
			"issn-type": [
			  {
				"value": "0260-9541",
				"type": "print"
			  },
			  {
				"value": "1755-6260",
				"type": "electronic"
			  }
			],
			"subject": [
			  "Agricultural and Biological Sciences (miscellaneous)",
			  "History",
			  "Anthropology"
			],
			"published": {
			  "date-parts": [
				[
				  1997,
				  2
				]
			  ]
			}
  
		  }
		]';


	$json = '[
{
  "_id": "6cbf26f6c62f19d946be3f87bc90b9ac",
  "_rev": "3-ecf256f54344d17305e21f6197e2e304",
  "type": "article-journal",
  "author": [
    {
      "family": "Dendy",
      "given": "A."
    }
  ],
  "title": "Report on the Sigmatotetraxonida collected by H. M. S. ‘Sealark’ in the Indian Ocean",
  "container-title": "In: Reports of the Percy Sladen Trust Expedition to the Indian Ocean in 1905.",
  "volume": "7",
  "page": "1-164",
  "issued": {
    "date-parts": [
      [
        1922
      ]
    ]
  },
  "DOI": "10.1111/j.1096-3642.1922.tb00547.x",
  "is-referenced-by": [
    {
      "DOI": "10.11646/zootaxa.3815.3.4"
    }
  ],
  "citebank": {
    "type": "work",
    "format": "application/vnd.citationstyles.csl+json",
    "source": "1922.jsonl",
    "created": "2026-05-14T09:47:17+00:00",
    "modified": "2026-05-14T09:47:17+00:00",
    "fetched": "2026-05-14T09:47:17+00:00",
    "cluster": "6cbf26f6c62f19d946be3f87bc90b9ac"
  }
},	
{
  "_id": "7406317dcd4f4cb2206aba523707f458",
  "_rev": "3-bc06b25fb39f89e2fae37eb3fd9a930f",
  "type": "article-journal",
  "author": [
    {
      "family": "Dendy",
      "given": "A."
    }
  ],
  "title": "Report on the Sigmatotetraxonida collected by H.M.S.‘Sealark’ in the Indian Ocean. In: Reports of the Percy Sladen Trust Expedition to the Indian Ocean in 1905, Vol. 7",
  "container-title": "Transactions of the Linnean Society of London",
  "collection-title": "2",
  "volume": "18",
  "page": "1-164",
  "issued": {
    "date-parts": [
      [
        1922
      ]
    ]
  },
  "DOI": "10.1111/j.1096-3642.1922.tb00547.x",
  "is-referenced-by": [
    {
      "DOI": "10.11646/zootaxa.3702.4.4"
    }
  ],
  "citebank": {
    "type": "work",
    "format": "application/vnd.citationstyles.csl+json",
    "source": "1922.jsonl",
    "created": "2026-05-14T09:47:17+00:00",
    "modified": "2026-05-14T09:47:17+00:00",
    "fetched": "2026-05-14T09:47:17+00:00",
    "cluster": "7406317dcd4f4cb2206aba523707f458"
  }
}	
	
	
	]';



	$objs = json_decode($json);
	$result = merge($objs, []);
	print_r($result);
}

?>
