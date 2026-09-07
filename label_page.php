<?php

// Render a self-contained page for judging pairs of citations by hand.
//
// Shared by make_labelling_set.php (label unlabelled pairs) and
// make_dataset.php --review (check labels that were derived automatically).
// A TSV of long citations is not something anyone will actually read; this
// highlights the words that differ so a pair takes a couple of seconds.
//
// render_label_page($rows, $opts) where each row is:
//
//   stratum    grouping key, used for the section headings
//   question   the question for that stratum, shown once at the top of it
//   a_text     rendered citation A
//   b_text     rendered citation B
//   pairs      [[a_id, b_id], ...] every underlying pair this row stands for
//   derived    optional: a label already assigned, e.g. "same". When present
//              the page runs in review mode -- see $opts['review'].
//
// $opts:
//   title      page title
//   intro      one-line instruction under the title
//   notes      stratum => [line1, line2] explanation shown above each section
//   storage    localStorage key. Change it whenever the row set changes shape.
//   review     when true the derived label is hidden until an answer is given,
//              then revealed with agreement or disagreement marked. Hiding it
//              first matters: showing someone an existing label before they
//              judge mostly measures their willingness to agree with it.

function render_label_page($rows, $opts)
{
	$payload  = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$notes    = json_encode(isset($opts['notes']) ? $opts['notes'] : new stdclass,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	$title    = htmlspecialchars($opts['title'], ENT_QUOTES, 'UTF-8');
	$intro    = isset($opts['intro']) ? $opts['intro'] : '';
	$storage  = isset($opts['storage']) ? $opts['storage'] : 'citebank-labels';
	$review   = !empty($opts['review']) ? 'true' : 'false';

	return <<<HTML
<html>
<head>
<meta charset="UTF-8">
<title>$title</title>
<style>
 body { font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
        margin: 0; padding: 0 0 6em; color: #222; }
 header { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd;
          padding: 0.8em 1.2em; z-index: 10; }
 h1 { font-size: 1.15em; margin: 0 0 0.2em; }
 .sub { color: #666; font-size: 0.85em; }
 .wrap { max-width: 60em; margin: 0 auto; padding: 0 1.2em; }
 .stratum-head { margin: 2em 0 0.6em; padding: 0.7em 0.9em; background: #f4f6f8;
                 border-left: 3px solid #7a9cc6; }
 .stratum-head h2 { font-size: 1em; margin: 0 0 0.3em; }
 .stratum-head p { margin: 0; font-size: 0.87em; color: #444; }
 .pair { border: 1px solid #e2e2e2; border-radius: 4px; margin: 0.7em 0; padding: 0.7em 0.9em; }
 .pair.done { background: #fafcfa; border-color: #cfe0cf; }
 .pair.clash { background: #fff8f6; border-color: #e6bdb2; }
 .cite { padding: 0.15em 0; }
 .cite b { background: #ffe9a8; font-weight: normal; padding: 0 1px; }
 .meta { font-size: 0.78em; color: #888; margin-top: 0.35em; }
 .verdict { font-size: 0.8em; margin-top: 0.35em; }
 .verdict.agree { color: #2d6a2d; }
 .verdict.disagree { color: #9c3232; font-weight: bold; }
 .btns { margin-top: 0.5em; }
 button { font: inherit; font-size: 0.85em; padding: 0.2em 0.7em; margin-right: 0.35em;
          border: 1px solid #bbb; background: #fff; border-radius: 3px; cursor: pointer; }
 button.on { background: #2d6a2d; color: #fff; border-color: #2d6a2d; }
 button.on.diff { background: #9c3232; border-color: #9c3232; }
 button.on.unsure { background: #7a6a2d; border-color: #7a6a2d; }
 #bar { position: fixed; bottom: 0; left: 0; right: 0; background: #fff;
        border-top: 1px solid #ddd; padding: 0.6em 1.2em; font-size: 0.85em; }
</style>
</head>
<body>
<header>
 <div class="wrap">
  <h1>$title</h1>
  <div class="sub">$intro</div>
 </div>
</header>
<div class="wrap" id="list"></div>
<div id="bar"><span id="progress"></span>
  <button onclick="exportTsv()">Export TSV</button>
  <button onclick="if(confirm('Clear all your answers?')){localStorage.removeItem(KEY);location.reload();}">Clear</button>
</div>
<script>
const ROWS = $payload;
const NOTE = $notes;
const REVIEW = $review;
const KEY = '$storage';
let labels = {};
try { labels = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { labels = {}; }
let cur = 0;

// Keyed by document ids rather than row position, so regenerating the file
// cannot silently reattach an answer to a different pair.
function idOf(r){ return r.pairs[0][0] + '|' + r.pairs[0][1]; }

function esc(s){ return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Word-level diff, so the eye goes straight to what actually differs.
function markup(a, b) {
  const A = a.split(/(\s+)/), B = b.split(/(\s+)/);
  const bag = new Map();
  B.forEach(w => { const k = w.toLowerCase(); bag.set(k, (bag.get(k)||0)+1); });
  return A.map(w => {
    const k = w.toLowerCase();
    if (/^\s+\$/.test(w)) return w;
    if (bag.get(k)) { bag.set(k, bag.get(k)-1); return esc(w); }
    return '<b>' + esc(w) + '</b>';
  }).join('');
}

function verdictHtml(r, v) {
  if (!REVIEW || !r.derived || !v || v === 'unsure') return '';
  const agree = (v === r.derived);
  return '<div class="verdict ' + (agree ? 'agree' : 'disagree') + '">'
       + (agree ? '✓ agrees with the derived label (' + esc(r.derived) + ')'
                : '✗ derived label says ' + esc(r.derived) + ' — disagreement')
       + '</div>';
}

function render() {
  const list = document.getElementById('list');
  let html = '', seen = null;
  ROWS.forEach((r, i) => {
    if (r.stratum !== seen) {
      seen = r.stratum;
      const n = ROWS.filter(x => x.stratum === seen).length;
      const note = NOTE[seen] || ['', ''];
      html += '<div class="stratum-head"><h2>' + esc(seen) + ' &mdash; ' + n + ' pairs</h2>'
            + '<p><b>' + esc(r.question) + '</b><br>' + note[0] + '<br>' + note[1] + '</p></div>';
    }
    const v = labels[idOf(r)];
    const clash = REVIEW && r.derived && v && v !== 'unsure' && v !== r.derived;
    html += '<div class="pair' + (v ? ' done' : '') + (clash ? ' clash' : '') + '" id="p' + i + '">'
      + '<div class="cite">A. ' + markup(r.a_text, r.b_text) + '</div>'
      + '<div class="cite">B. ' + markup(r.b_text, r.a_text) + '</div>'
      + '<div class="meta">#' + (i+1) + (r.note ? ' &middot; ' + esc(r.note) : '')
      + (r.pairs.length > 1 ? ' &middot; stands for ' + r.pairs.length + ' identical pairs' : '') + '</div>'
      + '<div class="btns">'
      + '<button class="' + (v==='same'?'on':'') + '" onclick="setL(' + i + ',\\'same\\')">same</button>'
      + '<button class="diff ' + (v==='different'?'on':'') + '" onclick="setL(' + i + ',\\'different\\')">different</button>'
      + '<button class="unsure ' + (v==='unsure'?'on':'') + '" onclick="setL(' + i + ',\\'unsure\\')">unsure</button>'
      + '</div>'
      + '<div id="v' + i + '">' + verdictHtml(r, v) + '</div>'
      + '</div>';
  });
  list.innerHTML = html;
  progress();
}

function progress() {
  const done = Object.keys(labels).length;
  let extra = '';
  if (REVIEW) {
    let clashes = 0;
    ROWS.forEach(r => { const v = labels[idOf(r)];
      if (r.derived && v && v !== 'unsure' && v !== r.derived) clashes++; });
    extra = ' — ' + clashes + ' disagree with the derived label';
  }
  document.getElementById('progress').textContent =
    done + ' of ' + ROWS.length + ' answered' + extra + ' — ';
}

function setL(i, v) {
  const r = ROWS[i], k = idOf(r);
  if (labels[k] === v) { delete labels[k]; } else { labels[k] = v; }
  localStorage.setItem(KEY, JSON.stringify(labels));
  const el = document.getElementById('p' + i);
  el.classList.toggle('done', !!labels[k]);
  el.classList.toggle('clash', REVIEW && r.derived && labels[k]
                      && labels[k] !== 'unsure' && labels[k] !== r.derived);
  el.querySelectorAll('button').forEach(b => b.classList.remove('on'));
  if (labels[k]) {
    const idx = {same:0, different:1, unsure:2}[labels[k]];
    el.querySelectorAll('button')[idx].classList.add('on');
  }
  document.getElementById('v' + i).innerHTML = verdictHtml(r, labels[k]);
  progress();
}

function focusPair(i) {
  cur = Math.max(0, Math.min(ROWS.length - 1, i));
  const el = document.getElementById('p' + cur);
  el.scrollIntoView({block:'center'});
  el.style.outline = '2px solid #7a9cc6';
  setTimeout(() => { el.style.outline = ''; }, 400);
}

document.addEventListener('keydown', e => {
  if (e.metaKey || e.ctrlKey || e.altKey) return;
  if (e.key === 's') { setL(cur, 'same'); focusPair(cur + 1); }
  else if (e.key === 'd') { setL(cur, 'different'); focusPair(cur + 1); }
  else if (e.key === 'u') { setL(cur, 'unsure'); focusPair(cur + 1); }
  else if (e.key === 'j') { focusPair(cur + 1); }
  else if (e.key === 'k') { focusPair(cur - 1); }
  else return;
  e.preventDefault();
});

function exportTsv() {
  const head = ['n','stratum','derived','covers','label','note','a_text','b_text','a_id','b_id'];
  const lines = [head.join('\\t')];
  ROWS.forEach((r, i) => {
    const k = idOf(r);
    if (!labels[k]) return;
    lines.push([i+1, r.stratum, r.derived || '', r.pairs.length, labels[k], '',
      r.a_text.replace(/\\t/g,' '), r.b_text.replace(/\\t/g,' '),
      r.pairs[0][0], r.pairs[0][1]].join('\\t'));
  });
  if (lines.length === 1) { alert('Nothing answered yet.'); return; }
  const blob = new Blob([lines.join('\\n')], {type:'text/tab-separated-values'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = KEY + '.tsv';
  a.click();
}

render();
focusPair(0);
</script>
</body>
</html>
HTML;
}

?>
