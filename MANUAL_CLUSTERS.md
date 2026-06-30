# Manual Cluster Assertions — Design Notes

A way for a human to say "these two records are the same work" when the
automatic clustering can't see it (e.g. one of the records has bad metadata,
the DOI is wrong, the title is misspelled, etc.), and have that assertion
survive every subsequent re-clustering pass.

This is a sketch. Not yet implemented.

## What we need it to do

1. Let a human pin two (or more) records together as members of the same
   cluster, even when the feature vector says "no match".
2. Survive future re-clusterings — the next worker visit must not split them
   again.
3. Be honest in the comparison log — a human override should not look like
   an algorithmic match (otherwise it pollutes the training data when we
   eventually train the perceptron).
4. (Probably also) the inverse — let a human say "these two are NOT the
   same" when the algorithm clusters them wrongly.

## Three places this could live

### Option A — `doc.citebank.same_as` on the records themselves *(recommended for v0)*

Add a field on each record listing the other records it has been manually
asserted to be the same as:

```json
{
  "_id": "doc-A",
  "citebank": {
    "type": "work",
    "cluster": "doc-A",
    "same_as": ["doc-B", "doc-C"]
  }
}
```

The assertion is **symmetric** — writing `A.same_as = [B]` should also write
`B.same_as = [A]`, so the assertion shows up regardless of which doc enters
the worker first.

**How the worker uses it:**

1. When the worker pulls a doc off the queue, before running the tier
   ladder, expand the candidate set with every ID in `same_as` (and
   recursively, every ID in *their* `same_as`). These records get pulled in
   even when no tier would have surfaced them together.
2. In `cluster_candidates`, after the pairwise feature-vector loop, do an
   extra pass: for every asserted pair `(A, B)`, force `dj->union(A, B)`
   regardless of the feature vector.
3. Log the forced union as a comparison entry with `decision='forced'` and
   `tier='manual'` — so the training data, when we extract it later, can
   filter these out.

**Why this is good for v0:**

- Cheapest to implement (~30 lines).
- The assertion travels with the record. When you open the doc to ask "why
  did this cluster?", the answer is right there.
- Survives re-clustering by construction (the worker re-reads the field
  every pass).
- Composes correctly: if you assert A↔B and B↔C, the DSU merges all three
  into one cluster the next time any of them is visited.

**Drawback:** assertions live inside doc bodies. If we end up with thousands,
the bodies bloat. That's the trigger to graduate to (B).

### Option B — side-document for manual links *(future)*

A separate document per assertion:

```json
{
  "_id": "link:doc-A,doc-B",
  "type": "manual_link",
  "pair": ["doc-A", "doc-B"],
  "decision": "same",
  "reason": "Same article — wrong DOI on doc-B (typo in zootaxa import)",
  "added_by": "rpage",
  "added": "2026-06-05T..."
}
```

Same effect on the worker, but the assertions are stored separately. Needs
a new design doc + view (`_design/links/_view/by-member` keyed on doc ID)
to find assertions touching the current candidate set.

**Why this is better long-term:**

- Doesn't bloat doc bodies.
- Natural audit trail / undo log — every assertion is a document.
- Clean place to put provenance (`reason`, `added_by`, `added`).
- Makes it easy to add metadata later (confidence, tags, etc.).

**Why not v0:** more wiring (new design doc, new view, new query path,
async invalidation when assertions change), and you don't need it until
the per-doc storage starts to feel cramped.

### Option C — `cluster_force = "<id>"` *(rejected)*

Hard-wire a cluster ID on the doc and have the worker treat it as final.

**Don't do this:** breaks the property in DESIGN.md that `cluster_id` is a
deterministic function of cluster membership (lex-min member ID). Also
doesn't compose — you'd have to pick an anchor for each manual cluster,
and merging two manual clusters becomes annoying.

## Negative assertions ("not-same")

Same shape, opposite effect:

- (A): `doc.citebank.not_same_as = [...]` on the doc.
- (B): same side-doc model, `decision: "different"`.

In `cluster_candidates`, a `not_same_as` entry vetoes a `dj->union()` even
when the feature vector says match.

Worth designing for from the start — clustering errors go both directions,
and the worker will produce false positives we need to break apart, not
just false negatives we need to glue together.

## Where the human triggers an assertion

Cheapest v0:

```sh
php worker.php --assert "doc-A" "doc-B"
```

This:
1. Fetches both docs.
2. Appends the symmetric `same_as` entries.
3. Runs `cluster_candidates([A, B], 'manual')` immediately, so the merge
   happens on the spot (no waiting for the queue to come around).
4. PUTs both docs back.

Next step (once the CLI feels frustrating): a button on the cluster-view
page in the web UI that POSTs to an `api.php?assert=...` endpoint.

## Concrete implementation sketch (Option A)

```
worker.php / cluster.php changes:

1. assert_same_as($id_a, $id_b)
   - GET both docs
   - append id_b to a.citebank.same_as (uniq)
   - append id_a to b.citebank.same_as (uniq)
   - PUT both
   - cluster_candidates([a, b], 'manual')

2. expand_with_assertions($candidates)
   - new helper: BFS over same_as fields
   - returns the closure of candidates + asserted partners

3. cluster_candidates() additions:
   - after building $by_id, call expand_with_assertions
   - after the pairwise loop, scan each doc's same_as field
     and dj->union() each pair (logging tier='manual',
     decision='forced')

4. CLI: worker.php --assert A B
   - calls assert_same_as
```

Roughly 30 lines of new code.

## Open questions

- **Anchor cluster IDs.** After a manual union, the cluster ID becomes the
  lex-min member ID as usual. Sometimes the "right" canonical record (the
  one with the cleanest metadata) is *not* the lex-min one. Do we want a
  way to override that too? Probably not for v0 — the consensus record
  papers over this. But worth flagging.

- **Display.** Should the cluster page in the UI indicate which clusters
  contain manual assertions? (Tiny badge? Tooltip?) Useful for trust.

- **Undo.** With (A), removing an assertion means re-editing the doc and
  removing the entry from `same_as` — fine in Fauxton, awkward elsewhere.
  Another reason (B) gets attractive once you accumulate assertions.

- **Transitive nuisance.** If A is manually linked to B, and the algorithm
  also clusters B with C, does C inherit the manual link? Yes, because DSU
  merges them. That's probably what you want — but it means a single human
  assertion can ripple a long way if downstream algorithmic matches are
  wrong. Negative assertions (`not_same_as`) become the safety valve.
