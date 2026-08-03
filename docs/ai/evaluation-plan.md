# Evaluation Plan — Search Quality Benchmarking

## Golden Dataset Format

JSON file with query-relevance pairs:

```json
{
  "version": 1,
  "queries": [
    {
      "id": "q001",
      "query": "watercolor landscape",
      "relevant_artwork_ids": [12, 34, 56, 78, 90],
      "notes": "Artworks with watercolor style and landscape theme"
    },
    {
      "id": "q002",
      "query": "кот акварель",
      "relevant_artwork_ids": [23, 45],
      "notes": "Russian query for cat watercolor"
    }
  ]
}
```

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

## Artisan Command

```powershell
php artisan ai:evaluate --dataset=golden.json --variant=baseline_sql
php artisan ai:evaluate --dataset=golden.json --variant=hybrid_search
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
