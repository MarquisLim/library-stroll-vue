import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const METHODS = ['A', 'B', 'C', 'D'];
const CONTRASTS = { 'B-A': ['B', 'A'], 'C-A': ['C', 'A'], 'D-C': ['D', 'C'], 'D-B': ['D', 'B'] };
const QUALITY_KEYS = ['ndcg5', 'ndcg10', 'recall30_grade1', 'hit1_grade1', 'hit5_grade1', 'mrr5_grade1',
    'recall30_grade2', 'hit1_grade2', 'hit5_grade2', 'mrr5_grade2'];
const read = path => JSON.parse(readFileSync(path, 'utf8'));
const sha256 = data => createHash('sha256').update(data).digest('hex');
const mean = values => values.reduce((total, value) => total + value, 0) / values.length;
const pair = (query, artwork) => `${query}:${artwork}`;

function timing(values) {
    if (!values.length) return null;
    const sorted = [...values].sort((a, b) => a - b);
    return { samples: values.length, mean_ms: mean(values), p95_ms: sorted[Math.ceil(0.95 * values.length) - 1] };
}

function dcg(grades, k) {
    return grades.slice(0, k).reduce((total, grade, i) => total + (2 ** grade - 1) / Math.log2(i + 2), 0);
}

function evaluate(ids, judgments) {
    const grades = ids.map(id => judgments.get(id) ?? 0);
    const ideal = [...judgments.values()].sort((a, b) => b - a);
    const normalized = k => dcg(ideal, k) ? dcg(grades, k) / dcg(ideal, k) : 0;
    const quality = { ndcg5: normalized(5), ndcg10: normalized(10) };
    for (const threshold of [1, 2]) {
        const relevant = ideal.filter(grade => grade >= threshold).length;
        const hitRank = grades.slice(0, 5).findIndex(grade => grade >= threshold) + 1;
        quality[`recall30_grade${threshold}`] = relevant ? grades.slice(0, 30).filter(grade => grade >= threshold).length / relevant : 0;
        quality[`hit1_grade${threshold}`] = Number(hitRank === 1);
        quality[`hit5_grade${threshold}`] = Number(hitRank > 0);
        quality[`mrr5_grade${threshold}`] = hitRank ? 1 / hitRank : 0;
    }
    return quality;
}

function coverage(ids, judgments, k) {
    const top = ids.slice(0, k);
    return { unjudged: top.filter(id => !judgments.has(id)).length, retrieved: top.length };
}

function selfTest() {
    const judgments = new Map([[1, 2], [2, 1], [4, 0], [5, 2]]);
    const ids = [9, 2, 1, 4];
    const metrics = evaluate(ids, judgments);
    const expected = (1 / Math.log2(3) + 3 / 2) / (3 + 3 / Math.log2(3) + 1 / 2);
    assert.ok(Math.abs(metrics.ndcg5 - expected) < 1e-12);
    assert.equal(metrics.ndcg10, metrics.ndcg5);
    assert.equal(metrics.hit1_grade1, 0);
    assert.equal(metrics.hit5_grade2, 1);
    assert.equal(metrics.mrr5_grade1, 1 / 2);
    assert.equal(metrics.mrr5_grade2, 1 / 3);
    assert.equal(metrics.recall30_grade1, 2 / 3);
    assert.equal(metrics.recall30_grade2, 1 / 2);
    assert.deepEqual(coverage(ids, judgments, 5), { unjudged: 1, retrieved: 4 });
    assert.equal(evaluate([1, 5, 2, 4], judgments).ndcg5, 1);
    const atCutoffs = [...Array.from({ length: 29 }, (_, i) => i + 100), 1, 5];
    assert.equal(evaluate(atCutoffs, judgments).recall30_grade2, 1 / 2);
    assert.equal(evaluate(atCutoffs, judgments).ndcg10, 0);
    assert.equal(evaluate([9], new Map([[4, 0]])).ndcg5, 0);
    assert.deepEqual(timing([5, 2, 1, 3, 4]), { samples: 5, mean_ms: 3, p95_ms: 5 });
}

function loadInputs(root) {
    const hashes = read(join(root, 'checksums.json'));
    for (const [name, expected] of Object.entries(hashes.files)) assert.equal(sha256(readFileSync(join(root, name))), expected, name);
    const plan = read(join(root, 'query-plan.json'));
    const ratings = read(join(root, 'qrels.json'));
    const pending = read(join(root, 'review-needed.json'));
    const images = new Map(read(join(root, 'dataset-images.json')).map(item => [item.artwork_id, item]));
    assert.deepEqual(plan.map(row => row.id), Array.from({ length: 40 }, (_, i) => i + 1));
    assert.equal(plan.filter(row => row.lang === 'ru').length, 20);
    assert.equal(plan.filter(row => row.lang === 'en').length, 20);
    assert.equal(new Set(plan.map(row => row.category)).size, 7);
    assert.equal(images.size, 100);
    assert.equal(ratings.length, 173);
    assert.equal(pending.length, 16);
    const guide = new Map(plan.map(row => [row.id, new Map()]));
    const used = new Set();
    for (const row of [...ratings, ...pending]) {
        const key = pair(row.query_id, row.artwork_id);
        assert.ok(guide.has(row.query_id) && images.has(row.artwork_id) && !used.has(key) && row.reason.trim());
        used.add(key);
    }
    for (const row of ratings) {
        assert.ok([0, 1, 2].includes(row.relevance));
        guide.get(row.query_id).set(row.artwork_id, row.relevance);
    }
    for (const row of pending) {
        assert.ok(!Object.hasOwn(row, 'relevance') && row.possible_relevance.length === 2
            && row.possible_relevance.every(grade => [0, 1, 2].includes(grade)));
    }
    for (const judgments of guide.values()) {
        assert.deepEqual([...new Set(judgments.values())].sort(), [0, 1, 2]);
    }
    return { hashes, plan, ratings, pending, images, guide };
}

function summarize(queries) {
    const output = { queries: queries.length, methods: {}, latency_increase: {} };
    for (const method of METHODS) {
        const samples = queries.map(query => query.methods[method]);
        const quality = Object.fromEntries(QUALITY_KEYS.map(key => [key, mean(samples.map(row => row.quality[key]))]));
        const unjudged = {};
        for (const k of [5, 10]) {
            const missing = samples.reduce((sum, row) => sum + row.unjudged[k].unjudged, 0);
            const retrieved = samples.reduce((sum, row) => sum + row.unjudged[k].retrieved, 0);
            unjudged[k] = { unjudged: missing, retrieved, fraction: missing / retrieved };
        }
        output.methods[method] = {
            quality, unjudged, elapsed: timing(samples.map(row => row.elapsed_ms)), dense_http: timing(samples.map(row => row.dense_ms)),
            colbert_http: timing(samples.map(row => row.colbert.elapsed_ms).filter(Number.isFinite)),
            colbert_compute: timing(samples.map(row => row.colbert.server_ms).filter(Number.isFinite)),
        };
    }
    for (const [name, [after, before]] of Object.entries(CONTRASTS)) {
        output.latency_increase[name] = timing(queries.map(row => row.methods[after].elapsed_ms - row.methods[before].elapsed_ms));
    }
    return output;
}

function compareDisputes(queries, guide, pending) {
    const result = Object.fromEntries(Object.keys(CONTRASTS).map(name => [name,
        Object.fromEntries(QUALITY_KEYS.map(key => [key, { min: 0, max: 0 }]))]));
    for (const query of queries) {
        let scenarios = [guide.get(query.id)];
        for (const row of pending.filter(row => row.query_id === query.id)) {
            scenarios = scenarios.flatMap(base => row.possible_relevance.map(grade => new Map(base).set(row.artwork_id, grade)));
        }
        for (const [name, [after, before]] of Object.entries(CONTRASTS)) {
            const deltas = scenarios.map(judgments => {
                const a = evaluate(query.methods[after].ids, judgments);
                const b = evaluate(query.methods[before].ids, judgments);
                return Object.fromEntries(QUALITY_KEYS.map(key => [key, a[key] - b[key]]));
            });
            for (const key of QUALITY_KEYS) {
                result[name][key].min += Math.min(...deltas.map(delta => delta[key])) / queries.length;
                result[name][key].max += Math.max(...deltas.map(delta => delta[key])) / queries.length;
            }
        }
    }
    return result;
}

function main(root, output) {
    const { plan, guide, images, ratings, pending, hashes } = loadInputs(root);
    const ranking = read(join(output, 'rankings.json'));
    assert.equal(ranking.config.plan_sha256, hashes.files['query-plan.json']);
    assert.deepEqual(ranking.queries.map(row => row.id), plan.map(row => row.id));

    const pool = new Map();
    const top5 = new Set();
    const actualErrors = [];
    const results = ranking.queries.map((row, i) => {
        const query = plan[i];
        assert.equal(row.lang, query.lang);
        assert.equal(row.category, query.category);
        const judgments = guide.get(query.id);
        const methods = {};
        for (const method of METHODS) {
            const arm = row.methods[method];
            const ids = arm.results.map(hit => hit.id);
            assert.ok(ids.length >= 50 && ids.length <= 100 && new Set(ids).size === ids.length);
            assert.ok(arm.results.every((hit, position) => images.has(hit.id) && hit.rank === position + 1 && Number.isFinite(hit.score)));
            assert.ok(Number.isFinite(arm.elapsed_ms) && Number.isFinite(arm.dense_ms));
            for (const artwork_id of ids.slice(0, 10)) {
                pool.set(pair(query.id, artwork_id), { query_id: query.id, artwork_id, image_path: images.get(artwork_id).path });
            }
            for (const id of ids.slice(0, 5)) top5.add(pair(query.id, id));
            if (arm.error) actualErrors.push({ query_id: query.id, method, ...arm.error });
            methods[method] = {
                ids, quality: evaluate(ids, judgments), unjudged: { 5: coverage(ids, judgments, 5), 10: coverage(ids, judgments, 10) },
                elapsed_ms: arm.elapsed_ms, dense_ms: arm.dense_ms, colbert: arm.colbert,
            };
        }
        const denseTop30 = new Set(methods.A.ids.slice(0, 30));
        const dense_coverage = {};
        for (const threshold of [1, 2]) {
            const relevant = [...judgments].filter(([, grade]) => grade >= threshold).map(([id]) => id);
            dense_coverage[threshold] = { relevant, outside_top30: relevant.filter(id => !denseTop30.has(id)) };
        }
        const deltas = Object.fromEntries(Object.entries(CONTRASTS).map(([name, [a, b]]) => [name,
            Object.fromEntries(QUALITY_KEYS.map(key => [key, methods[a].quality[key] - methods[b].quality[key]]))]));
        return { ...query, methods, dense_coverage, deltas };
    });

    const summary = { all: summarize(results), languages: {}, categories: {} };
    for (const [field, outputField] of [['lang', 'languages'], ['category', 'categories']]) {
        for (const value of new Set(plan.map(query => query[field]))) {
            summary[outputField][value] = summarize(results.filter(query => query[field] === value));
        }
    }
    const reviewPool = [...pool.values()].sort((a, b) => a.query_id - b.query_id || a.artwork_id - b.artwork_id);
    const known = new Set(ratings.map(row => pair(row.query_id, row.artwork_id)));
    const pendingKeys = new Set(pending.map(row => pair(row.query_id, row.artwork_id)));
    const pendingRanks = pending.map(row => {
        const query = results[row.query_id - 1];
        const ranks = Object.fromEntries(METHODS.map(method => [method, query.methods[method].ids.indexOf(row.artwork_id) + 1 || null]));
        return { ...row, ranks };
    });
    const poolStats = {
        top10_pairs: pool.size, top10_judged_by_ai: [...pool.keys()].filter(key => known.has(key)).length,
        top10_unjudged: [...pool.keys()].filter(key => !known.has(key)).length,
        top10_pending: [...pool.keys()].filter(key => pendingKeys.has(key)).length,
        top5_pairs: top5.size, top5_unjudged: [...top5].filter(key => !known.has(key)).length,
        top10_plus_prior_labels_and_disputes: new Set([...pool.keys(), ...known, ...pendingKeys]).size,
    };
    const diagnostics = {
        errors: actualErrors, pool: poolStats, disputes: pendingRanks,
        dispute_delta_bounds: compareDisputes(results, guide, pending),
        dense_without_top30_hit: Object.fromEntries([1, 2].map(threshold => [threshold,
            results.filter(row => row.dense_coverage[threshold].outside_top30.length === row.dense_coverage[threshold].relevant.length).map(row => row.id)])),
    };
    for (const [name, data] of Object.entries({
        'summary.json': summary, 'metrics-by-query.json': results, 'review-pool.json': reviewPool, 'diagnostics.json': diagnostics,
    })) writeFileSync(join(output, name), JSON.stringify(data, null, 2) + '\n');
    console.log(JSON.stringify({ queries: results.length, pool: poolStats, errors: actualErrors, dense_without_top30_hit: diagnostics.dense_without_top30_hit }));
}

selfTest();
if (process.argv[2] === '--self-test') {
    console.log('Synthetic graded metrics, cutoffs, timing and unjudged coverage passed.');
} else if (process.argv[2] === '--check' && process.argv.length === 4) {
    loadInputs(process.argv[3]);
    console.log('Frozen plan and judgments verified.');
} else if (process.argv.length === 4) {
    main(process.argv[2], process.argv[3]);
} else {
    throw new Error('Usage: node independent_search_metrics.mjs INPUT_DIR OUTPUT_DIR | --check INPUT_DIR | --self-test');
}
