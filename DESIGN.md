# CiteBank: Design Notes

A bibliographic database for taxonomy, storing references as CSL-JSON objects with support for deduplication, clustering, and citation graph construction.

## Overview

CiteBank ingests bibliographic records from multiple sources (CrossRef, DOI content negotiation, JATS-XML, Wikispecies, BHL, ORCID, etc.), stores them as CSL-JSON documents in CouchDB, and progressively clusters duplicate records that refer to the same work. A consensus record is computed on the fly from cluster members using Bayesian belief propagation. The system is eventually consistent — it does not aim to be fully clustered at any point in time, but steadily improves through a background worker process.

## Data Store

CouchDB is the canonical store. CSL-JSON maps naturally to CouchDB documents, and the revision model provides conflict detection for concurrent updates. CouchDB views handle exact-match lookups (DOI, ISSN, year/volume/page hash) for blocking/candidate retrieval. Nouveau (Lucene-based search built into CouchDB) provides fuzzy full-text search for harder matching cases.

Elasticsearch is not needed initially. If Nouveau proves insufficient for fuzzy matching quality, Elasticsearch can be added later as a read-only search index fed from CouchDB via the `_changes` feed, without changing the data model or worker logic.

## Document Model

### Record Structure

CSL-JSON is stored as the top-level document, with a `citebank` key added for internal metadata. This means documents are valid CSL-JSON (processors ignore unknown keys), existing views work directly on `doc.title`, `doc.author`, etc., and no envelope/wrapper is needed.

```json
{
  "_id": "https://doi.org/10.1111/j.1440-6055.1997.tb01440.x",
  "title": "Pseudobalta, a New Australian Ovoviviparous Cockroach Genus",
  "author": [
    { "given": "Louis M.", "family": "Roth" }
  ],
  "container-title": "Australian Journal of Entomology",
  "volume": "36",
  "issue": "2",
  "page": "101-108",
  "DOI": "10.1111/j.1440-6055.1997.tb01440.x",
  "ISSN": ["1326-6756", "1440-6055"],
  "issued": { "date-parts": [[1997, 5]] },
  "is-referenced-by": [
    {
      "DOI": "10.11646/zootaxa.4109.2.3",
      "source": "crossref-reference"
    }
  ],
  "citebank": {
    "type": "work",
    "source": "crossref",
    "format": "application/vnd.citationstyles.csl+json",
    "created": "2024-05-26T16:40:18+00:00",
    "modified": "2024-05-26T16:40:18+00:00",
    "fetched": "2024-05-26T16:40:18+00:00",
    "cluster": "https://doi.org/10.1111/j.1440-6055.1997.tb01440.x",
    "clustering": {
      "visited": "2024-06-01T12:00:00+00:00",
      "algorithm": "heuristic-v0",
      "tier": "doi-exact",
      "comparisons": [
        {
          "id": "https://zoobank.org/References/ca131643-...",
          "score": 0.92,
          "decision": "match",
          "features": [1, 0, 1, 0, 0, 0, 0, 0, 1, 0, 1, 0],
          "tier": "doi-exact",
          "timestamp": "2024-06-01T12:00:00+00:00"
        }
      ]
    }
  }
}
```

### Document Types

The `citebank.type` field distinguishes different kinds of documents in the database:

- **`work`** — a bibliographic record (CSL-JSON article, book, chapter, etc.)
- **`container`** — a journal/series authority record with spelling variants
- **`person`** — (future) an author authority record

All CouchDB views should filter on `citebank.type` to avoid mixing document types.

### Record Identifiers (`_id`)

The `_id` is a meaningful URL denoting where the record came from:

- DOI records: `https://doi.org/10.1111/...`
- ZooBank records: `https://zoobank.org/References/...`
- Wikispecies records: the wiki page URL
- Parsed citations from reference lists: fragment URI, e.g. `https://doi.org/10.12657/folmal.028.002#e_1_2_1_3_1`
- Records without a natural URL: `urn:citebank:<md5>` where the MD5 is computed from a canonicalised form of the CSL-JSON (normalised title, first author family name, year, volume, first page, DOI)

Raw URLs are preferred over hashes for legibility, collision semantics, and provenance.

### Provenance

The `citebank` block records provenance:

- `source` — controlled vocabulary: `crossref`, `zoobank`, `wikispecies`, `bhl`, `orcid`, `jats-reference`, `user-upload`, etc. Also used to assign source-specific confidence values for the Bayesian merge.
- `format` — MIME type of the original data
- `created` — when the record was first added to CiteBank
- `modified` — when the record was last modified
- `fetched` — when the source was last checked (for periodic refresh)

### Soft Delete

Records are never hard-deleted. A `citebank.deleted` timestamp marks records as removed. All views filter on `!doc.citebank.deleted`. This avoids orphaned cluster IDs (if the record whose `_id` is the cluster ID gets deleted, the cluster still functions) and allows undoing bad harvests.

### Citation Graph

The `is-referenced-by` array sits on the **cited** record and points outward to the **citer** — the paper whose reference list yielded this record. The DOI in each entry belongs to the citing paper; the cited record itself may have no DOI.

```json
"is-referenced-by": [
  { "DOI": "10.11646/zootaxa.4109.2.3", "source": "crossref-reference" }
]
```

This field is populated only by parsing-based ingest paths — extracting CSL-JSON from the HTML or JATS-XML of individual papers' reference lists. Records fetched directly via DOI content negotiation (e.g. CrossRef) will not have it; CrossRef instead provides outbound `reference`.

A record carries one `is-referenced-by` entry corresponding to the source it was extracted from. The array is set at ingest and **is not modified afterwards**. If the same work is cited from multiple sources, each ingest produces its own document (with its own `_id` and its own `is-referenced-by`); the documents are linked together by clustering, not by writing back to a single shared record.

Citation count for a work is therefore a **query-time aggregation** across the members of its cluster, not part of the consensus merge. A CouchDB view emits `(cluster_id, is-referenced-by)` for each work; querying by `cluster_id` returns all citing contexts for the cluster, and the count grows as more citing sources are harvested without any record being rewritten.

## Clustering

### Philosophy

The database is a collection of citation objects in various states of clustering, not a single source of truth. Clustering is approximate, incremental, and self-improving. The system is useful even before clustering is complete — individual records are searchable immediately; clusters and consensus records are a bonus.

### Cluster ID

The cluster ID is the lexicographically smallest `_id` among all members of the cluster. When merging two clusters, the smaller cluster ID wins. This means DOI URLs (which start with `https://doi.org/`) tend to become cluster IDs, since they're short and well-known.

Every record has a `citebank.cluster` field. Unclustered records are singletons where `citebank.cluster` equals their own `_id`.

### Consensus

The consensus (merged) record is computed on the fly when requested, never stored. This avoids stale consensus records and simplifies the data model. The merge uses Bayesian belief propagation (based on Councill et al. 2006, doi:10.1145/1141753.1141817), where each source has a confidence value and the most-believed value for each field is selected.

### Queue

A CouchDB view sorts records by `citebank.clustering.visited`, with null (never visited) sorting first. A background worker (cron job) pulls the top record, processes it, and stamps the visited timestamp, pushing it to the back of the queue. Records cycle through indefinitely, getting re-evaluated with the latest algorithm.

```javascript
// Queue view
function(doc) {
  if (doc.citebank && doc.citebank.type == 'work' && !doc.citebank.deleted) {
    var visited = (doc.citebank.clustering && doc.citebank.clustering.visited)
      ? doc.citebank.clustering.visited
      : null;
    emit(visited, null);
  }
}
```

The queue is self-healing: if a new algorithm version is deployed, records processed by the old version naturally cycle back to the front. The `algorithm` field on each record tracks which version last processed it.

### Comparison Log

Each record carries a ring buffer of recent comparison results in `citebank.clustering.comparisons`, capped at ~5 entries (oldest dropped when full). Each entry records:

- `id` — the candidate record's `_id`
- `score` — the match score (perceptron output or heuristic score)
- `decision` — `match` or `no-match`
- `features` — the feature vector (for training data extraction)
- `tier` — how the candidate was found
- `timestamp` — when the comparison was made

This log serves dual purposes: debugging clustering decisions, and generating labelled training data for the ML model.

### Tiered Matching

The worker uses tiered blocking to avoid comparing every record against the entire database:

**Tier 1 — DOI exact match.** Query the `kv/doi` view. Cheapest and highest precision. Even DOI matches go through at least a sanity check (title similarity > threshold) because DOIs can be wrong.

**Tier 2 — Year + volume + first page hash.** Query the `matching/hash` view. Catches records that share bibliographic coordinates but come from different sources with different DOIs (or no DOI).

**Tier 3 — Nouveau fuzzy search.** Full-text search on normalised title + first author + year. Only used when Tiers 1-2 find no candidates. Most expensive, but handles the long tail of records without DOIs or standard metadata (older literature, Wikispecies, BHL).

If a match is found at any tier, the worker stops (no need to try more expensive tiers).

### Matching: Heuristic → Perceptron

The matching model evolves through versions:

**v0 — DOI only.** Same DOI = same cluster. No ML needed. Gets the obvious duplicates immediately.

**v1 — Heuristic rules.** Feature extraction (same/diff/miss for each CSL-JSON field), then simple counting: if any field actively differs, reject; if 3+ fields agree, accept. Conservative — low false-positive rate.

**v2 — Trained perceptron.** Once enough labelled pairs have been generated from v0/v1 comparisons (extracted from the comparison logs), train a perceptron on the feature vectors. Deploy as `algorithm: perceptron-v1`.

**v3+ — Improved features and models.** Better string comparison (e.g. trigram Jaccard for titles, phonetic matching for author names), more features (author count match, page range normalisation), potentially a model beyond a single perceptron.

Each version is useful. Each generates data that makes the next version better.

### Feature Vector

A pair of CSL-JSON records is compared field by field. Each field produces a binary pair:

- `[1, 0]` — same (field values match)
- `[0, 1]` — different (field values don't match)
- `[0, 0]` — missing (one or both records lack this field)

Fields compared: author (first author family name), title (Smith-Waterman subsequence alignment), container-title (subsequence), volume (exact), issue (exact), page (exact), DOI (exact), issued year (exact).

The feature vector is the concatenation of all field pairs, e.g. `[1,0,1,0,0,0,0,0,1,0,1,0]` for a 6-field comparison.

## Container Titles

Journal/container-title normalisation is handled as a lightweight lookup layer, not a full clustering system. A `container` document maps variant spellings and ISSNs to a canonical form:

```json
{
  "_id": "container:archives-of-natural-history",
  "citebank": { "type": "container" },
  "canonical": "Archives of Natural History",
  "variants": [
    "Arch. Nat. Hist.",
    "Archives of Natural History",
    "Archs nat. Hist."
  ],
  "ISSN": ["0260-9541", "1755-6260"]
}
```

A CouchDB view keyed on each variant and ISSN resolves any known spelling to the canonical form at display time. The underlying CSL-JSON records are not modified — container resolution is a presentation concern.

These records can be seeded from existing resources (BPH, ISSN Portal, LTWA abbreviation lists) and curated semi-automatically: a view grouped by ISSN shows all distinct container-title spellings for review.

## API

The API has two layers:

**Internal API** — REST-ish endpoints for record retrieval, search, feature comparison, consensus generation. Based on the existing `api.php` pattern with query parameters.

**OpenRefine Reconciliation API** — a separate endpoint (`reconcile.php`) that speaks the OpenRefine reconciliation protocol, delegating to the same underlying search and matching functions. Supports the standard query, preview, and suggest endpoints.

## Implementation Path

1. **v0**: Minimal worker — DOI matching only, heuristic rules, cron job every 30 seconds. Get clusters forming.
2. **v1**: Add Tier 2 (hash matching) and the heuristic same/diff/miss counter.
3. **v2**: Set up Nouveau index, add Tier 3 fuzzy search.
4. **v3**: Extract training data from comparison logs, train perceptron, deploy as new algorithm version.
5. **v4**: Container-title normalisation, citation graph building, OpenRefine reconciliation API.

Each step is independently useful. The queue ensures old records get re-evaluated as the system improves.

## Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Data store | CouchDB (canonical) + Nouveau (search) | Natural JSON fit, views for blocking, replication, revision tracking |
| Document format | CSL-JSON at top level + `citebank` key | No wrapper needed, compatible with CSL processors, views work directly |
| Record ID | Source URL (or `urn:citebank:md5`) | Legible, encodes provenance, collision detection for free |
| Cluster ID | Lexicographically smallest member `_id` | Deterministic, tends to be the DOI URL (best identifier) |
| Consensus storage | Computed on the fly | No stale records, simpler data model |
| Deletion | Soft delete (`citebank.deleted` timestamp) | Reversible, avoids orphaned cluster IDs |
| Comparison log | Ring buffer on each document (5 entries) | Auditability + training data, bounded storage |
| Container normalisation | Separate lookup documents, not clustering | Avoids building a second clustering system |
| Matching model | Heuristic first, perceptron later | Unblocks development, system generates its own training data |

## References

- Councill, I.G., Giles, C.L. & Kan, M.-Y. (2006) ParsCit: An open-source CRF reference string parsing package. Proceedings of the 6th ACM/IEEE-CS Joint Conference on Digital Libraries. doi:10.1145/1141753.1141817
- CSL-JSON schema: https://citeproc-js.readthedocs.io/en/latest/csl-json/markup.html
- Nouveau (CouchDB full-text search): https://neighbourhood.ie/blog/2024/10/24/first-steps-with-nouveau
- OpenRefine Reconciliation API: https://reconciliation-api.github.io/specs/latest/
