# Journal-name clustering

Clustering raw `container-title` strings from the CouchDB citation store into
groups that (approximately) correspond to one journal.

## Data

`../container.tsv` — no header. Column 1 = raw container-title, column 2 = ISSN
(often blank). ~147k rows, almost all distinct. Only ~0.5% carry an ISSN, but
those ISSNs are used as ground truth for tuning.

## Pipeline

```
php import.php [tsv] [db]              # build containers.db: normalise + generate keys
php runs.php --start='act ara'         # read-only runs-and-breaks report (LCS primary)
php cluster_runs.php                    # pass 1: assign cluster_id by run-chaining
php block_union.php                     # pass 2: merge clusters across keys + ISSN must-links
php export.php                          # write clusters.tsv for review (--min=2 for multi only)
php export.php --format=html --min=2 --out=clusters_multi.html   # standalone HTML view
php couch_export.php                    # build container_docs.json (curation-aware, dry run)
php couch_push.php                      # _bulk_docs container_docs.json into CouchDB
```

### CouchDB container docs

`couch_export.php` turns each cluster into a container doc matching the hand-built
template (`../acta-arachnologica.json`):

```
{ "_id":"container:<slug>", "citebank":{"type":"container"},
  "name":<canonical>, "ISSN":[...], "variants":[...raw...],
  "cluster_run":"<run>", "junk":true|false }
```

- **Slug ids** (`container:<slug-of-name>`) are deterministic, so a re-run updates
  in place; collisions get `-2`, `-3` suffixes.
- **Curation**: any container doc with `"curated": true` is authoritative. Clusters
  whose variants overlap a curated doc are skipped (absorbed), so regeneration never
  clobbers hand-curated docs. Add `curated:true` to a cluster as you vet it.
- **junk** = cluster has no clean variant (raw starting with a letter + a real token);
  excluded from listing views, surfaced in `container-junk` for parser-error review.

`_design/container` (`../couchdb/container.js`) views:
`container-list` (non-junk, `[letter,name]`) · `container-junk` (review worklist) ·
`variant` (raw → `_id`, for resolution) · `manage` (`cluster_run → _id`, non-curated,
for re-run delete/diff). Current load: 47,412 docs (46,388 listed + 1,024 junk).

### Reviewing (`export.php`)

Writes one row per distinct raw name, clusters kept contiguous and ordered
alphabetically by each cluster's representative. Columns `band` (0/1, flips each
cluster) and `flag` (`+` on a cluster's non-representative members) drive Google
Sheets conditional formatting: shade rows on `band` to separate clusters, and
highlight `flag = "+"` to spot the extra names merged into each cluster. Sort by
`rows` desc to surface the largest clusters (where over-merges hide). `--min=2`
drops singletons (`clusters_multi.tsv`).

`--format=html` writes a standalone, self-contained page (no server/upload) with
alternating per-cluster shading, the representative name in bold and merged-in
members marked `+`, and a live filter box. It's one big table (~33 MB / 116k rows
for `--min=2`), so it takes a moment to render; use a higher `--min` for a lighter
file.

`measures.php` holds the shared adjacency measures; `report.php` the shared metrics;
`disjoint_set.php` the union-find used by pass 2.

### Schema (`containers.db`, table `containers`)

| column       | meaning                                             |
|--------------|-----------------------------------------------------|
| `raw`        | original container-title                            |
| `issn`       | ISSN if present (ground truth)                      |
| `normalized` | lower-cased, accent/punctuation-stripped form       |
| `tokens`     | significant tokens (stop words, pure numbers, series gone) |
| `series`     | series / new-series marker lifted out of the name (see below) |
| `key_a`      | ordered word-prefixes (first 3 chars each)          |
| `key_b`      | sorted word-prefixes (order-independent)            |
| `key_c`      | leading 3-word prefix anchor (survives trailing tails) |
| `key_d`      | initials of each token (acronym ↔ expansion bridge) |
| `cluster_id` | assigned by the clustering step (not yet populated) |

### Blocking keys

Journal abbreviations are prefix truncations of words (`Arachnol` → `Arachnologica`),
so prefix-based keys survive abbreviation where first-letter "sort keys" over-merge.
Each record gets several keys; two names are candidate matches if they share *any*
non-empty key (canopy/union-find style). Empty keys (junk like `-`, `p`) are skipped.

Measured against ISSN ground truth, the four keys put **~78%** of same-ISSN name
pairs in a shared block. The rest are pure acronyms (need a dedicated rule) or true
title renames (only ISSN can link those).

### Series markers

Series / new-series markers ("ser. 10", "n. s.", "nouvelle série", "Neue Folge",
"Serie A", ...) are usually noise: they mark the same journal or a continuation.
`strip_series()` lifts them out of the keyed text into the `series` column (~8% of
rows carry one), so variants cluster together by default while the marker is
retained for possible later sub-splitting. Bare section letters with no following
subject word (`Serie A` alone) are dropped, so A/B sections merge; where a subject
word survives (`Serie A (Biologie)`) the sections stay distinct.

## Clustering passes

**Pass 1 — runs-and-breaks (`cluster_runs.php`).** Sort by `key_a`, walk the list,
and chain each row into the previous cluster while abbreviation-aware token **LCS
>= 0.50**; break otherwise. Single-linkage along one sort order — cheap (~4s) and
keeps abbreviation/OCR variants of one journal together (e.g. the whole *Ann. Soc.
Ent. Fr.* block). Of three adjacency measures compared, LCS was decisively best;
Jaccard and shared-prefix both over-segment exactly the abbreviation cases.

Current result: ~48k clusters, **52% ISSN purity**. The gap to the ~78% blocking
ceiling is by construction — runs only merge rows that sort *adjacent* under
`key_a`. Variants sharing a *different* key (word-order via `key_b`, acronyms via
`key_d`) sort apart and are never compared. (Some "splits" are also noisy ground
truth: ISSNs mis-assigned to two different journals.)

**Pass 2 — block union (`block_union.php`).** Each pass-1 cluster gets one
representative (its longest member); within each `key_b` / `key_c` block we compare
distinct clusters' representatives and union them when **LCS >= 0.85**, then apply
**ISSN must-links** (union all clusters sharing an ISSN). Runs in ~1s.

The threshold is deliberately far stricter than pass 1: pass 2 compares whole-cluster
representatives, so a false match cascades via single-linkage. `key_d` (initials) is
excluded — at 0.5 it produced a 12k-row, 31-journal blob — and is left to a future
acronym rule. `--noissn` runs string-only (for honest measurement); `--lcs` / `--keys`
/ `--cap` tune it.

### Results (212 ISSN journals seen on >1 row)

| stage | journal purity | over-merge clusters |
|---|---|---|
| pass 1 (runs)                  | 66.0% | 18 |
| pass 2, string only            | 67.9% | 18 |
| pass 2 + ISSN must-links       | 100%* | 13 |

\* tautological — ISSN purity is forced once must-links are on; it validates the
ISSN-bearing subset, not the string clustering. String-only (67.9%) is the honest
recall proxy. Residual over-merges concentrate in boilerplate-heavy institutional
names whose abbreviations collide (`Trans. Am. * Society`, `J. Mar. Biol. Assoc.
India`/`UK`, `Bull. MNHN Section A`/`C`).

## Next

- **Acronym rule** (recover the deferred `key_d` cases: `jhr`/`app`/`ai`/`ejt`):
  match single-token names against the initials of multi-word names, gated on
  corroboration (shared ISSN, or distinctive length) to avoid false bridges.
- **Pass-1 boilerplate guard**: the 0.5 threshold merges `Trans. Am. * Society`
  siblings; weight discriminating tokens over shared institutional words.
- Tune the pass-2 `--lcs` recall/precision knob; export clusters for manual review.
