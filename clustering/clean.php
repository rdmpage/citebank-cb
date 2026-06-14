<?php

// Normalisation + blocking-key generation for journal-name clustering.
//
// Ported and trimmed from ~/Development/journal-name-clustering/clean.php, with
// the single first-letter "acronym" sort key replaced by prefix-based keys
// (first N chars of each significant word), which are far more robust to journal
// abbreviations (e.g. "Arachnol" and "Arachnologica" share the prefix "ara").

define('WHITESPACE_CHARS', ' \f\n\r\t\x{00a0}\x{0020}\x{1680}\x{180e}\x{2028}\x{2029}\x{2000}\x{2001}\x{2002}\x{2003}\x{2004}\x{2005}\x{2006}\x{2007}\x{2008}\x{2009}\x{200a}\x{202f}\x{205f}\x{3000}');

// Prefix length used to build the substring keys.
define('KEY_PREFIX_LEN', 3);

//----------------------------------------------------------------------------------------
// https://stackoverflow.com/a/2759179 — strip accents/diacritics to ASCII.
function unaccent($string)
{
	$string = preg_replace('~&([a-z]{1,2})(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron);~i', '$1', htmlentities($string, ENT_QUOTES, 'UTF-8'));
	$string = html_entity_decode($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	return $string;
}

//----------------------------------------------------------------------------------------
// Stop words across the main European languages these journal names appear in.
function removeCommonWords($input)
{
	$commonWords = array(
		// en
		'and', 'at', 'from', 'in', 'of', 'on', 'the',
		// de
		'aus', 'dem', 'der', 'das', 'des', 'die', 'fur', 'und', 'zu', 'zur',
		// fr
		'de', 'du', 'et', 'la', 'le', 'les',
		// es
		'del', 'y',
		// it
		'della', 'di',
		// nl
		'van',
		// pt
		'da', 'do', 'e',
		// other
		'v',
	);

	$input = preg_replace('/\b(' . implode('|', $commonWords) . ')\b/i', '', $input);
	$input = preg_replace('/\s\s+/', ' ', $input);
	$input = preg_replace('/^\s+/', '', $input);

	return $input;
}

//----------------------------------------------------------------------------------------
// Collapse whitespace, split likely acronyms (e.g. "PNAS" -> "P N A S") so that
// acronyms and their expansions share word prefixes.
function clean_text($text)
{
	$text = strip_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	// Drop d'/l' style prefixes that would interfere with matching.
	$text = preg_replace("/[d|l][\'|’]/iu", "", $text);

	// Ensure spaces after a "." that abuts a letter.
	$text = preg_replace('/\.(\p{Lu}|\p{L})/u', '. $1', $text);

	// Split probable acronyms into individual letters (skipped when text is all caps).
	if (!preg_match('/^[\p{Lu}\s"]+$/', $text)) {
		$text = preg_replace_callback('/\b[A-Z]{2,}\b/',
			function ($matches) {
				return implode(' ', str_split($matches[0]));
			},
			$text);
	}

	$text = preg_replace('/[' . WHITESPACE_CHARS . ']+/u', ' ', $text);

	return $text;
}

//----------------------------------------------------------------------------------------
// Lower-cased, accent-free, punctuation-free single-spaced form.
function normalise_text($text)
{
	$text = clean_text($text);
	$text = unaccent($text);
	$text = preg_replace('/[^a-z0-9 ]/i', '', $text);
	$text = mb_convert_case($text, MB_CASE_LOWER);
	$text = trim($text);
	return $text;
}

//----------------------------------------------------------------------------------------
// Significant tokens: normalised, stop words removed, blanks dropped.
function tokenise_string($string)
{
	$string = normalise_text($string);
	$string = removeCommonWords($string);

	$tokens = array();
	foreach (explode(' ', $string) as $t) {
		$t = trim($t);
		if ($t !== '') {
			$tokens[] = $t;
		}
	}

	return $tokens;
}

//----------------------------------------------------------------------------------------
// True if either string is a prefix of the other.
function starts_with_either($a, $b)
{
	if ($a === '' || $b === '') {
		return false;
	}
	return strpos($a, $b) === 0 || strpos($b, $a) === 0;
}

//----------------------------------------------------------------------------------------
// True if one token is a plausible abbreviation of the other: simplest case is a
// prefix relationship, plus a few hard-coded non-prefix abbreviations.
function is_abbreviation($a, $b)
{
	if (starts_with_either($a, $b)) {
		return true;
	}

	if (strlen($a) > strlen($b)) {
		list($a, $b) = array($b, $a);
	}
	switch ($a) {
		case 'boln': return $b === 'boletin';
		case 'qld':  return $b === 'queensland';
		default:     return false;
	}
}

//----------------------------------------------------------------------------------------
// Keep only tokens useful for keys: drop pure-numeric tokens (volume/year noise).
function key_tokens($tokens)
{
	$out = array();
	foreach ($tokens as $t) {
		if (ctype_digit($t)) {
			continue;
		}
		$out[] = $t;
	}
	return $out;
}

//----------------------------------------------------------------------------------------
// First KEY_PREFIX_LEN characters of a token (whole token if shorter).
function token_prefix($token)
{
	return substr($token, 0, KEY_PREFIX_LEN);
}

//----------------------------------------------------------------------------------------
//----------------------------------------------------------------------------------------
// Strip series / new-series markers, which are usually noise: they typically mark
// the same journal or a continuation of it ("ser. 10", "n. s.", "nouvelle série",
// "Neue Folge", ...). Returns [stripped_text, captured_series_string] so the marker
// is removed from matching/keying but retained for possible later sub-splitting.
//
// Expects already-normalised text (lower case, [a-z0-9 ] only). Genuine content
// words that distinguish parallel sections (e.g. "Serie A (Biologie)") survive,
// because only the marker token is removed, not the following subject word.
function strip_series($text)
{
	$series = array();
	$capture = function ($m) use (&$series) { $series[] = trim($m[0]); return ' '; };

	// ordinal/number + series  (fourth series, 4th series)
	$text = preg_replace_callback(
		'/\b(first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|[0-9]{1,3}(st|nd|rd|th)?)\s+(series|serie|ser)\b/',
		$capture, $text);
	// qualified new-series phrases
	$text = preg_replace_callback(
		'/\b(new|nova|nouvelle|neue|nuova|neudruck)\s+(series|serie|ser|folge|reihe)\b/',
		$capture, $text);
	// abbreviated new series / neue folge: "n s", "n f", "n ser"
	$text = preg_replace_callback('/\bn\s+(s|f|ser|serie|series|folge)\b/', $capture, $text);
	// bare series word + optional designator (number / roman / single letter), whole token
	$text = preg_replace_callback(
		'/\b(series|serie|ser|folge|reihe)\b(\s+([0-9]{1,3}|[ivxlcdm]{1,5}|[a-z])\b)?/',
		$capture, $text);

	$text = trim(preg_replace('/\s+/', ' ', $text));
	return array($text, implode('; ', $series));
}

// Number of leading word-prefixes used for the anchor key.
define('KEY_ANCHOR_WORDS', 3);

// Build the blocking keys for a raw name. Returns an associative array.
//
//   key_a : ordered prefixes (full)     — abbreviation-robust, order-sensitive
//   key_b : sorted prefixes (full)      — order-independent (word transpositions)
//   key_c : first KEY_ANCHOR_WORDS      — anchors names that gain trailing tails
//           prefixes (anchor)             (series, descriptive continuations)
//   key_d : initials of each token      — bridges acronyms to their expansions
//
// Any key may be '' (e.g. for junk like "-" or "p"); callers should skip empty
// keys when blocking so they don't form spurious mega-clusters.
function make_keys($raw)
{
	// Normalise, lift out series markers, then drop stop words / pure numbers.
	$normalized = normalise_text($raw);
	list($stripped, $series) = strip_series($normalized);
	$stripped = removeCommonWords($stripped);

	$tokens = array();
	foreach (explode(' ', $stripped) as $t) {
		$t = trim($t);
		if ($t !== '') {
			$tokens[] = $t;
		}
	}
	$tokens = key_tokens($tokens);

	$prefixes = array();
	$initials = array();
	foreach ($tokens as $t) {
		$prefixes[] = token_prefix($t);
		$initials[] = substr($t, 0, 1);
	}

	$sorted = $prefixes;
	sort($sorted);

	$key_a = implode(' ', $prefixes);
	$key_b = implode(' ', $sorted);
	$key_c = implode(' ', array_slice($prefixes, 0, KEY_ANCHOR_WORDS));
	$key_d = implode('', $initials);

	return array(
		'tokens' => implode(' ', $tokens),
		'series' => $series,
		'key_a'  => $key_a,
		'key_b'  => $key_b,
		'key_c'  => $key_c,
		'key_d'  => $key_d,
	);
}
