# Search Pipeline

## Hybrid Search Architecture

```
User Query
    │
    ├──► Lexical Search (SQL LIKE on title)
    │        Returns: ranked artwork IDs by likes_count
    │
    └──► Semantic Search (if AI enabled)
             Query → Python /api/v1/search
             Python: text → embedding → Qdrant search
             Returns: ranked artwork IDs by cosine similarity
    │
    ▼
Reciprocal Rank Fusion (RRF)
    Merges both ranked lists
    k = 60 (configurable)
    Score = Σ 1/(k + rank_i)
    │
    ▼
MySQL Re-verification
    Load artworks by fused IDs
    Apply publiclyVisible() scope
    Apply additional filters (type, tag, author)
    Preserve RRF order
    │
    ▼
Final Results
```

## Reciprocal Rank Fusion (RRF)

RRF is a simple and effective method for merging multiple ranked lists:

```
RRF_score(d) = Σ(r ∈ R) 1 / (k + rank_r(d))
```

Where:
- `d` is a document (artwork)
- `R` is the set of ranking systems (lexical + semantic)
- `rank_r(d)` is the rank of document `d` in ranking `r`
- `k` is a constant (default: 60)

Documents appearing in both lists get boosted scores.

## Configuration

```env
AI_SEARCH_LEXICAL_LIMIT=50    # Max lexical candidates
AI_SEARCH_SEMANTIC_LIMIT=50   # Max semantic candidates
AI_SEARCH_RRF_K=60            # RRF smoothing constant
AI_SEARCH_FINAL_LIMIT=20      # Final page size
```

## Fallback Behavior

1. If AI disabled → lexical only
2. If Python unreachable → lexical only (logged as warning)
3. If semantic returns empty → lexical only
4. Empty query → random recommendations (no hybrid search)

## Similar Artworks

```
Artwork ID
    │
    ▼
Check AI metadata indexed?
    │
    ├── Yes ──► Python /api/v1/similar
    │              Qdrant: find vector → cosine similarity
    │              Returns: candidate IDs
    │              Laravel: re-verify via MySQL
    │
    └── No ───► Tag-based fallback
                   SQL: shared tags → order by likes
```
