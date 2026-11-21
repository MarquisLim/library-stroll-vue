"""Run the Library Stroll 4.5A reranking experiment on frozen, local inputs."""

import argparse
import hashlib
import importlib.metadata
import json
import math
import resource
import statistics
import time
from pathlib import Path

import psutil
import torch
from safetensors import safe_open
from sentence_transformers import MultiVectorEncoder


MODEL_ID = "jinaai/jina-colbert-v2"
MODEL_REVISION = "a9dc5cd7293d4c71dbbba04829923ba4d0e4f6ea"
CODE_REVISION = "bd55a5ec8e6c0fb1d6c26efb4b6a4a74ce8a88d3"
WEIGHTS_SHA256 = "741387d37db6027ada13c4705b8bb32719a09dc71873f54814d1182cd8943806"
CANDIDATE_LIMIT = 30


def sha256(path):
    with path.open("rb") as stream:
        return hashlib.file_digest(stream, "sha256").hexdigest()


def read_json(path):
    return json.loads(path.read_text(encoding="utf-8-sig"))


def load_inputs(directory):
    proof = read_json(directory / "dense-integrity.json")
    if not proof["exact_order"] or not proof["sql_unchanged"] or not proof["qdrant_unchanged"]:
        raise ValueError("Dense input has not been verified against stage 4.4C")

    for name, digest in (
        ("documents.json", "documents_sha256"),
        ("query-plan.json", "plan_sha256"),
        ("dense-top30.jsonl", "dense_sha256"),
    ):
        if sha256(directory / name) != proof[digest]:
            raise ValueError(f"Frozen input changed: {name}")

    documents = read_json(directory / "documents.json")
    by_id = {row["id"]: row["text"] for row in documents}
    if len(documents) != 100 or sorted(by_id) != list(range(441001, 441101)):
        raise ValueError("Unexpected document corpus")
    for row in documents:
        if hashlib.sha256(row["text"].encode()).hexdigest() != row["text_sha256"]:
            raise ValueError(f"Document text hash mismatch: {row['id']}")

    plan = read_json(directory / "query-plan.json")
    lines = (directory / "dense-top30.jsonl").read_text(encoding="utf-8-sig").splitlines()
    candidates = [json.loads(line) for line in lines]
    if [query["id"] for query in plan] != list(range(1, 25)) or len(candidates) != len(plan):
        raise ValueError("Unexpected query set")
    for query, row in zip(plan, candidates):
        if any(query[key] != row[key] for key in ("id", "text", "lang", "relevant")):
            raise ValueError(f"Query or gold label changed: {query['id']}")
        ids = [hit["id"] for hit in row["results"]]
        if len(ids) != CANDIDATE_LIMIT or len(set(ids)) != CANDIDATE_LIMIT or not set(ids) <= by_id.keys():
            raise ValueError(f"Unexpected candidates: {query['id']}")

    return by_id, plan, candidates


def target_rank(hits, artwork_id):
    return next((rank for rank, hit in enumerate(hits, 1) if hit["id"] == artwork_id), None)


def quality(rows, rank_key):
    ranks = [row[rank_key] for row in rows]
    hits = [rank for rank in ranks if rank is not None and rank <= 5]
    return {
        "queries": len(ranks),
        "target_in_top30": sum(rank is not None for rank in ranks),
        "hit_at_1": ranks.count(1),
        "hit_at_5": len(hits),
        "mrr_at_5": sum(1 / rank for rank in hits) / len(ranks),
    }


def runtime_memory(use_cuda):
    return {
        "rss_bytes": psutil.Process().memory_info().rss,
        "peak_rss_bytes": resource.getrusage(resource.RUSAGE_SELF).ru_maxrss * 1024,
        "cuda_peak_allocated_bytes": torch.cuda.max_memory_allocated() if use_cuda else 0,
        "cuda_peak_reserved_bytes": torch.cuda.max_memory_reserved() if use_cuda else 0,
    }


def rerank(model, text, hits, document_vectors, synchronize):
    synchronize()
    started = time.perf_counter()
    query_vectors = model.encode_query(text, show_progress_bar=False)
    synchronize()
    encoded = time.perf_counter()
    vectors = [document_vectors[hit["id"]] for hit in hits]
    values = model.similarity(query_vectors, vectors).reshape(-1).tolist()
    if len(values) != len(hits) or not all(math.isfinite(value) for value in values):
        raise ValueError("Invalid MaxSim output")
    order = sorted(range(len(hits)), key=lambda offset: (-values[offset], offset))
    result = [
        {
            **hits[offset],
            "dense_rank": offset + 1,
            "colbert_rank": rank,
            "colbert_score": values[offset],
        }
        for rank, offset in enumerate(order, 1)
    ]
    synchronize()
    finished = time.perf_counter()
    return result, (finished - started) * 1_000, (encoded - started) * 1_000


def run(directory, model_path, device):
    documents, plan, candidates = load_inputs(directory)
    if sha256(model_path / "model.safetensors") != WEIGHTS_SHA256:
        raise ValueError("Model weights differ from the pinned Jina revision")
    if (model_path / "model-revision.txt").read_text(encoding="utf-8-sig").strip() != MODEL_REVISION:
        raise ValueError("Unexpected model revision")

    use_cuda = device == "cuda"
    if use_cuda:
        if not torch.cuda.is_available():
            raise RuntimeError("CUDA is unavailable. Re-run with --device cpu.")
        torch.cuda.synchronize()
        torch.cuda.reset_peak_memory_stats()

    def synchronize():
        if use_cuda:
            torch.cuda.synchronize()

    torch.set_num_threads(4)
    before_load = runtime_memory(use_cuda)
    started = time.perf_counter()
    model = MultiVectorEncoder(
        str(model_path),
        device=device,
        trust_remote_code=True,
        local_files_only=True,
        model_kwargs={"code_revision": CODE_REVISION, "dtype": "float32"},
        config_kwargs={"use_flash_attn": False, "code_revision": CODE_REVISION},
    )
    model.eval()
    synchronize()
    model_load_seconds = time.perf_counter() - started
    after_load = runtime_memory(use_cuda)

    projections = [module for module in model.modules()
                   if isinstance(module, torch.nn.Linear) and tuple(module.weight.shape) == (128, 1024)]
    with safe_open(model_path / "model.safetensors", framework="pt", device="cpu") as checkpoint:
        stored_projection = checkpoint.get_tensor("linear.weight").float()
    if len(projections) != 1 or not torch.equal(projections[0].weight.detach().cpu().float(), stored_projection):
        raise ValueError("The trained Jina ColBERT token projection was not loaded")

    document_ids = sorted({hit["id"] for row in candidates for hit in row["results"]})
    started = time.perf_counter()
    vectors = model.encode_document(
        [documents[artwork_id] for artwork_id in document_ids],
        batch_size=8,
        show_progress_bar=False,
    )
    synchronize()
    document_seconds = time.perf_counter() - started
    document_vectors = dict(zip(document_ids, vectors, strict=True))
    if any(vector.ndim != 2 or vector.shape[1] != 128 for vector in document_vectors.values()):
        raise ValueError("Expected 128-dimensional Jina token vectors")

    # One warm-up query is excluded from the latency distribution.
    rerank(model, plan[0]["text"], candidates[0]["results"], document_vectors, synchronize)
    results = []
    for query, row in zip(plan, candidates):
        hits, elapsed, encode_ms = rerank(model, query["text"], row["results"], document_vectors, synchronize)
        results.append({
            **query,
            "dense_rank": target_rank(row["results"], query["relevant"]),
            "colbert_rank": target_rank(hits, query["relevant"]),
            "dense_latency_ms": row["latency_ms"],
            "rerank_ms": elapsed,
            "query_encode_ms": encode_ms,
            "results": hits,
        })

    groups = {
        "all": results,
        "ru": [row for row in results if row["lang"] == "ru"],
        "en": [row for row in results if row["lang"] == "en"],
    }
    elapsed = sorted(row["rerank_ms"] for row in results)
    return {
        "model": {
            "id": MODEL_ID,
            "revision": MODEL_REVISION,
            "code_revision": CODE_REVISION,
            "weights_sha256": WEIGHTS_SHA256,
            "device": torch.cuda.get_device_name() if use_cuda else "cpu",
            "dtype": str(next(model.parameters()).dtype),
            "similarity": str(model.similarity_fn_name),
            "prompts": model.prompts,
            "dependencies": {
                name: importlib.metadata.version(name)
                for name in ("torch", "sentence-transformers", "transformers", "tokenizers")
            },
        },
        "counts": {
            "corpus": len(documents),
            "documents_encoded": len(document_ids),
            "candidates_per_query": CANDIDATE_LIMIT,
            "document_tokens_min": min(vector.shape[0] for vector in document_vectors.values()),
            "document_tokens_max": max(vector.shape[0] for vector in document_vectors.values()),
        },
        "metrics": {
            name: {"dense": quality(rows, "dense_rank"), "colbert": quality(rows, "colbert_rank")}
            for name, rows in groups.items()
        },
        "timing": {
            "model_load_seconds": model_load_seconds,
            "documents_seconds": document_seconds,
            "rerank_mean_ms": statistics.mean(elapsed),
            "rerank_median_ms": statistics.median(elapsed),
            "rerank_p95_ms": elapsed[math.ceil(0.95 * len(elapsed)) - 1],
            "rerank_min_ms": elapsed[0],
            "rerank_max_ms": elapsed[-1],
            "query_encode_mean_ms": statistics.mean(row["query_encode_ms"] for row in results),
        },
        "memory": {"before_load": before_load, "after_load": after_load, "final": runtime_memory(use_cuda)},
        "queries": results,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("directory", type=Path, help="Frozen inputs directory")
    parser.add_argument("--model", type=Path, required=True, help="Local Jina model directory")
    parser.add_argument("--device", choices=("cuda", "cpu"), default="cuda")
    args = parser.parse_args()
    print(json.dumps(run(args.directory, args.model, args.device), ensure_ascii=False, allow_nan=False))


if __name__ == "__main__":
    main()
