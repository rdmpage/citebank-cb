# Container-title clustering — status

How CiteBank turns the messy free-text `container-title` strings on work records
into clustered **journals**, exposes them in CouchDB, lets you browse them, and
feeds them back to the work-clustering worker.

This is the at-a-glance overview. For the clustering *algorithm* detail see
[`clustering/README.md`](clustering/README.md); for the work-level (citation
dedup) clustering see [`DESIGN.md`](DESIGN.md) and
[`MANUAL_CLUSTERS.md`](MANUAL_CLUSTERS.md).

> **Two different "clusters" in this repo — don't confuse them:**
> - **Work clusters** (`doc.citebank.cluster`) — duplicate *citations* of the same
>   article merged together. Owned by `worker.php` / `cluster.php`.
> - **Container clusters** (`container:<slug>` docs) — variant *spellings of one
>   journal* grouped together. The subject of this document.

---

## Pipeline at a glance

```
container.tsv ──► SQLite clustering ──► container_docs.json ──► CouchDB container docs
(146k raw names)   (clustering/)         (couch_export.php)      (_design/container)
                                                                      │
                                          containers.html ◄───────────┤  browse
                                          worker.php --cid ◄───────────┘  cluster works per journal
```

---

## 1. Offline clustering (SQLite) — `clustering/`

Input: `container.tsv` (col 1 = raw `container-title`, col 2 = ISSN if known),
~146k rows, almost all distinct, ~0.5% with an ISSN.

Two passes (see `clustering/README.md` for the keys and measures):

1. **Runs-and-breaks** (`cluster_runs.php`) — sort by prefix key, chain rows while
   abbreviation-aware token **LCS ≥ 0.50**.
2. **Block union** (`block_union.php`) — merge clusters across the other keys at the
   stricter **LCS ≥ 0.85**, plus **ISSN must-links**.

| | value |
|---|---|
| rows | 146,745 |
| clusters | 47,415 |
| ISSN journal purity, pass 1 | 66% |
| ISSN journal purity, string-only (pass 2) | 68% |
| ISSN journal purity, + ISSN must-links | 100%\* |

\* tautological — ISSN must-links force it; the honest string-clustering number is ~68%.

Export for review: `clusters.tsv` / `clusters_multi.html` (banded, filterable).

## 2. Container docs in CouchDB

`couch_export.php` (dry run → `container_docs.json`) + `couch_push.php` (bulk load).
One doc per cluster, slug `_id`, matching the hand template
[`acta-arachnologica.json`](acta-arachnologica.json):

```json
{ "_id": "container:<slug>", "citebank": { "type": "container" },
  "name": "<canonical>", "ISSN": [...], "variants": ["...raw..."],
  "cluster_run": "<YYYYMMDD>", "junk": true|false }
```

- **Loaded:** 47,412 docs — **46,388 listed** + **1,024 junk** (+ curated docs).
- **`junk`** = cluster has no "clean" variant (raw starting with a letter + a real
  token). Excluded from listings; surfaced for **parser-error review**.
- **Curation:** a doc with `"curated": true` is authoritative. Re-generation skips
  (absorbs) any cluster whose variants overlap a curated doc, so hand-curation is
  never clobbered. Mark a cluster `curated:true` as you vet it.

**Design doc** `_design/container` ([`couchdb/container.js`](couchdb/container.js)):

| view | key → value | purpose |
|---|---|---|
| `container-list` | `[letter, name]` → variant count | letter index + listing (non-junk; accents folded to base letter) |
| `container-junk` | `[letter, name]` → variant count | parser-error review worklist |
| `variant` | raw string → `_id` | resolve a spelling to its cluster |
| `manage` | `cluster_run` → `_id` | find/delete a prior non-curated generation (curated untouched) |

## 3. Browsing — `containers.html` + `api.php`

Round-trip navigation, all in the browser:

```
letter index ──► journal cluster (?cid=) ──► works across all variants
                        ▲                          │
                        │                          ▼  each variant links to
                        └────────── ?title= (works for one exact spelling)
```

API routes (all under `?container`): `&first` (letters), `&letter=X` (journals under
a letter), `&cid=<id>` (works across a cluster's variants), `&title=<raw>` (works for
one spelling), `&variant=<raw>` (reverse: which cluster owns this spelling).

## 4. Worker integration — `worker.php`

The work-clustering worker can be scoped to a whole journal:

- **`--cid container:<slug>`** — visit the stalest work across **every variant
  spelling** of the cluster (not just one exact name).
- **`--once`** — drain the chosen scope in one pass (visit every work once, then
  exit) instead of one-doc-per-invocation.
- Precedence: `--cid` → `--container` (exact) → `--author` → global queue.

```sh
php worker.php --cid container:novititates-zoologicae --once   # one full pass over a journal
```

---

## Re-running the clustering

1. Re-run the SQLite passes, `php couch_export.php` (dry run), eyeball the summary.
2. Delete the previous non-curated generation via the `manage` view, then
   `php couch_push.php`. Curated docs survive untouched.

## Known issues / review backlog

- **Under-merge** — some journals split into several clusters (e.g. *Acta
  Arachnologica* (Tokyo)/Osaka/typo). Same gap as the deferred acronym work.
- **Over-merge** — boilerplate-heavy institutional siblings collide (*Trans. Am. *
  Society*, *J. Mar. Biol. Assoc.* India/UK, *Bull. MNHN* sections). ~13 clusters.
- **Acronyms** — `jhr`/`app`/`ai`/`ejt` don't merge to their expansions (needs a
  dedicated, evidence-gated rule).
- **Junk (1,024)** — flagged for review; mostly leading-punctuation parser errors.
- **Partial overlaps** — clustering sometimes finds spellings a curated doc is
  missing; `couch_export.php` reports these as "review/add" candidates.
- **Non-canonical names** — canonical = most-frequent raw, so OCR/misspelled forms
  can win (e.g. `Novititates Zoologicae`, `Acarolo-gia`).

## Next levers

- Acronym ↔ expansion rule (recover the deferred `key_d` cases).
- Pass-1 boilerplate guard (weight discriminating tokens over institutional words).
- Tighten clustering before a re-load so there's less hand-cleanup.
- Curated-doc evaluation set (better ground truth than the sparse ISSNs).

## File map

| path | role |
|---|---|
| `clustering/` | offline SQLite clustering (import, keys, passes, exports) — see its README |
| `clustering/couch_export.php` | clusters → `container_docs.json` (curation-aware, dry run) |
| `clustering/couch_push.php` | bulk `_bulk_docs` load into CouchDB |
| `couchdb/container.js` | `_design/container` views |
| `acta-arachnologica.json` | hand-built container-doc template (`curated:true`) |
| `api.php` | container API routes (`get_containers_*`, `get_container_for_variant`, `get_works_by_container_id`) |
| `containers.html` | browsing UI |
| `worker.php` | work-clustering worker (`--cid`, `--once`) |
