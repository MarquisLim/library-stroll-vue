# Evaluation Plan — Search Quality Benchmarking

## Research Dataset Format (implemented 2026-11-11)

Preparation and annotation workflow: [Research dataset instructions](../ru/research-dataset.md).
The old illustrative `relevant_artwork_ids` JSON is replaced by a fixed corpus and graded independent human judgments:

```text
storage/app/research-datasets/<name>/versions/<version>/
  manifest.json            # schema/rule versions, seed, policy, intent partitions
  artworks.jsonl           # stable source:external_id, provenance, hashes, local media references
  queries.jsonl            # query_id, intent_id, text, language, type, split
  pool.jsonl               # candidate pairs and inclusion methods, not automatic grades
  judgments.csv            # query_id,dataset_item_id,relevance,annotator_id,judged_at
  judgment-context.json    # binds human grades to corpus and question text
  checksums.sha256
  validation.json
  README.md
```

`DatasetVersionManager::readVersion($name, $version)` verifies and reads these fixed artifacts without accessing the demo manifest, database or AI. Future evaluators must use stable dataset IDs, preserve grades 0–3 and annotator provenance, and document their relevance threshold/adjudication policy. Missing pairs are **unjudged**, not grade 0. Source metadata, Laravel-normalized metadata and future AI metadata are separate layers. LLM metadata must never become ground-truth relevance labels.

Queries, not documents, are split approximately 25% development / 75% evaluation by intent. Equivalent RU/EN questions share a partition. Tune only on development queries; freeze independent evaluation queries before experiments. A bounded lexical/category/random/cross-category annotation pool has selection bias. Extend it with other systems' results before claiming comprehensive recall; never silently treat unjudged results as irrelevant.

`dataset:prepare` creates a draft; `dataset:validate` distinguishes structural errors, warnings and readiness blockers; `dataset:freeze --name=... --dataset-version=1.0` refuses unfinished catalog/query review/human annotation and never overwrites an existing version. Media are referenced, not duplicated: preserve separately licensed media backups and verify original SHA-256 before reproducing experiments. No experimental variants or metrics are run during preparation.

## Metrics

### Precision@K
```
P@K = |relevant ∩ top_k| / K
```
Report: P@5, P@10

### Recall@K
```
R@K = |relevant ∩ top_k| / |relevant|
```
Report: R@10

### Mean Reciprocal Rank (MRR)
```
MRR = 1/|Q| Σ 1/rank_i
```
Where rank_i is the position of the first relevant result.

### nDCG@K
```
DCG@K = Σ(i=1..K) rel_i / log2(i+1)
nDCG@K = DCG@K / IDCG@K
```
Report: nDCG@10

### Latency
- p50, p95 (milliseconds)
- Measured end-to-end from Laravel request to response

## Evaluation Variants

| Variant | Description |
|---------|-------------|
| baseline_sql | Current SQL LIKE + tag search |
| vector_search | Qdrant semantic search only |
| hybrid_search | Lexical + semantic via RRF |
| hybrid_metadata | Hybrid + LLM-extracted metadata |
| multimodal | Hybrid + vision-analyzed images |

## Planned Evaluation Command (not implemented in this stage)

```powershell
php artisan ai:evaluate --dataset=library-stroll-v1 --dataset-version=1.0 --variant=baseline_sql
php artisan ai:evaluate --dataset=library-stroll-v1 --dataset-version=1.0 --variant=hybrid_search
```

## Output Format

```json
{
  "variant": "hybrid_search",
  "timestamp": "2026-08-03T12:00:00Z",
  "metrics": {
    "precision_at_5": null,
    "precision_at_10": null,
    "recall_at_10": null,
    "mrr": null,
    "ndcg_at_10": null,
    "latency_p50_ms": null,
    "latency_p95_ms": null
  },
  "queries_evaluated": 0,
  "notes": "Scores to be filled after golden dataset creation"
}
```

Results are NOT pre-filled with fictitious scores.
A golden dataset must be manually curated before running evaluation.
