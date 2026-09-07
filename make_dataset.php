<?php

// Build a portable train/test dataset of citation pairs.
//
//   php make_dataset.php [--labels=labels-done.tsv] [--doi] [--out=dataset]
//
//     --labels=FILE  human labels, as exported from labels.html
//     --doi          also include high-confidence positives derived from DOI
//                    agreement (see the warning below)
//     --balance      downsample DOI positives to match the negatives
//     --limit=N      cap the number of DOI-derived pairs
//     --out=PREFIX   output prefix (default: dataset)
//
// Output
// ------
//   PREFIX.train.jsonl   one pair per line: [cslA, cslB, true|false]
//   PREFIX.test.jsonl    same shape
//   PREFIX.tsv           feature vector + label, for going straight to a model
//   PREFIX.README.md     what the files are and how they were made
//
// The JSONL shape is the one citation_pair_to_feature_vector() already expects:
// a two-element array of CSL-JSON objects, with a third element flagging whether
// they match. So a line can be fed to it directly, and anyone else can ignore
// our features entirely and compute their own from the CSL.
//
// Label provenance
// ----------------
// Only two sources are allowed, and neither is the clustering heuristic. Every
// decision stored in the database is heuristic-v1's own output, and is_match()
// is a deterministic function of the same feature vector a model would see, so
// training on those labels reproduces the heuristic exactly and learns nothing.
//
//   human   read from the labelling file. The real ground truth.
//
//   doi     two records carrying an identical DOI are the same work by external
//           fact rather than by our own algorithm's opinion, which makes them
//           usable. But DOIs in this corpus are sometimes simply wrong: of the
//           18,630 DOI-identical pairs, 2,022 disagree on all five of title,
//           container, volume, page and year, and spot-checking those finds
//           things like Druce (1886) "Fam. Arctiidae" -- a moth -- sharing a DOI
//           with Pickard-Cambridge (1889) "Arachnida. Araneida", a spider. So
//           DOI agreement is only trusted here when at most two other fields
//           disagree. That keeps the useful hard positives (same work, metadata
//           disagrees) and drops the tail that is mostly bad identifiers.
//
// Leakage
// -------
// Two guards, both of which matter more than they look:
//
//   * citebank.cluster and citebank.clustering are stripped from every record.
//     The cluster id IS the answer -- a model given it would score perfectly and
//     have learned nothing.
//
//   * the train/test split is by connected component of the pair graph, not by
//     pair. Splitting pairwise puts document X in training and a different pair
//     containing the same X in test, and near-duplicate records leak the answer
//     across the boundary.

ini_set('memory_limit', '-1');

error_reporting(E_ALL);

require_once(dirname(__FILE__) . '/couchsimple.php');
require_once(dirname(__FILE__) . '/csl_features.php');

$opt = getopt('', array('labels::', 'doi', 'balance', 'limit::', 'out::', 'pairs::', 'test-fraction::'));

$labels_file = isset($opt['labels']) ? $opt['labels'] : dirname(__FILE__) . '/labels-done.tsv';
$pairs_file  = isset($opt['pairs'])  ? $opt['pairs']  : dirname(__FILE__) . '/pairs.jsonl';
$out         = isset($opt['out'])    ? $opt['out']    : dirname(__FILE__) . '/dataset';
$limit       = isset($opt['limit'])  ? (int)$opt['limit'] : 0;
$test_frac   = isset($opt['test-fraction']) ? (float)$opt['test-fraction'] : 0.25;
$use_doi     = isset($opt['doi']);

$dbn = $config['couchdb_options']['database'];

// How far DOI evidence is trusted, in each direction. Both tails are
// contaminated and both are excluded:
//
//   positives  identical DOIs, but 2,022 such pairs disagree on all five of
//              title, container, volume, page and year, and those are wrong
//              DOIs rather than hard positives -- a moth sharing a DOI with a
//              spider. Trusted only when at most 2 fields disagree.
//
//   negatives  different DOIs, but 2,078 such pairs agree on all five, and
//              those are one article registered twice rather than two
//              articles -- Warren (1905), same title, journal and volume,
//              two DOIs. Trusted only when at most 3 fields agree.
define('DOI_MAX_DISAGREEING_FIELDS', 2);
define('DOI_MAX_AGREEING_FIELDS', 3);

//----------------------------------------------------------------------------------------
$examples = array();   // key "a|b" => [a, b, label(bool), provenance]

function add_example(&$examples, $a, $b, $label, $provenance)
{
	// One row per unordered pair; a human label always wins over a derived one.
	$key = ($a < $b) ? "$a|$b" : "$b|$a";

	if (isset($examples[$key]) && $examples[$key]['provenance'] === 'human')
	{
		return;
	}

	$examples[$key] = array(
		'a' => $a, 'b' => $b, 'label' => $label, 'provenance' => $provenance
	);
}

//----------------------------------------------------------------------------------------
// 1. Human labels.
$n_human = 0;

if (file_exists($labels_file))
{
	$fh = fopen($labels_file, 'r');
	$head = fgetcsv($fh, 0, "\t");
	$col = array_flip($head);

	while (($row = fgetcsv($fh, 0, "\t")) !== false)
	{
		if (!isset($row[$col['label']]))
		{
			continue;
		}

		$label = trim($row[$col['label']]);

		// "unsure" is deliberately not a class: an example nobody can call is
		// not one a model should be asked to.
		if ($label !== 'same' && $label !== 'different')
		{
			continue;
		}

		add_example($examples, $row[$col['a_id']], $row[$col['b_id']],
			($label === 'same'), 'human');
		$n_human++;
	}
	fclose($fh);

	fwrite(STDERR, "human labels    : " . number_format($n_human) . " from " . basename($labels_file) . "\n");
}
else
{
	fwrite(STDERR, "human labels    : none ($labels_file not found)\n");
}

//----------------------------------------------------------------------------------------
// 2. DOI-derived positives.
$n_doi = 0;

if ($use_doi)
{
	if (!file_exists($pairs_file))
	{
		fwrite(STDERR, "Cannot derive DOI labels: $pairs_file not found\n");
		exit(1);
	}

	$names = json_decode(file_get_contents($pairs_file . '.names.json'), true);
	$ix    = array_flip($names);

	$fh = fopen($pairs_file, 'r');

	while (($line = fgets($fh)) !== false)
	{
		$r = json_decode($line, true);
		if (!$r)
		{
			continue;
		}

		$v = $r['v2'];

		// Both sides must actually carry a DOI.
		//
		// Deliberately not restricted to the doi-exact tier: that tier blocks on
		// identical DOIs, so every pair in it agrees by construction and there
		// would never be a single negative. The pairs whose DOIs differ arrive
		// through the hash and title-search tiers.
		if (!$v[$ix['doi_present']])
		{
			continue;
		}

		$same_doi = ($v[$ix['doi_score']] >= 1);

		$agreeing = 0;
		$disagreeing = 0;
		foreach (array('title_trigram', 'container_trigram', 'volume', 'page_first', 'year') as $f)
		{
			if (!$v[$ix[$f . '_present']])
			{
				continue;
			}
			if ($v[$ix[$f . '_score']] >= 0.95)
			{
				$agreeing++;
			}
			if ($v[$ix[$f . '_score']] < 1)
			{
				$disagreeing++;
			}
		}

		if ($same_doi)
		{
			if ($disagreeing > DOI_MAX_DISAGREEING_FIELDS)
			{
				continue;   // probably a wrong DOI rather than a hard positive
			}
			add_example($examples, $r['a'], $r['b'], true, 'doi');
		}
		else
		{
			if ($agreeing > DOI_MAX_AGREEING_FIELDS)
			{
				continue;   // probably one article registered twice
			}
			add_example($examples, $r['a'], $r['b'], false, 'doi');
		}

		$n_doi++;

		if ($limit > 0 && $n_doi >= $limit)
		{
			break;
		}
	}
	fclose($fh);

	fwrite(STDERR, "doi positives   : " . number_format($n_doi) . "\n");
}

if (count($examples) === 0)
{
	fwrite(STDERR, "\nNothing to build. Label some pairs in labels.html and export, or pass --doi.\n");
	exit(1);
}

//----------------------------------------------------------------------------------------
// Optionally downsample the majority class.
//
// DOI evidence is lopsided: identical DOIs are everywhere, whereas a different
// DOI only counts as a negative when enough else disagrees too, which is rare.
// The result is around 97% positive, and a model trained on that can score 97%
// by answering "same" to everything. Human labels never get downsampled --
// they are the scarce and expensive half.
if (isset($opt['balance']))
{
	$pos = array(); $neg = array(); $keep = array();

	foreach ($examples as $k => $e)
	{
		if ($e['provenance'] === 'human') { $keep[$k] = $e; }
		elseif ($e['label'])              { $pos[$k]  = $e; }
		else                              { $neg[$k]  = $e; }
	}

	$target = count($neg);

	if (count($pos) > $target)
	{
		// Deterministic subsample, so the dataset is reproducible.
		$keys = array_keys($pos);
		usort($keys, function ($x, $y) { return strcmp(md5($x), md5($y)); });
		$keys = array_slice($keys, 0, $target);

		$trimmed = array();
		foreach ($keys as $k) { $trimmed[$k] = $pos[$k]; }
		$pos = $trimmed;
	}

	$before = count($examples);
	$examples = $keep + $pos + $neg;

	fwrite(STDERR, "balanced        : " . number_format($before) . " -> "
		. number_format(count($examples)) . " examples\n");
}

//----------------------------------------------------------------------------------------
// 3. Fetch the documents.
$ids = array();
foreach ($examples as $e)
{
	$ids[$e['a']] = 1;
	$ids[$e['b']] = 1;
}
$ids = array_keys($ids);

fwrite(STDERR, "documents       : " . number_format(count($ids)) . "\n");

$docs = array();

foreach (array_chunk($ids, 500) as $chunk)
{
	$resp = $couch->send("POST", "/$dbn/_all_docs?include_docs=true",
		couch_encode(array('keys' => $chunk)));

	$o = json_decode($resp);

	if (!$o || !isset($o->rows))
	{
		continue;
	}

	foreach ($o->rows as $row)
	{
		if (!isset($row->doc) || $row->doc === null)
		{
			continue;
		}

		$d = $row->doc;

		// Strip anything that would leak the answer or bloat the file.
		unset($d->_rev);
		unset($d->reference);          // CrossRef reference lists, often huge
		if (isset($d->citebank))
		{
			unset($d->citebank->cluster);
			unset($d->citebank->clustering);
		}

		$docs[$d->_id] = $d;
	}
}

fwrite(STDERR, "fetched         : " . number_format(count($docs)) . "\n");

//----------------------------------------------------------------------------------------
// 4. Split by connected component, so no document appears on both sides.
$parent = array();

function find(&$parent, $x)
{
	while ($parent[$x] !== $x)
	{
		$parent[$x] = $parent[$parent[$x]];
		$x = $parent[$x];
	}
	return $x;
}

foreach ($examples as $e)
{
	foreach (array($e['a'], $e['b']) as $id)
	{
		if (!isset($parent[$id]))
		{
			$parent[$id] = $id;
		}
	}
	$ra = find($parent, $e['a']);
	$rb = find($parent, $e['b']);
	if ($ra !== $rb)
	{
		$parent[$ra] = $rb;
	}
}

$components = array();
foreach ($parent as $id => $_)
{
	$components[find($parent, $id)][] = $id;
}

// Deterministic assignment: hash the component root, so re-running gives the
// same split and a component never straddles the boundary.
$test_components = array();
foreach ($components as $root => $_)
{
	$h = hexdec(substr(md5($root), 0, 8)) / 0xffffffff;
	if ($h < $test_frac)
	{
		$test_components[$root] = true;
	}
}

fwrite(STDERR, "components      : " . number_format(count($components))
	. " (" . number_format(count($test_components)) . " to test)\n");

//----------------------------------------------------------------------------------------
// 5. Write.
$train = fopen($out . '.train.jsonl', 'w');
$test  = fopen($out . '.test.jsonl', 'w');
$tsv   = fopen($out . '.tsv', 'w');

$feature_names = continuous_feature_names();

fputs($tsv, join("\t", array_merge(
	array('split', 'label', 'provenance', 'a_id', 'b_id'), $feature_names)) . "\n");

$stats = array('train' => array(0, 0), 'test' => array(0, 0));
$skipped = 0;

foreach ($examples as $e)
{
	if (!isset($docs[$e['a']]) || !isset($docs[$e['b']]))
	{
		$skipped++;
		continue;
	}

	$a = $docs[$e['a']];
	$b = $docs[$e['b']];

	$split = isset($test_components[find($parent, $e['a'])]) ? 'test' : 'train';

	$line = couch_encode(array($a, $b, $e['label'])) . "\n";
	fputs(($split === 'test') ? $test : $train, $line);

	$stats[$split][$e['label'] ? 0 : 1]++;

	$v = citation_pair_to_feature_vector_continuous(array($a, $b));
	fputs($tsv, join("\t", array_merge(
		array($split, $e['label'] ? 1 : 0, $e['provenance'], $e['a'], $e['b']),
		$v->vector)) . "\n");
}

fclose($train);
fclose($test);
fclose($tsv);

//----------------------------------------------------------------------------------------
$readme = "# CiteBank citation-pair dataset\n\n"
	. "Pairs of bibliographic records, labelled as the same work or not.\n\n"
	. "Generated by `make_dataset.php` on " . date('Y-m-d') . ".\n\n"
	. "## Files\n\n"
	. "| file | contents |\n|---|---|\n"
	. "| `" . basename($out) . ".train.jsonl` | one pair per line: `[cslA, cslB, true\\|false]` |\n"
	. "| `" . basename($out) . ".test.jsonl` | same shape |\n"
	. "| `" . basename($out) . ".tsv` | feature vector + label, if you want to skip the CSL |\n\n"
	. "Each JSONL line is a two-element array of CSL-JSON objects plus a boolean.\n"
	. "That is the shape `citation_pair_to_feature_vector()` in `csl_features.php`\n"
	. "already accepts, so a line feeds straight into it -- or ignore our features\n"
	. "entirely and compute your own from the CSL.\n\n"
	. "## Counts\n\n"
	. "| split | same | different |\n|---|---|---|\n"
	. "| train | " . number_format($stats['train'][0]) . " | " . number_format($stats['train'][1]) . " |\n"
	. "| test | " . number_format($stats['test'][0]) . " | " . number_format($stats['test'][1]) . " |\n\n"
	. "Label provenance: " . number_format($n_human) . " human, "
	. number_format($n_doi) . " derived from DOI agreement.\n\n"
	. "## Two things to know before using it\n\n"
	. "**The split is by connected component, not by pair.** Records in this\n"
	. "corpus are frequently near-identical, so splitting pairwise would put one\n"
	. "copy of a work in training and another in test and leak the answer. Whole\n"
	. "components go to one side or the other, which is why the split is not an\n"
	. "exact " . round((1 - $test_frac) * 100) . "/" . round($test_frac * 100) . ".\n\n"
	. "**`citebank.cluster` and `citebank.clustering` are stripped.** The cluster\n"
	. "id is the answer; a model given it scores perfectly and has learned nothing.\n\n"
	. "## DOI-derived labels, and what they are not\n\n"
	. "Two records with the same DOI are the same work; two with different DOIs\n"
	. "are different works. Both hold as external facts rather than as our own\n"
	. "algorithm's opinion, which is what makes them usable at all. But both tails\n"
	. "are contaminated, so both are cut:\n\n"
	. "* identical DOIs are trusted only where at most " . DOI_MAX_DISAGREEING_FIELDS
	. " of title, container,\n  volume, page and year disagree. 2,022 DOI-identical pairs disagree on all\n"
	. "  five, and those are wrong DOIs, not hard positives -- one has a moth\n"
	. "  sharing a DOI with a spider.\n"
	. "* different DOIs are trusted only where at most " . DOI_MAX_AGREEING_FIELDS
	. " of the five agree.\n  2,078 pairs with different DOIs agree on all five, and those are one\n"
	. "  article registered twice.\n\n"
	. "The consequence is worth stating plainly: **DOI evidence is reliable only\n"
	. "in the easy regime.** It supplies bulk and a sanity baseline, but it cannot\n"
	. "label the boundary, because at the boundary is exactly where it is wrong.\n"
	. "Only the human labels cover that, and there is no way around collecting\n"
	. "them.\n";

file_put_contents($out . '.README.md', $readme);

fwrite(STDERR, "\n");
fwrite(STDERR, "train           : " . number_format($stats['train'][0]) . " same, "
	. number_format($stats['train'][1]) . " different\n");
fwrite(STDERR, "test            : " . number_format($stats['test'][0]) . " same, "
	. number_format($stats['test'][1]) . " different\n");
if ($skipped)
{
	fwrite(STDERR, "skipped         : " . number_format($skipped) . " (document missing)\n");
}
fwrite(STDERR, "written         : $out.{train,test}.jsonl, $out.tsv, $out.README.md\n");

?>
