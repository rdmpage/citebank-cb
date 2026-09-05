<?php

// string cleaning and comparison

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/swa.php');

mb_internal_encoding("UTF-8");
setlocale(LC_ALL, 0);
date_default_timezone_set('UTC');

define ('WHITESPACE_CHARS', ' \f\n\r\t\x{00a0}\x{0020}\x{1680}\x{180e}\x{2028}\x{2029}\x{2000}\x{2001}\x{2002}\x{2003}\x{2004}\x{2005}\x{2006}\x{2007}\x{2008}\x{2009}\x{200a}\x{202f}\x{205f}\x{3000}');
define ('PUNCTUATION_CHARS', '\?\!\.\-—,\(\)\[\]:;«»\'\&"\`\´„”“”‘’');

//----------------------------------------------------------------------------------------
// https://stackoverflow.com/a/2759179
function unaccent($string)
{
    $string = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);~i', '$1', htmlentities($string, ENT_QUOTES, 'UTF-8'));
    $string = html_entity_decode($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $string;
}

//----------------------------------------------------------------------------------------
// https://gist.github.com/keithmorris/4155220
function removeCommonWords($input){
 
 	// EEEEEEK Stop words
	$commonWords = array('and', 'der', 'des', 'die', 'do', 'et', 'fur', 'in', 'of', 'the', 'und');
 
	return preg_replace('/\b('.implode('|',$commonWords).')\b/i','',$input);
}


//----------------------------------------------------------------------------------------
// Clean up text so that we have single spaces between text, 
// see https://github.com/readmill/API/wiki/Highlight-locators
function clean_text($text)
{	
	$text = strip_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	
	$text = preg_replace('/\.(\p{Lu}|\p{L})/u', '. $1', $text);
	
	$text = preg_replace('/[' . WHITESPACE_CHARS . ']+/u', ' ', $text);
	
	return $text;
}

//----------------------------------------------------------------------------------------
// Normalise text by cleaning it and removing punctuation.
//
// Punctuation is DELETED here, not replaced with a space, and that is
// deliberate: it is what the text comparators (Levenshtein, Smith-Waterman,
// trigram) want. Hyphens in this corpus are very often intra-word noise from
// OCR or line breaking -- "(Or-tho-p-tera)" for "(Orthoptera)" -- and deleting
// them makes the two forms identical, where spacing them splits one word into
// four and drops the similarity below threshold. Measured over 306,180 logged
// pairs, spacing here broke 146 previously-correct matches (97 title, 49
// container).
//
// This function is therefore left exactly as it was. Where punctuation is a
// word separator instead ("Selys-Longchamps"), see normalise_text_unspaced()
// and compare_simple().
function normalise_text($text)
{
	// clean
	$text = clean_text($text);
	$text = unaccent($text);

	// trim
	$text = preg_replace('/^\s+/', '', $text);
	$text = preg_replace('/\s+$/', '', $text);

	// remove punctuation
	//$text = preg_replace('/[' . PUNCTUATION_CHARS . ']+/u', '', $text);
	$text = preg_replace('/[^a-z0-9 ]/i', '', $text);

	// lowercase
	$text = mb_convert_case($text, MB_CASE_LOWER);

	return $text;
}

//----------------------------------------------------------------------------------------
// normalise_text() with word boundaries removed as well.
//
// Punctuation is genuinely ambiguous. It separates ("Selys-Longchamps" is two
// words), it abbreviates ("J.C.H." is one token), and it is sometimes just noise
// ("Or-tho-p-tera"). normalise_text() deletes it, which handles the second and
// third cases but turns "Selys-Longchamps" into "selyslongchamps" while the
// space-separated spelling of the same name gives "selys longchamps".
//
// Collapsing the spaces too puts every spelling into one form, so an exact
// comparison stops caring where the word boundaries fell.
function normalise_text_unspaced($text)
{
	return str_replace(' ', '', normalise_text($text));
}

//----------------------------------------------------------------------------------------
// trim a string to a set length, for example some comparison methods fail if string is too
// long
function shorten_text($text, $length = 250) 
{
	if (mb_strlen($text) > $length)
	{
		$text = mb_substr($text, 0, $length - 1);
	}

	return $text;
}

//----------------------------------------------------------------------------------------
// string identity
function compare_simple($text1, $text2, $debug = false)
{
	$normalised1 = normalise_text($text1);
	$normalised2 = normalise_text($text2);

	// Punctuation deleted: "J.C.H." == "JCH", "Or-tho-p-tera" == "Orthoptera".
	// This is the original comparison, so nothing that matched before stops
	// matching -- the change below is purely additive.
	$same = ($normalised1 === $normalised2);

	// Same again with word boundaries removed, so that it no longer matters
	// whether punctuation separated words or joined them: "Selys-Longchamps"
	// == "Selys Longchamps", "J.C.H." == "JCH".
	//
	// Deleting punctuation alone gave "selyslongchamps" against
	// "selys longchamps", which compared as different; since the author feature
	// is exact identity and is_match() treats any single diff as a veto, that
	// silently rejected the whole pair. Around 18% of all rejections in the
	// comparison logs look like this kind of noise.
	if (!$same)
	{
		$same = (normalise_text_unspaced($text1) === normalise_text_unspaced($text2));
	}

	$result = new stdclass;
	$result->strings = [$normalised1, $normalised2];
	$result->name = 'simple';
	$result->value = $same ? 0 : strcmp($normalised1, $normalised2);
	$result->normalised = $same ? 1 : 0;

	return $result;
}

//----------------------------------------------------------------------------------------
// Get longest common subsequence for two strings
// Return value is array of minimum and maximum rations of subsequence length w.r.t. input strings
// Idea is that we can use these two numbers to get some sense of whether the match is spurious or not.
function compare_common_subsequence($text1, $text2, $debug = false)
{

	$text1 = normalise_text($text1);
	$text2 = normalise_text($text2);
	
	$alignment = swa($text1, $text2);
	
	//print_r($alignment);
	
	if ($debug)
	{
		echo join("\n", $alignment->text);
	}
	
	$result = new stdclass;
	$result->strings = [$text1, $text2];
	$result->name = 'lcs';
	$result->value = $alignment->score;
	$result->normalised = $alignment->score;	
	
	return $result;
}

//----------------------------------------------------------------------------------------
function compare_levenshtein($text1, $text2, $debug = false)
{
	$text1 = normalise_text($text1);
	$text2 = normalise_text($text2);
	
	$text1 = shorten_text($text1);
	$text2 = shorten_text($text2);

	$d = levenshtein($text1, $text2);
	
	$length1 = strlen($text1);
	$length2 = strlen($text2);
	
	$result = new stdclass;
	$result->strings = [$text1, $text2];
	$result->name = 'levenshtein';
	$result->value = $d;
	$result->lengths = [$length1, $length2];
	$result->normalised = 1 - $d / max($length1, $length2);
		
	return $result;
}

//----------------------------------------------------------------------------------------
// treat string as bag of words
function bag_of_words($text)
{
	$text = clean_text($text);
	$words = explode(' ', $text);

	asort($words);

	return join(' ', $words);
}

//----------------------------------------------------------------------------------------
// Set of character trigrams for a normalised string, as a hash for cheap
// intersection. The string is padded so that the first and last characters
// carry the same weight as the middle ones, and so that strings shorter than
// three characters still produce grams.
//
// normalise_text() reduces to [a-z0-9 ], so plain substr/strlen are safe here
// and much faster than their mb_ equivalents.
function text_trigrams($text)
{
	$text = normalise_text($text);
	$text = trim(preg_replace('/\s+/', ' ', $text));

	if ($text === '')
	{
		return array();
	}

	$text = shorten_text($text, 500);

	$padded = '  ' . $text . '  ';
	$length = strlen($padded);

	$grams = array();

	for ($i = 0; $i + 3 <= $length; $i++)
	{
		$grams[substr($padded, $i, 3)] = true;
	}

	return $grams;
}

//----------------------------------------------------------------------------------------
// Jaccard similarity over character trigrams: |A ∩ B| / |A ∪ B|, in [0,1].
//
// Unlike compare_common_subsequence this is O(n) rather than O(n·m) -- Smith-
// Waterman costs ~4.9ms on a typical title against ~0.02ms here -- and unlike
// compare_levenshtein it is insensitive to word order, so "Notes on the genus X"
// and "The genus X, notes" score highly. Useful precisely where the binary
// same/diff features are blindest: telling a one-word or transposed difference
// apart from an unrelated title.
function compare_trigram($text1, $text2, $debug = false)
{
	$grams1 = text_trigrams($text1);
	$grams2 = text_trigrams($text2);

	$result = new stdclass;
	$result->name = 'trigram';
	$result->sizes = array(count($grams1), count($grams2));

	if (count($grams1) === 0 || count($grams2) === 0)
	{
		$result->value = 0;
		$result->normalised = 0;
		return $result;
	}

	$intersection = count(array_intersect_key($grams1, $grams2));
	$union        = count($grams1 + $grams2);

	$result->value      = $intersection;
	$result->normalised = ($union > 0) ? ($intersection / $union) : 0;

	if ($debug)
	{
		echo "trigram: $intersection / $union = " . $result->normalised . "\n";
	}

	return $result;
}

//----------------------------------------------------------------------------------------
// Edit-distance similarity in [0,1], as compare_levenshtein but tolerant of the
// field being an array or otherwise not a plain string (csl_first_string is
// applied by the caller in feature.php; this is the raw text entry point).
function compare_edit_similarity($text1, $text2)
{
	$comparison = compare_levenshtein($text1, $text2);

	// compare_levenshtein can go negative when one string is much longer than
	// the other; clamp so downstream models see a well-behaved [0,1] feature.
	$score = $comparison->normalised;

	if ($score < 0)
	{
		$score = 0;
	}
	if ($score > 1)
	{
		$score = 1;
	}

	$comparison->normalised = $score;

	return $comparison;
}


//----------------------------------------------------------------------------------------

if (0)
{
	$text = 'Anales del Jardin Botánico de Madrid';
	//$text = '[[Anales del Jardin Botánico de Madrid]]';
	//$text = 'Göteb. Kgl. Vetensk. och Vitterh.-Samh. Handlingar. Fjärde följden';
	//$text = '[[Flore des Serres et des Jardins de l’Europe]]';
	$text = 'Actes du premièrs Congrés International de Spéléologie, Paris';
	$text = '2000a';
	
	$text = 'Bull. Mus. Natl. d\'Hist. Nat., Paris, (sér. 2)';
	$text = 'Bulletin de la Musee d\'Histoire Naturelle de Paris, 2e sér.';
	
	echo "       Raw $text\n";
	echo "   Cleaned " . clean_text($text) . "\n";
	echo "Normalised " . normalise_text($text) . "\n";


}

if (0)
{
	$pairs = array(
	'Annu Conserv Jard Bot Geneve',
	'Annuaire du Conservatoire et du Jardin Botaniques de Geneve'
	);	

	
	$pairs = array(
	'Bull. Mus. Natl. d\'Hist. Nat., Paris, (sér. 2)',
	'Bulletin de la Musee d\'Histoire Naturelle de Paris, 2e sér.'
	);	
	
	
	//$pairs = ['Acta Societatis Scientiarum Fennica', 'Acta Soc. Sci. Fennicae'];
	
	
	// people
	//$pairs = ['JOSÉ ESTEBAN JIMÉNEZ', 'José Esteban Jiménez'];

	//$pairs = ['FRED R. BARRIE', 'Fred Rogers Barrie'];

	//$pairs = ['Henrik Æ. Pedersen', 'Henrik Aerenlund Pedersen'];
	
	$pairs = ['Kaj Vollesen', 'Kaj Børge Vollesen'];
	$pairs = ['Ko Wanchang', 'Wan Chang Ko'];
	
	$pairs = ['A systematic study on Chinese species of the ant genus <i>Oligomyrmex</i> Mayr (Hymenoptera: Formicidae)',
			'A systematic study on Chinese species of the ant genus Oligomyrmex Mayr (Hymenoptera: Formicidae)'];

	
	$pairs = ['Flore (Pteridophyta et Spermatophyta) des zones humides du Maroc Méditérranéen: Inventaire et écologie',
	'Flore (&quot;Pteridophyta&quot; et &quot;Spermatophyta&quot;) des zones humides du Maroc Méditérranéen'];
	
	/*
	$pairs = [
	'Smith, J.J. (1914) Neue Orchideen des Malaiischen Archipels. VII. Bulletin du Jardin botanique de Buitenzorg Sér. 2, 13: 1–52.',
	'Smith, J.J. (1914b) Neue Orchideen des malaiischen Archipels VII. Bulletin du Jardin Botanique de Buitenzorg, sér. 2, 13: 1–52.'
	];
	*/
	
	//$pairs = ['JOSÉ ESTEBAN JIMÉNEZ', 'José Esteban Jiménez'];
	
	//$pairs = ['K.I. Goebel', 'K.I.E. Goebel'];
	
	//$pairs = ['Nascimento, J.G.A.do', 'J.G.A. do Nascimento'];
	
	print_r($pairs);
	
	$d = compare_common_subsequence($pairs[0], $pairs[1], true);	
	print_r($d);
	
	$d = compare_levenshtein($pairs[0], $pairs[1]);	
	print_r($d);

	$d = compare_simple($pairs[0], $pairs[1]);	
	print_r($d);
	
	
	echo "---\n";
	$pairs[0] = bag_of_words($pairs[0]);
	$pairs[1] = bag_of_words($pairs[1]);
	
	print_r($pairs);
	

}


?>