<?php

// Build a hand-labelling set from the candidate pairs in pairs.jsonl.
//
//   php derive_features.php --all          # produces pairs.jsonl
//   php make_labelling_set.php             # produces labels.tsv + labels.html
//
// Why these pairs
// ---------------
// There is no human-labelled data anywhere in the database: every stored
// decision is heuristic-v1's own output, so training on them reproduces the
// heuristic and learns nothing. This selects the pairs where a human judgement
// is actually worth something.
//
// Two strata, both concerning titles that differ only by a numeral or a series
// marker ("(2e partie)", "III", "Suite"). A numeral there means one of two
// completely different things, and which one decides the label:
//
//   series-guard  the volume or first page ALSO differs, so these look like
//                 genuinely different instalments of a serial work. The
//                 heuristic rejects all 357, and is probably right -- but it is
//                 right only because is_match() vetoes on any disagreeing
//                 field, and a learned model has no veto. On the continuous
//                 features these sit at 0.74-0.89 against 0.94-1.00 for known
//                 matches: separable, but by a narrow margin that nothing in a
//                 training set currently pins down. Cheap to label, and the
//                 stratum you would most regret omitting.
//
//   series-noise  volume and first page agree exactly, so the numeral is
//                 citation style rather than a part number -- the same article
//                 cited once as "...de la Chine" and once as "...de la Chine
//                 (2e partie)", both vol 6, pp 303-356. The heuristic merged
//                 6,348 of these and rejected 1,216; the rejected ones are the
//                 interesting half, and are probably false negatives.
//
// The label you supply is the ground truth the whole exercise lacks. The
// heuristic's own decision is carried through for reference only -- do not
// treat it as a starting point to agree with.

ini_set('memory_limit', '-1');

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/compare.php');
require_once(dirname(__FILE__) . '/label_page.php');

$opt = getopt('', array('in::', 'out::', 'max::'));

$in  = isset($opt['in'])  ? $opt['in']  : dirname(__FILE__) . '/pairs.jsonl';
$out = isset($opt['out']) ? $opt['out'] : dirname(__FILE__) . '/labels';
$max = isset($opt['max']) ? (int)$opt['max'] : 0;   // 0 = no cap

if (!file_exists($in))
{
	fwrite(STDERR, "Not found: $in\nRun: php derive_features.php --all\n");
	exit(1);
}

//----------------------------------------------------------------------------------------
// display_citation() in derive_features.php renders
// "Family (Year) Title Container vol X pp Y"; recover the title-ish middle.
function display_title($text)
{
	$t = preg_replace('/^\S+.*?\(\d{4}\)\s*/u', '', $text);
	$t = preg_replace('/\s+vol\s.*$/u', '', $t);
	$t = preg_replace('/\s+pp\s.*$/u', '', $t);

	return normalise_text($t);
}

//----------------------------------------------------------------------------------------
// Strip series markers and standalone numerals, so that two titles differing
// only in their part number collapse to the same string.
function strip_series($s)
{
	$series = '(?:suite|suites|partie|parties|part|parts|teil|deel|no|nr|numero|continued|concluded|fortsetzung|schluss|conclusion)';
	$roman  = '(?:x{0,3})(?:ix|iv|v?i{0,3})';

	$s = preg_replace('/\b' . $series . '\b/u', ' ', $s);
	$s = preg_replace('/\b\d+\s*(?:e|er|eme|st|nd|rd|th)?\b/u', ' ', $s);
	$s = preg_replace('/\b' . $roman . '\b/u', ' ', $s);

	return trim(preg_replace('/\s+/u', ' ', $s));
}

//----------------------------------------------------------------------------------------
$names = json_decode(file_get_contents($in . '.names.json'), true);
$ix    = array_flip($names);

function score($vector, $ix, $name)
{
	return array($vector[$ix[$name . '_score']], $vector[$ix[$name . '_present']]);
}

$rows = array();
$seen = 0;

$fh = fopen($in, 'r');

while (($line = fgets($fh)) !== false)
{
	$r = json_decode($line, true);
	if (!$r)
	{
		continue;
	}
	$seen++;

	$ta = display_title($r['a_text']);
	$tb = display_title($r['b_text']);

	if ($ta === '' || $tb === '' || $ta === $tb)
	{
		continue;
	}

	$sa = strip_series($ta);
	$sb = strip_series($tb);

	// Titles must be identical once the numerals are removed, and there must be
	// enough left over for that to mean anything.
	if ($sa !== $sb || mb_strlen($sa) <= 8)
	{
		continue;
	}

	list($vol, $vol_present)   = score($r['v2'], $ix, 'volume');
	list($page, $page_present) = score($r['v2'], $ix, 'page_first');

	if (!$vol_present || !$page_present)
	{
		continue;   // cannot tell the two cases apart without both coordinates
	}

	if ($vol < 1 || $page < 1)
	{
		$stratum = 'series-guard';
		$question = 'Different instalments of a series, or the same article?';
	}
	elseif ($r['decision'] === 'no-match')
	{
		$stratum = 'series-noise';
		$question = 'Same article cited two ways, or genuinely different?';
	}
	else
	{
		continue;   // coordinates agree and the heuristic already merged it
	}

	$rows[] = array(
		'stratum'   => $stratum,
		'question'  => $question,
		'a'         => $r['a'],
		'b'         => $r['b'],
		'a_text'    => $r['a_text'],
		'b_text'    => $r['b_text'],
		'heuristic' => $r['decision'],
	);
}

fclose($fh);

// Collapse pairs that render identically.
//
// The same work is often present three or four times over, so several distinct
// document pairs produce the same two citations on screen. Asking for the same
// judgement four times wastes the only expensive resource here. One
// representative is kept, carrying the ids of every pair it stands for, so a
// single label propagates to all of them.
$groups = array();

foreach ($rows as $r)
{
	$key = ($r['a_text'] < $r['b_text'])
		? $r['a_text'] . "\0" . $r['b_text']
		: $r['b_text'] . "\0" . $r['a_text'];

	if (!isset($groups[$key]))
	{
		$r['pairs'] = array();
		$groups[$key] = $r;
	}

	$groups[$key]['pairs'][] = array($r['a'], $r['b']);
}

$before = count($rows);
$rows   = array_values($groups);

fwrite(STDERR, "collapsed     : " . number_format($before) . " pairs -> "
	. number_format(count($rows)) . " distinct presentations\n");

// series-guard first: it is the smaller stratum and the one that matters most.
usort($rows, function ($x, $y)
{
	if ($x['stratum'] === $y['stratum'])
	{
		return strcmp($x['a'], $y['a']);
	}
	return ($x['stratum'] === 'series-guard') ? -1 : 1;
});

if ($max > 0 && count($rows) > $max)
{
	$rows = array_slice($rows, 0, $max);
}

$counts = array();
foreach ($rows as $r)
{
	$counts[$r['stratum']] = (isset($counts[$r['stratum']]) ? $counts[$r['stratum']] : 0) + 1;
}

//----------------------------------------------------------------------------------------
// TSV, for a spreadsheet. The label column is deliberately empty.
$tsv = fopen($out . '.tsv', 'w');

fputs($tsv, join("\t", array('n', 'stratum', 'heuristic', 'covers', 'label', 'note', 'a_text', 'b_text', 'a_id', 'b_id')) . "\n");

$n = 0;
foreach ($rows as $r)
{
	$n++;
	fputs($tsv, join("\t", array(
		$n,
		$r['stratum'],
		$r['heuristic'],
		count($r['pairs']),       // how many raw pairs this row stands for
		'',                       // label: same | different | unsure
		'',                       // note
		str_replace("\t", ' ', $r['a_text']),
		str_replace("\t", ' ', $r['b_text']),
		$r['a'],
		$r['b'],
	)) . "\n");
}

fclose($tsv);

//----------------------------------------------------------------------------------------
// Self-contained HTML, because a TSV of 1,500 long citations is not something
// anyone will actually read. Judgements are kept in localStorage and exported
// as the same TSV shape.
$html = render_label_page($rows, array(
	'title'   => 'Label candidate pairs',
	'intro'   => 'Is A the same work as B? Keys: <b>s</b> same &middot; <b>d</b> different &middot; '
	           . '<b>u</b> unsure &middot; <b>j</b>/<b>k</b> move. Differences are highlighted. '
	           . 'Answers are kept in this browser; Export writes the TSV.',
	'storage' => 'citebank-labels-v2',
	'notes'   => array(
		'series-guard' => array(
			'Volume or first page ALSO differs — these look like different instalments of a serial work.',
			'The heuristic rejects all of them, probably correctly, but only because it vetoes on any '
			. 'disagreeing field. A learned model has no veto, and nothing else would pin this boundary. '
			. 'Expect most to be <em>different</em>; the ones that are not are the interesting ones.'),
		'series-noise' => array(
			'Volume and first page agree exactly — the numeral is citation style, not a part number.',
			'The heuristic rejected these while merging 6,348 similar pairs, so they are likely false '
			. 'negatives. Expect most to be <em>same</em>.'),
	),
));

file_put_contents($out . '.html', $html);

//----------------------------------------------------------------------------------------
fwrite(STDERR, "pairs scanned : " . number_format($seen) . "\n");
fwrite(STDERR, "selected      : " . number_format(count($rows)) . "\n");
foreach ($counts as $k => $v)
{
	fwrite(STDERR, "  $k: " . number_format($v) . "\n");
}
fwrite(STDERR, "written       : $out.tsv, $out.html\n");

?>
