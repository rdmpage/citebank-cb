<?php

// Compare a pair of citations using features and a ML model

require_once(dirname(__FILE__) . '/feature.php');

//----------------------------------------------------------------------------------------
// Given a feature vector return true if it represents a matching pair, false otherwise
function is_match ($vector)
{
	// rule-based hack
	$same = 0;
	$diff = 0;
	$miss = 0;
	
	$num_features = count($vector);
	
	for ($feature_index = 0; $feature_index < $num_features; $feature_index += 2)
	{
		if ($vector[$feature_index] == 1)
		{
			$same++;
		}
		else
		{
			if ($vector[$feature_index+1] == 1)
			{
				$diff++;
			}
			else
			{
				$miss++;
			}
		}
	}
	
	// three or more same and no difference is a hit
	$is_match = false;
	
	if ($same >= 3 && $diff === 0)
	{
		$is_match = true;
	}
	
	return $is_match;
}

//----------------------------------------------------------------------------------------
//
// Input is an array of two CSL-JSON objects (and optionally a third element 
// which is an integer but which we ignore here). Based on a list of keys we 
// create a set of features and return a vector of 1's and 0's.
// 
// This method can be used as part of training (in which case the third array element is a
// flag as to whether the records match), or just as a comparison of two records.
//
// things to handle
// arrays
// fields being either arrays or strings (e.g., titles)
// numbers being roman or arabic, but still the same
// dates having years, months, days
//
function citation_pair_to_feature_vector($obj, $date_precision = 1)
{

	// fields to compare
	$keys = array('author', 'title', 'container-title', 'volume', 'issue', 'page', 'issued', 'DOI');

	foreach ($keys as $k)
	{
		switch ($k)
		{

			case 'volume':
			case 'issue':
			case 'page':
			case 'DOI':				
				$features[] 
					= feature_exact(
						$k, 
						$k, 
						$obj[0],
						$obj[1]
						);								
				break;
			
			case 'title':
				$features[] 
					= feature_subsequence(
						$k, 
						$k, 
						$obj[0],
						$obj[1]
						);
				break;
			
			case 'container-title':
				$features[] 
					= feature_subsequence(
						$k, 
						$k, 
						$obj[0],
						$obj[1]
						);
				break;	
			
			// date as array
			case 'issued':			
				if ($date_precision > 0)
				{
					// year
					$features[] 
						= feature_date_array(
							$k, 
							$k, 
							$obj[0],
							$obj[1],
							0
							);
				}
				
				if ($date_precision > 1)
				{
					// month
					$features[] 
						= feature_date_array(
							$k, 
							$k, 
							$obj[0],
							$obj[1],
							1
							);
				}

				if ($date_precision > 2)
				{
					// day
					$features[] 
						= feature_date_array(
							$k, 
							$k, 
							$obj[0],
							$obj[1],
							2
							);
				}
				break;	
				
			// first author
			case 'author':
				$features[] 
					= feature_author_in_list(
						$k, 
						$k, 
						$obj[0],
						$obj[1],
						0
						);
				break;
								
			default:
				break;
	
	
		}
	}
	
	$result = new stdclass;
	$result->k_v = array();
	$result->vector = array();
	
	foreach ($features as $feature)
	{	
		foreach ($feature as $k => $v)
		{
			$result->k_v[$k] = $v;
		
			$result->vector[] = $v;
		}
	}
	
	return $result;
}

// test
if (0)
{

	$filename = 'csl.json';

	$file = @fopen($filename, "r") or die("couldn't open $filename");
		
	$file_handle = fopen($filename, "r");
	while (!feof($file_handle)) 
	{
		$line = trim(fgets($file_handle));	
	
		$obj = json_decode($line);
	
		if(json_last_error() == JSON_ERROR_NONE)
		{
	
			print_r($obj);
		
			$vector = citation_pair_to_feature_vector($obj, true);
		
			print_r($vector);
		}
	}
}

// training
if (0)
{
	$filename = 'csl.json';

	$file = @fopen($filename, "r") or die("couldn't open $filename");
		
	$file_handle = fopen($filename, "r");
	while (!feof($file_handle)) 
	{
		$line = trim(fgets($file_handle));	
		
		$obj = json_decode($line);
	
		if(json_last_error() == JSON_ERROR_NONE)
		{	
			//print_r($obj);
		
			$vector = citation_pair_to_feature_vector($obj);
			
			$vector[] = $obj[2]; // flag to indicate match or not
		
			echo join("\t", $vector) . "\n";
		}
	}
}

if (0)
{
	$json = '[{"_id":"https://doi.org/10.1111/j.1440-6055.1997.tb01440.x","_rev":"1-c4632d8e91b0b6f23675b21227efdc01","indexed":{"date-parts":[[2023,10,29]],"date-time":"2023-10-29T06:11:39Z","timestamp":1698559899718},"reference-count":11,"publisher":"Wiley","issue":"2","license":[{"start":{"date-parts":[[2007,3,31]],"date-time":"2007-03-31T00:00:00Z","timestamp":1175299200000},"content-version":"vor","delay-in-days":3621,"URL":"http://onlinelibrary.wiley.com/termsAndConditions#vor"}],"content-domain":{"domain":[],"crossmark-restriction":false},"published-print":{"date-parts":[[1997,5]]},"abstract":"<jats:p> <jats:italic>Pseudobalta</jats:italic>, a new blattellid genus, is described. It differs from oviparous <jats:italic>Balta</jats:italic> Tepper in being ovoviviparous and the male having a tergal gland on the first abdominal segment. <jats:italic>Pseudobalta</jats:italic> comprises two species previously placed in <jats:italic>Balta (P. pusilla</jats:italic> (Hebard) comb.n. and <jats:italic>P. cinctella</jats:italic> (Hebard) comb.n.) and <jats:italic>P. queenslandica</jats:italic> sp.n. This is the fourth known blattellid, and the second taxon in the Pseudophyllodromiinae, that reproduces by ovoviviparity.</jats:p>","DOI":"10.1111/j.1440-6055.1997.tb01440.x","type":"journal-article","created":{"date-parts":[[2007,4,2]],"date-time":"2007-04-02T19:12:45Z","timestamp":1175541165000},"page":"101-108","source":"Crossref","is-referenced-by-count":4,"title":"<i>Pseudobalta</i>, a New Australian Ovoviviparous Cockroach Genus (Dictyoptera: Blattaria: Blattellidae: Pseudophyllodromiinae)","prefix":"10.1111","volume":"36","author":[{"given":"LOUIS M.","family":"ROTH","sequence":"first","affiliation":[]}],"member":"311","published-online":{"date-parts":[[2007,3,31]]},"reference":[{"key":"e_1_2_1_2_1","first-page":"1","article-title":"Blattidae of the subfamilies Chorisoneurinae and Ectobiinae Orthoptera","volume":"4","author":"HEBARD M.","year":"1943","journal-title":"Monog. Acad. nat. Sci. Philad."},{"key":"e_1_2_1_3_1","unstructured":"MCKITTRICK F. A.(1964).Evolutionary studies of cockroaches. Cornell Univ. Agr. Exp. Sta. Mem. No. 389 197pp."},{"key":"e_1_2_1_4_1","first-page":"711","article-title":"Blattariae, subordo Epilamproidea, Fam. Blattellidae","volume":"13","author":"PRINCIS K.","year":"1969","journal-title":"Orthopterorum Catalogus, Pt."},{"key":"e_1_2_1_5_1","first-page":"1","article-title":"Catalogue of Australian cockroaches","volume":"21","author":"RENTZ D. C. F.","year":"1983","journal-title":"CSIRO, Div. Ent. Tech. Pap."},{"key":"e_1_2_1_6_1","doi-asserted-by":"publisher","DOI":"10.1093/aesa/61.1.83"},{"key":"e_1_2_1_7_1","doi-asserted-by":"publisher","DOI":"10.1146/annurev.en.15.010170.000451"},{"key":"e_1_2_1_8_1","first-page":"277","article-title":"Ovoviviparity in the blattellid cockroach Symploce bimaculata Gerstaecker) Dictyoptera: Blattaria: Blattellidae","volume":"84","author":"ROTH L. M.","year":"1982","journal-title":"Proc. ent. Soc. Wash."},{"key":"e_1_2_1_9_1","doi-asserted-by":"publisher","DOI":"10.1163/187631284X00109"},{"key":"e_1_2_1_10_1","first-page":"441","article-title":"Sliferia, a new ovoviviparous cockroach genus Blattellidae) and the evolution of ovoviviparity in Blattaria Dictyoptera","volume":"91","author":"ROTH L. M.","year":"1989","journal-title":"Proc. ent. Soc. Wash."},{"key":"e_1_2_1_11_1","doi-asserted-by":"publisher","DOI":"10.1155/1995/92482"},{"key":"e_1_2_1_12_1","first-page":"97","article-title":"Cockroaches from the Seychelles Islands Dictyoptera: Blattaria","volume":"110","author":"ROTH L. M.","year":"1996","journal-title":"J. Afr. Zool"}],"container-title":"Australian Journal of Entomology","original-title":[],"language":"en","link":[{"URL":"https://api.wiley.com/onlinelibrary/tdm/v1/articles/10.1111%2Fj.1440-6055.1997.tb01440.x","content-type":"unspecified","content-version":"vor","intended-application":"text-mining"},{"URL":"https://onlinelibrary.wiley.com/doi/pdf/10.1111/j.1440-6055.1997.tb01440.x","content-type":"unspecified","content-version":"vor","intended-application":"similarity-checking"}],"deposited":{"date-parts":[[2023,10,28]],"date-time":"2023-10-28T05:49:18Z","timestamp":1698472158000},"score":1,"resource":{"primary":{"URL":"https://onlinelibrary.wiley.com/doi/10.1111/j.1440-6055.1997.tb01440.x"}},"subtitle":[],"short-title":[],"issued":{"date-parts":[[1997,5]]},"references-count":11,"journal-issue":{"issue":"2","published-print":{"date-parts":[[1997,5]]}},"alternative-id":["10.1111/j.1440-6055.1997.tb01440.x"],"URL":"http://dx.doi.org/10.1111/j.1440-6055.1997.tb01440.x","relation":{},"ISSN":["1326-6756","1440-6055"],"subject":[],"container-title-short":"Australian Journal of Entomology","published":{"date-parts":[[1997,5]]},"citebank":{"format":"application/vnd.citationstyles.csl+json","created":"2024-05-26T16:40:18+00:00","modified":"2024-05-26T16:40:18+00:00"}},{"_id":"https://zoobank.org/References/ca131643-1fba-4751-a05c-01d51f2f417e","_rev":"1-602c428c976cc7f1be5e1ed800240671","id":"ca131643-1fba-4751-a05c-01d51f2f417e","ZOOBANK":"CA131643-1FBA-4751-A05C-01D51F2F417E","URL":"https://zoobank.org/References/ca131643-1fba-4751-a05c-01d51f2f417e","issued":{"date-parts":[[1997]]},"title":"Pseudobalta, a new Australian ovoviviparous cockroach genus (Dictyoptera: Blattaria: Blattellidae: Pseudophyllodromiinae)","volume":"36","issue":"2","page-first":"101","page":"101-108","LSID":"urn:lsid:zoobank.org:pub:CA131643-1FBA-4751-A05C-01D51F2F417E","container-title":"Australian Journal of Entomology","author":[{"family":"Roth","given":"Louis M.","ZOOBANK":"30D6AA6C-8396-4CD6-A76A-3FEE67D0A6B3"}],"ISSN":["1326-6756","1440-6055"],"DOI":"10.1111/j.1440-6055.1997.tb01440.x","citebank":{"format":"application/vnd.citationstyles.csl+json","created":"2024-05-26T16:38:01+00:00","modified":"2024-05-26T16:38:01+00:00"}}]';


	$obj = json_decode($json);
	
	if(json_last_error() == JSON_ERROR_NONE)
	{

		print_r($obj);
	
		$features = citation_pair_to_feature_vector($obj, 3);
	
		print_r($features);
	}


}

?>
