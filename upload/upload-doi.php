<?php

require_once (dirname(dirname(__FILE__)) . '/compare.php');
require_once (dirname(__FILE__) . '/upload.php');

//----------------------------------------------------------------------------------------
function doi_to_agency($prefix, $doi)
{
	global $prefix_to_agency;
	
	$agency = '';
			
	if (isset($prefix_to_agency[$prefix]))
	{
		$agency = $prefix_to_agency[$prefix];
	}
	else
	{
		$url = 'https://doi.org/ra/' . $doi;
	
		$json = get($url);
	
		echo $json;
	
		$obj = json_decode($json);
	
		if ($obj)
		{
			if (isset($obj[0]->RA))
			{
				$agency = $obj[0]->RA;
		
				$prefix_to_agency[$prefix] = $agency;
			}
			else
			{
				// Bad DOI
				if (isset($obj[0]->status))
				{
					$agency = $obj[0]->status;
				}
			}
	
		}
	}
	
	return $agency;
}

//----------------------------------------------------------------------------------------
function doi_to_identifier($doi)
{
	$doi = strtolower($doi);
	$identifier = 'https://doi.org/' . $doi;
	return $identifier;
}

//----------------------------------------------------------------------------------------
// Content negotiation
function get_work($doi)
{
	$doc = null;
	
	$url = 'https://doi.org/' . $doi;
	
	$json = get($url, 'application/vnd.citationstyles.csl+json');
	
	if ($json != '')
	{
		$doc = json_decode($json);
		
		// clean HTML tags, etc.
		if (isset($doc->title))
		{
			if (is_array($doc->title))
			{
				$doc->title[0] = clean_text($doc->title[0]);
			}
			else
			{
				$doc->title = clean_text($doc->title);
			}
		}
		
		$doc->DOI = strtolower($doc->DOI);	
		
		$doc->_id = doi_to_identifier($doi);
	}

	return $doc;
}

//----------------------------------------------------------------------------------------


$dois=array(
//'10.34434/za000199',
'10.1080/21686351.1922.12280292',
);

$dois=array(
'10.5962/bhl.title.50815',
'10.5479/si.00963801.60-2413.1',
'10.1111/j.1096-3642.1922.tb01493.x',
'10.1080/00222932208632741',
'10.1080/00222932208632683',
'10.1080/00222932208632685',
'10.1080/00222932208632677',
'10.1080/00222932208632707',
'10.1080/00222932308632764',
'10.1080/00222932208632753',
'10.1111/j.1744-7348.1922.tb05941.x',
'10.5962/bhl.title.21645',
'10.2307/1536521',
'10.1017/s0016756800084867',
'10.1017/s0007485300045144',
'10.5962/bhl.title.19393',
'10.1002/mmnd.192619260406',
'10.5962/bhl.title.6785',
'10.1111/j.1096-3642.1922.tb00554.x',
'10.1080/00222932208632642',
'10.3354/meps327001',
'10.5962/bhl.title.46678',
'10.1002/mmnz.4830100205',
'10.1038/1108060',
'10.5962/bhl.title.12504',
'10.1017/s0031182000009938',
'10.5479/si.00963801.61-2431.1',
'10.2307/20025913',
'10.5479/si.00963801.61-2434.1',
'10.5479/si.00963801.2429',
'10.5479/si.00963801.60-2421.1',
'10.5479/si.00963801.61-2436.1',
'10.5479/si.00963801.61-2445.1',
'10.1111/j.1096-3642.1922.tb03300.x',
'10.3853/j.0067-1975.13.1922.874',
'10.1111/j.1096-3642.1922.tb00547.x',
'10.2307/3492826',
'10.3406/befeo.1922.2980',
'10.2307/3221669',
'10.1111/j.1365-2311.1922.tb02828.x',
'10.1080/00359192209519288',
'10.1111/j.1365-2311.1923.tb02844.x',
'10.1111/j.1096-3642.1922.tb01843.x',
'10.1111/j.1439-0418.1921.tb01626.x',
);

$dois=[
'10.1080/00034983.1922.11684316',
];

$dois=[
'10.1163/26660644-02201004',
];

$dois=[
'10.5169/seals-270930',
];

// 10.3897/zookeys.834.28800
// Annotated checklist of the terrestrial molluscs from Laos...
$dois=[
'10.1080/00222935608697501',
'10.1080/00222935608697626',
'10.4002/040.061.0201',
'10.1080/03745484209445368',
'10.3366/jsbnh.1980.9.part_4.477',
'10.1080/00222939608680326',
'10.1093/oxfordjournals.mollus.a066138',
'10.1111/j.1749-6632.1858.tb00358.x',
'10.1007/s00267-004-0211-x',
'10.3897/zookeys.589.7933',
'10.5852/ejt.2017.330',
'10.3366/jsbnh.1969.5.3.236',
'10.1080/13235818.2016.1200959',
'10.3897/zookeys.287.4617',
'10.1038/35002501',
'10.3897/zookeys.411.7258',
'10.1127/arch.moll/132/2003/121',
'10.1127/arch.moll/1869-0963/139/045-069',
'10.1127/arch.moll/1869-0963/140/149-173',
'10.12657/folmal.022.025',
'10.11646/zootaxa.3937.1.1',
'10.3897/zookeys.473.8659',
'10.3897/zookeys.523.6114',
'10.11646/zootaxa.4139.3.9',
'10.3897/zookeys.592.8118',
'10.11646/zootaxa.4331.1.1',
'10.11646/zootaxa.1648.1.1',
'10.1017/s096042861700035x',
'10.1111/j.1749-6632.1858.tb00339.x',
'10.3897/zookeys.287.4572',
'10.3897/zookeys.401.7075',
'10.1080/00222939608680338',
'10.1093/mollus/eyi044',
'10.3897/zookeys.492.8641',
'10.5962/bhl.title.61796',
'10.1111/j.1469-7998.1865.tb02358.x',
'10.3366/anh.1999.26.2.157',
'10.12657/folmal.008.002',
];

// from https://doi.org/10.11609/jott.8516.15.12.24368-24395
$dois=[
"10.1127/arch.moll/124/1995/89",
"10.11609/jott.zpj.17.10.921",
"10.1080/03745485809494679",
"10.1080/03745486009496158",
"10.1080/03745486009494813",
"10.1080/03745486009494926",
"10.1080/00222935708693934",
"10.1080/00222936308681554",
"10.1080/00222936408681588",
"10.1080/00222936508681753",
"10.21276/ambi.2015.02.2.ra02",
"10.1111/j.1469-7998.1905.tb08348.x",
"10.4002/040.061.0201",
"10.4002/040.052.0201",
"10.3897/zookeys.492.9175",
"10.3897/zookeys.675.13252",
"10.5852/ejt.2017.337",
"10.1017/9781108758826",
"10.1127/arch.moll/0003-9284/138/043-052",
"10.1127/arch.moll/1869-0963/142/137-156",
"10.12657/folmal.012.016",
"10.1007/s10661-022-09869-x",
"10.2307/1004939",
"10.11609/jott.7165.14.3.20747-20757",
"10.3390/su10124504",
"10.5962/bhl.",
"10.1093/oxfordjournals.mollus.a064912",
"10.3897/zookeys.529.6139",
"10.5962/bhl.title.11903",
"10.1111/j.1469-7998.1853.tb07179.x",
"10.3161/000345409484847",
"10.5962/bhl.title.8129",
"10.5962/bhl.title.8129",
"10.5962/bhl.title.8129",
"10.1007/s12526-018-0883-8",
"10.1007/978-981-15-4327-2_11",
"10.1080/00222933.2019.1615566",
"10.1080/19475705.2020.1756464",
"10.1127/arch.moll/123/1994/127",
"10.1007/978-1-4020-8259-7_17",
"10.1186/s40663-017-0100-4",
"10.1007/978-81-322-2178-4_11",
"10.53550/pr.2022.v41i01.052",
"10.3897/rio.3.e20860",
];

$dois=['10.1111/j.1365-2311.1922.tb02828.x'];

$dois=['10.5281/zenodo.16173'];

$force = false;
$force = true;

$count = 1;

$source = 'unknown';

$prefix_filename = dirname(__FILE__) . '/prefix.json';
$json = file_get_contents($prefix_filename);
$prefix_to_agency = json_decode($json, true);

foreach ($dois as $doi)
{
	// get DOI resolving agency
	$parts = explode('/', $doi);
	$prefix = $parts[0];
		
	$source = doi_to_agency($prefix, $doi);
	
	// Full URL version of DOI
	$identifier = doi_to_identifier($doi);
	
	echo $identifier . "\n";

	$go = true;

	if (!$force)
	{
		$go = !$couch->exists($identifier);
	}

	if ($go)
	{
		$doc = get_work($doi);
		
		//print_r($doc);
	
		if ($doc)
		{
			upload($doc, $source, $force);
		}
		else
		{
			echo "DOI $doi not found\n";
		}
		
		// Give server a break every 100 items
		if (($count++ % 10) == 0)
		{
			$rand = rand(1000000, 3000000);
			echo "\n-- ...sleeping for " . round(($rand / 1000000),2) . ' seconds' . "\n\n";
			usleep($rand);
		}		
		
	}
	else
	{
		echo "We have $doi already\n";
	}

}

// save prefix file
file_put_contents($prefix_filename, json_encode($prefix_to_agency));


?>
