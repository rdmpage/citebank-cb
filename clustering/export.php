<?php

// Export the current clustering to TSV for manual review (e.g. a Google Sheet).
//
//   php export.php [--db=containers.db] [--out=clusters.tsv]
//
// One row per distinct raw name (identical raws collapsed with a count). Rows are
// grouped by cluster and the clusters are ordered alphabetically by each cluster's
// representative (longest member), so the whole sheet reads alphabetically while
// keeping every cluster contiguous.
//
// Columns:
//   band       0/1, flips each cluster — use for alternating row shading
//   flag       blank on the representative row, "+" on the cluster's other members
//   cluster_id cluster identifier
//   names      distinct raw names in the cluster
//   rows       total source rows in the cluster
//   n          source rows collapsed into this raw
//   issn       an ISSN seen for this raw (if any)
//   series     series marker lifted out during normalisation
//   key_a      ordered-prefix key
//   raw        original container-title
//   normalized normalised form

ini_set('memory_limit', '1G');

$opt = getopt('', array('db::', 'out::', 'min::', 'format::'));
$dbPath = isset($opt['db'])  ? $opt['db']  : __DIR__ . '/containers.db';
$format = isset($opt['format']) ? $opt['format'] : 'tsv';   // tsv | html
$min    = isset($opt['min']) ? (int)$opt['min'] : 1;        // min distinct names to include
$out    = isset($opt['out']) ? $opt['out'] : __DIR__ . '/clusters.' . ($format === 'html' ? 'html' : 'tsv');

if (!in_array($format, array('tsv', 'html'))) {
	fwrite(STDERR, "--format must be tsv or html\n");
	exit(1);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// distinct raw per cluster, with a representative ISSN and a collapse count
$stmt = $db->query("
	SELECT cluster_id, raw, normalized, series, key_a,
	       MAX(issn) AS issn, COUNT(*) AS n
	FROM containers
	GROUP BY cluster_id, raw");

$clusters = array();   // cluster_id => list of member rows
$meta = array();        // cluster_id => ['rep'=>normalized, 'rows'=>int]
foreach ($stmt as $r) {
	$cid = (int)$r['cluster_id'];
	$clusters[$cid][] = $r;
	if (!isset($meta[$cid])) $meta[$cid] = array('rep' => '', 'rows' => 0);
	$meta[$cid]['rows'] += (int)$r['n'];
	if (mb_strlen($r['normalized']) > mb_strlen($meta[$cid]['rep'])) {
		$meta[$cid]['rep'] = $r['normalized'];   // longest member = representative
	}
}

// order clusters alphabetically by representative; empty reps (junk) sort last
$order = array_keys($clusters);
usort($order, function ($a, $b) use ($meta, $clusters) {
	$ra = $meta[$a]['rep'];
	$rb = $meta[$b]['rep'];
	if (($ra === '') !== ($rb === '')) return $ra === '' ? 1 : -1;
	return strcmp($ra, $rb) ?: ($a - $b);
});

// sort each kept cluster's members (representative first) and collect the order
$render = array();   // list of [cid, members]
foreach ($order as $cid) {
	$members = $clusters[$cid];
	if (count($members) < $min) continue;
	$rep = $meta[$cid]['rep'];
	usort($members, function ($a, $b) use ($rep) {
		$ar = ($a['normalized'] === $rep) ? 0 : 1;
		$br = ($b['normalized'] === $rep) ? 0 : 1;
		if ($ar !== $br) return $ar - $br;
		if ($a['n'] !== $b['n']) return $b['n'] - $a['n'];
		return strcmp($a['normalized'], $b['normalized']);
	});
	$render[] = array($cid, $members);
}

$fh = fopen($out, 'w');
$written = ($format === 'html')
	? write_html($fh, $render, $meta, $min)
	: write_tsv($fh, $render, $meta);
fclose($fh);

fwrite(STDERR, sprintf("Wrote %s: %d clusters (min names=%d, of %d total).\n",
	$out, $written, $min, count($clusters)));

//----------------------------------------------------------------------------------------
function write_tsv($fh, $render, $meta)
{
	fwrite($fh, implode("\t", array(
		'band', 'flag', 'cluster_id', 'names', 'rows', 'n', 'issn', 'series', 'key_a', 'raw', 'normalized'
	)) . "\n");
	$band = 0;
	foreach ($render as list($cid, $members)) {
		$first = true;
		foreach ($members as $m) {
			fwrite($fh, implode("\t", array(
				$band, $first ? '' : '+', $cid, count($members), $meta[$cid]['rows'],
				$m['n'], $m['issn'], $m['series'], $m['key_a'],
				str_replace(array("\t", "\n", "\r"), ' ', $m['raw']), $m['normalized'],
			)) . "\n");
			$first = false;
		}
		$band ^= 1;
	}
	return count($render);
}

//----------------------------------------------------------------------------------------
function write_html($fh, $render, $meta, $min)
{
	$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

	$rows = 0;
	foreach ($render as list($cid, $members)) $rows += count($members);

	fwrite($fh, "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">\n");
	fwrite($fh, "<title>Journal-name clusters</title>\n");
	fwrite($fh, "<style>\n" . html_css() . "</style>\n</head><body>\n");

	fwrite($fh, "<header>\n");
	fwrite($fh, sprintf("<h1>Journal-name clusters</h1><p class=\"sub\">%s clusters &middot; %s names (min %d per cluster)</p>\n",
		number_format(count($render)), number_format($rows), $min));
	fwrite($fh, "<input id=\"q\" type=\"search\" placeholder=\"Filter\u{2026} (matches name, ISSN, key)\" autocomplete=\"off\">");
	fwrite($fh, "<span id=\"count\"></span>\n</header>\n");

	fwrite($fh, "<table><thead><tr>"
		. "<th>cluster</th><th>names</th><th>rows</th><th>n</th><th>ISSN</th>"
		. "<th>series</th><th>key_a</th><th>name</th></tr></thead>\n<tbody>\n");

	$band = 0;
	foreach ($render as list($cid, $members)) {
		$size = count($members);
		$first = true;
		foreach ($members as $m) {
			$cls = 'b' . $band . ($first ? ' rep' : ' extra');
			fwrite($fh, "<tr class=\"$cls\">"
				. '<td class="c">' . $cid . '</td>'
				. '<td class="num">' . ($first ? $size : '') . '</td>'
				. '<td class="num">' . ($first ? $meta[$cid]['rows'] : '') . '</td>'
				. '<td class="num">' . $m['n'] . '</td>'
				. '<td class="issn">' . $e($m['issn']) . '</td>'
				. '<td class="ser">' . $e($m['series']) . '</td>'
				. '<td class="key">' . $e($m['key_a']) . '</td>'
				. '<td class="raw">' . ($first ? '' : '<span class="plus">+</span> ') . $e($m['raw']) . '</td>'
				. "</tr>\n");
			$first = false;
		}
		$band ^= 1;
	}

	fwrite($fh, "</tbody></table>\n");
	fwrite($fh, "<script>\n" . html_js() . "</script>\n</body></html>\n");
	return count($render);
}

//----------------------------------------------------------------------------------------
function html_css()
{
	return <<<CSS
:root { --b0:#ffffff; --b1:#eef3f8; --line:#d8dee6; }
* { box-sizing: border-box; }
body { margin:0; font:13px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:#1b1f24; }
header { position:sticky; top:0; background:#fff; border-bottom:1px solid var(--line);
         padding:10px 16px; z-index:3; display:flex; align-items:baseline; gap:14px; flex-wrap:wrap; }
h1 { font-size:16px; margin:0; }
.sub { color:#6b7480; margin:0; }
#q { flex:1; min-width:220px; padding:7px 10px; font-size:13px; border:1px solid var(--line); border-radius:6px; }
#count { color:#6b7480; }
table { border-collapse:collapse; width:100%; }
thead th { position:sticky; top:49px; background:#f6f8fa; text-align:left; font-weight:600;
           padding:6px 10px; border-bottom:1px solid var(--line); z-index:2; }
td { padding:3px 10px; border-bottom:1px solid #f0f2f5; vertical-align:top; }
tr.b0 td { background:var(--b0); }
tr.b1 td { background:var(--b1); }
tr.rep td { border-top:2px solid var(--line); }
tr.rep .raw { font-weight:600; }
tr.extra .raw { color:#3a4350; }
.plus { color:#c0392b; font-weight:700; }
.num,.c { text-align:right; color:#6b7480; white-space:nowrap; }
.issn { font-variant-numeric:tabular-nums; color:#0a66c2; white-space:nowrap; }
.ser { color:#8a6d00; white-space:nowrap; }
.key { color:#7a8290; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:11px; white-space:nowrap; }
.raw { width:55%; }
tr.hide { display:none; }
CSS;
}

//----------------------------------------------------------------------------------------
function html_js()
{
	return <<<JS
const q = document.getElementById('q');
const count = document.getElementById('count');
const rows = Array.from(document.querySelectorAll('tbody tr'));
const hay = rows.map(r => r.textContent.toLowerCase());   // cache once
let t;
function apply() {
  const term = q.value.trim().toLowerCase();
  let shown = 0;
  for (let i = 0; i < rows.length; i++) {
    const ok = term === '' || hay[i].indexOf(term) !== -1;
    rows[i].classList.toggle('hide', !ok);
    if (ok) shown++;
  }
  count.textContent = term ? shown.toLocaleString() + ' rows match' : '';
}
q.addEventListener('input', () => { clearTimeout(t); t = setTimeout(apply, 150); });
JS;
}
