<?php

// Compare fields of objects and return _same and _diff values
// same [1, 0]
// diff [0, 1]
// miss [0, 0]

error_reporting(E_ALL);

require_once (dirname(__FILE__) . '/compare.php');

//----------------------------------------------------------------------------------------
// Coerce a CSL field that may be a string, a single-element array (e.g. CrossRef
// container-title), null, or missing, into a non-empty string. Returns null if
// there's nothing usable to compare.
function csl_first_string($v)
{
	if (is_array($v))
	{
		$v = isset($v[0]) ? $v[0] : null;
	}
	if (!is_string($v) || $v === '')
	{
		return null;
	}
	return $v;
}

//----------------------------------------------------------------------------------------
function feature_exact($name, $key, $obj1, $obj2)
{
	$same_key = $name . '_same';
	$diff_key = $name . '_diff';

	$feature = array(
		$same_key => 0,
		$diff_key => 0	
	);
	
	if (isset($obj1->{$key}) && isset($obj2->{$key}))
	{
	
		$comparison = compare_simple($obj1->{$key}, $obj2->{$key});
		
		($comparison->normalised == 1) ? $feature[$same_key] = 1 : $feature[$diff_key] = 1;
	
	}
	
	return $feature;
}

//----------------------------------------------------------------------------------------
function feature_levenstein($name, $key, $obj1, $obj2)
{
	$same_key = $name . '_same';
	$diff_key = $name . '_diff';

	$feature = array(
		$same_key => 0,
		$diff_key => 0	
	);
	
	if (isset($obj1->{$key}) && isset($obj2->{$key}))
	{
		$text1 = csl_first_string($obj1->{$key});
		$text2 = csl_first_string($obj2->{$key});

		if ($text1 !== null && $text2 !== null)
		{
			$comparison = compare_levenshtein($text1, $text2);
			($comparison->normalised > 0.9) ? $feature[$same_key] = 1 : $feature[$diff_key] = 1;
		}
	}

	return $feature;
}

//----------------------------------------------------------------------------------------
function feature_subsequence($name, $key, $obj1, $obj2)
{
	$same_key = $name . '_same';
	$diff_key = $name . '_diff';

	$feature = array(
		$same_key => 0,
		$diff_key => 0	
	);
	
	if (isset($obj1->{$key}) && isset($obj2->{$key}))
	{
		$text1 = csl_first_string($obj1->{$key});
		$text2 = csl_first_string($obj2->{$key});

		if ($text1 !== null && $text2 !== null)
		{
			$comparison = compare_common_subsequence($text1, $text2);
			($comparison->normalised > 0.8) ? $feature[$same_key] = 1 : $feature[$diff_key] = 1;
		}
	}

	return $feature;
}

//----------------------------------------------------------------------------------------
function feature_date_array($name, $key, $obj1, $obj2, $index = 0)
{
	$same_key = $name . '_' . $index . '_same';
	$diff_key = $name . '_' . $index . '_diff';

	$feature = array(
		$same_key => 0,
		$diff_key => 0	
	);
	
	if (isset($obj1->{$key}) && isset($obj2->{$key}))
	{
		if (isset($obj1->{$key}->{'date-parts'}[0][$index]) && isset($obj2->{$key}->{'date-parts'}[0][$index]))
		{
			$comparison = compare_simple($obj1->{$key}->{'date-parts'}[0][$index], $obj2->{$key}->{'date-parts'}[0][$index]);
		
			($comparison->normalised == 1) ? $feature[$same_key] = 1 : $feature[$diff_key] = 1;

		}
	}
	
	return $feature;
}

//----------------------------------------------------------------------------------------
// Continuous features
//----------------------------------------------------------------------------------------
//
// The features above collapse a similarity score to same/diff at a fixed
// threshold, which throws away everything the comparison actually measured: a
// title differing by one character and a completely unrelated title both come
// out as "diff". In practice that leaves only 87 distinct feature vectors across
// the whole database, which is the ceiling on anything learned from them.
//
// These emit the score itself, plus a presence flag so that "missing" stays
// distinguishable from "present and scored 0":
//
//     [score, present]   score in [0,1], present in {0,1}
//
// present = 0 forces score = 0, so a model can read the pair unambiguously.

//----------------------------------------------------------------------------------------
// Shared shape for the continuous features below.
function feature_pair($name, $score = 0.0, $present = 0)
{
	if (!$present)
	{
		$score = 0.0;
	}

	return array(
		$name . '_score'   => round((float)$score, 6),
		$name . '_present' => $present ? 1 : 0
	);
}

//----------------------------------------------------------------------------------------
// Text similarity under a named comparator: 'trigram', 'edit' or 'subsequence'.
function feature_text_score($name, $key, $obj1, $obj2, $method = 'trigram')
{
	if (!isset($obj1->{$key}) || !isset($obj2->{$key}))
	{
		return feature_pair($name);
	}

	$text1 = csl_first_string($obj1->{$key});
	$text2 = csl_first_string($obj2->{$key});

	if ($text1 === null || $text2 === null)
	{
		return feature_pair($name);
	}

	switch ($method)
	{
		case 'edit':
			$comparison = compare_edit_similarity($text1, $text2);
			break;

		case 'subsequence':
			$comparison = compare_common_subsequence($text1, $text2);
			break;

		case 'trigram':
		default:
			$comparison = compare_trigram($text1, $text2);
			break;
	}

	return feature_pair($name, $comparison->normalised, 1);
}

//----------------------------------------------------------------------------------------
// Exact string identity as a 0/1 score, with presence tracked separately.
function feature_exact_score($name, $key, $obj1, $obj2)
{
	if (!isset($obj1->{$key}) || !isset($obj2->{$key}))
	{
		return feature_pair($name);
	}

	$comparison = compare_simple($obj1->{$key}, $obj2->{$key});

	return feature_pair($name, ($comparison->normalised == 1) ? 1 : 0, 1);
}

//----------------------------------------------------------------------------------------
// First page number, compared numerically.
//
// CSL "page" is messy ("101-108", "101–8", "e12345"), and comparing the whole
// string exactly means "101-108" and "101-8" look different when they are the
// same article. Comparing the leading number is far more robust.
function feature_first_page_score($name, $obj1, $obj2)
{
	$first_page = function ($obj)
	{
		if (isset($obj->{'page-first'}) && preg_match('/(\d+)/', (string)$obj->{'page-first'}, $m))
		{
			return (int)$m[1];
		}
		if (isset($obj->page) && preg_match('/(\d+)/', (string)csl_first_string($obj->page), $m))
		{
			return (int)$m[1];
		}
		return null;
	};

	$p1 = $first_page($obj1);
	$p2 = $first_page($obj2);

	if ($p1 === null || $p2 === null)
	{
		return feature_pair($name);
	}

	return feature_pair($name, ($p1 === $p2) ? 1 : 0, 1);
}

//----------------------------------------------------------------------------------------
// Publication year, as a graded proximity rather than a hard match.
//
// Year disagreement by one is extremely common in the taxonomic literature
// (cover date vs actual date of publication, reprints, "1899 [1900]"), so a
// binary same/diff on year is both noisy and, because is_match() treats any
// "diff" as a veto, actively harmful. This decays linearly over $window years.
function feature_year_score($name, $key, $obj1, $obj2, $window = 5)
{
	$year_of = function ($obj) use ($key)
	{
		if (!isset($obj->{$key}))
		{
			return null;
		}
		if (isset($obj->{$key}->{'date-parts'}[0][0]))
		{
			return (int)$obj->{$key}->{'date-parts'}[0][0];
		}
		if (is_scalar($obj->{$key}) && preg_match('/(\d{4})/', (string)$obj->{$key}, $m))
		{
			return (int)$m[1];
		}
		return null;
	};

	$y1 = $year_of($obj1);
	$y2 = $year_of($obj2);

	if ($y1 === null || $y2 === null || $y1 === 0 || $y2 === 0)
	{
		return feature_pair($name);
	}

	$delta = abs($y1 - $y2);
	$score = ($delta >= $window) ? 0.0 : (1.0 - ($delta / $window));

	return feature_pair($name, $score, 1);
}

//----------------------------------------------------------------------------------------
// First author family name, as an edit-distance similarity rather than exact
// identity -- initials, transliteration and OCR noise mean exact matching is
// unnecessarily brittle ("Meijere" vs "Meijére", "Roth" vs "Rotb").
function feature_author_score($name, $key, $obj1, $obj2, $index = 0)
{
	if (!isset($obj1->{$key}[$index]) || !isset($obj2->{$key}[$index]))
	{
		return feature_pair($name);
	}

	$person1 = $obj1->{$key}[$index];
	$person2 = $obj2->{$key}[$index];

	if (!isset($person1->family) || !isset($person2->family))
	{
		return feature_pair($name);
	}

	$comparison = compare_edit_similarity($person1->family, $person2->family);

	return feature_pair($name, $comparison->normalised, 1);
}

//----------------------------------------------------------------------------------------
function feature_author_in_list($name, $key, $obj1, $obj2, $index = 0)
{
	$same_key = $name . '_' . $index . '_same';
	$diff_key = $name . '_' . $index . '_diff';
	
	$feature = array(
		$same_key => 0,
		$diff_key => 0	
	);
	
	if (isset($obj1->{$key}) && isset($obj2->{$key}))
	{
		if (isset($obj1->{$key}[$index]) && isset($obj2->{$key}[$index]))
		{
			$person1 = $obj1->{$key}[$index];
			$person2 = $obj2->{$key}[$index];
			
			// simplest comparison is to match last name (assumes name has been parsed)
			if (isset($person1->family) && isset($person2->family))
			{
				$comparison = compare_simple($person1->family, $person2->family);
		
				($comparison->normalised == 1) ? $feature[$same_key] = 1 : $feature[$diff_key] = 1;
			
			}
				
		
		}
	}
	
	return $feature;
}	
	



?>
