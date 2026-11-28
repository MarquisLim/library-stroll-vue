import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const [split, dir = 'storage/app/ai-stage-05-04/5.4-1d-1', humanFile] = process.argv.slice(2);
assert(['development', 'evaluation', 'regression'].includes(split));
assert(!humanFile || split !== 'development');
const parse = bytes => JSON.parse(bytes.toString('utf8').replace(/^\uFEFF/, ''));
const read = name => parse(readFileSync(join(dir, name + '.json')));
const write = (name, data) => writeFileSync(join(dir, name + '.json'), JSON.stringify(data, null, 2) + '\n');
const bytesHash = bytes => createHash('sha256').update(bytes).digest('hex');
const { rows, depths, freeze } = read('runs-' + split);
assert.deepEqual(freeze, read('experiment-freeze'));
const metricsProtocol = new URL('./assistant-colbert-protocol.json', import.meta.url);
assert.equal(bytesHash(readFileSync(metricsProtocol)), freeze.protocol);
const { review_depth: reviewDepth } = parse(readFileSync(metricsProtocol));
const modes = ['A', 'B', 'C', ...depths.flatMap(depth => ['D', 'E', 'F'].map(name => name + depth))];

const isNumeric = Number.isFinite;
const sum = values => values.reduce((total, value) => total + value, 0);
const mean = values => values.length ? sum(values) / values.length : null;
const quantile = (values, q) => {
    if (!values.length) return null;
    const scores = [...values].sort((a, b) => a - b);
    const index = (scores.length - 1) * q;
    return scores[Math.floor(index)] + (index % 1) * (scores[Math.ceil(index)] - scores[Math.floor(index)]);
};
const times = values => ({ n: values.length, p50: quantile(values, 0.5), p95: quantile(values, 0.95) });
const at = (ids, limit) => ids.slice(0, limit);
const dcg = grades => sum(grades.map((grade, i) => grade == null ? 0 : (2 ** grade - 1) / Math.log2(i + 2)));
const pair = (query, id) => query + ':' + id;

let provenance = split === 'regression' ? 'preliminary_5.4-1c_image_review' : 'predeclared_preliminary_examples';
const judgments = new Map();
if (humanFile) {
    const queueBytes = readFileSync(join(dir, 'review-queue-' + split + '.json'));
    const queue = new Set(parse(queueBytes).map(row => pair(row.query_id, row.artwork_id)));
    const file = parse(readFileSync(humanFile));
    assert(file.source === 'manual_ui' && file.queue_sha256 === bytesHash(queueBytes));
    for (const row of file.ratings) {
        const key = pair(row.query_id, row.artwork_id);
        assert(queue.has(key) && !judgments.has(key) && [0, 1, 2, null].includes(row.relevance));
        assert.equal(row.status, row.relevance === null ? 'unsure' : 'rated');
        judgments.set(key, row.relevance);
    }
    provenance = 'human_manual_ui_only';
}

let priorPool;
let priorLabels;
if (split === 'regression' && !humanFile) {
    priorPool = parse(readFileSync(join(dir, '../5.4-1c/pool-evaluation.json')));
    priorLabels = parse(readFileSync(new URL('./assistant-hybrid-judgments.json', import.meta.url)));
}

const pool = read('pool-' + split);
const measured = rows.map(row => {
    const grades = new Map();
    if (humanFile) {
        for (const id of pool[row.id]) {
            const key = pair(row.id, id);
            if (judgments.has(key)) grades.set(id, judgments.get(key));
        }
    } else {
        const oldId = row.id.slice(1);
        if (priorPool) {
            const unknown = new Set(priorLabels[oldId].unknown);
            for (const id of priorPool[oldId]) grades.set(id, unknown.has(id) ? null : 0);
        }
        for (const [level, examples] of [[0, row.zero], [1, row.part], [2, row.full]]) {
            for (const id of examples) grades.set(id, level);
        }
    }
    const ideal = [...grades.values()].filter(isNumeric).sort((a, b) => b - a);
    const full = new Set([...grades].filter(([, grade]) => grade === 2).map(([id]) => id));
    const ndcg = (ids, k) => dcg(at(ideal, k)) ? dcg(at(ids, k).map(id => grades.get(id))) / dcg(at(ideal, k)) : null;
    const count = (ids, k, relevant = full) => at(ids, k).filter(id => relevant.has(id)).length;
    const relevant = new Set([...grades].filter(([, grade]) => grade > 0).map(([id]) => id));
    const byMode = Object.fromEntries(modes.map(mode => {
        const runs = row.runs[mode];
        const ids = runs[0].ids;
        assert.equal(new Set(ids).size, ids.length);
        for (const id of at(ids, reviewDepth)) assert(pool[row.id].includes(id));
        for (const run of runs) assert.deepEqual(run.ids, ids);
        const position = at(ids, 5).findIndex(id => full.has(id));
        const recall = k => full.size ? count(ids, k) / full.size : null;
        return [mode, {
            ndcg5: ndcg(ids, 5), ndcg10: ndcg(ids, 10),
            hit1: full.size ? Number(count(ids, 1) > 0) : null,
            hit5: full.size ? Number(position !== -1) : null,
            mrr5: full.size ? (position === -1 ? 0 : 1 / (position + 1)) : null,
            recall5: recall(5), recall10: recall(10), recall20: recall(20), recall50: recall(50),
            full_in_rag: count(ids, 5), full_at_6_to_20: count(ids, 20) - count(ids, 5),
            full_at_21_to_50: count(ids, 50) - count(ids, 20), full_not_in_top50: full.size - count(ids, 50),
            relevant_at_6_to_50: count(ids, 50, relevant) - count(ids, 5, relevant),
            top5: at(ids, 5).map(id => [id, grades.get(id) ?? null]),
            coverage: Object.fromEntries([5, 10, 20, 50].map(k => {
                const sample = at(ids, k);
                return [k, { known: sample.filter(id => isNumeric(grades.get(id))).length, returned: sample.length }];
            })),
            retrieval_ms: runs.map(run => run.ms),
            rerank_ms: runs.flatMap(run => run.rerank ? [run.rerank.ms] : []),
            model_ms: runs.flatMap(run => isNumeric(run.rerank?.service_ms) ? [run.rerank.service_ms] : []),
            fallbacks: runs.flatMap(run => run.rerank?.fallback_reason ? [run.rerank.fallback_reason] : []),
        }];
    }));
    return { id: row.id, lang: row.lang, category: row.category, query: row.query, labels: Object.fromEntries(grades), byMode };
});

if (split === 'development') {
    // The locked objective uses every development query, with a missed known target contributing zero.
    const options = depths.map(depth => ({
        depth, objective: mean(measured.map(row => row.byMode['F' + depth].mrr5 ?? 0)),
        latency: times(measured.flatMap(row => row.byMode['F' + depth].rerank_ms)),
    }));
    assert(measured.every(row => depths.every(k => ['D', 'E', 'F'].every(m => !row.byMode[m + k].fallbacks.length))));
    const [choice] = [...options].sort((a, b) => b.objective - a.objective || a.depth - b.depth);
    write('depth-lock', { depth: choice.depth, options, development_sha256: bytesHash(readFileSync(join(dir, 'runs-development.json'))) });
}

const keys = ['ndcg5', 'ndcg10', 'hit1', 'hit5', 'mrr5', 'recall5', 'recall10', 'recall20', 'recall50'];
const totals = ['full_in_rag', 'full_at_6_to_20', 'full_at_21_to_50', 'full_not_in_top50', 'relevant_at_6_to_50'];
const groups = { all: measured, ru: measured.filter(row => row.lang === 'ru'), en: measured.filter(row => row.lang === 'en') };
const summaries = Object.fromEntries(Object.entries(groups).map(([lang, group]) => [lang, Object.fromEntries(modes.map(mode => {
    const data = group.map(row => row.byMode[mode]);
    return [mode, {
        queries: group.length, queries_with_known_full: data.filter(row => row.recall5 !== null).length,
        quality: Object.fromEntries(keys.map(key => [key, mean(data.map(row => row[key]).filter(isNumeric))])),
        counts: Object.fromEntries(totals.map(key => [key, sum(data.map(row => row[key]))])),
        latency: Object.fromEntries(['retrieval_ms', 'rerank_ms', 'model_ms'].map(key => [key, times(data.flatMap(row => row[key]))])),
        coverage: Object.fromEntries([5, 10, 20, 50].map(k => [k, {
            known: sum(data.map(row => row.coverage[k].known)), returned: sum(data.map(row => row.coverage[k].returned)),
        }])),
        fallbacks: data.flatMap(row => row.fallbacks).reduce((counts, reason) => {
            counts[reason] = (counts[reason] ?? 0) + 1;
            return counts;
        }, {}),
    }];
}))]));

function bootstrap(diffs) {
    if (!diffs.length) return [null, null];
    let seed = 54101;
    const random = () => {
        seed ^= seed << 13;
        seed ^= seed >>> 17;
        seed ^= seed << 5;
        return seed >>> 0;
    };
    const samples = Array.from({ length: 10000 }, () => mean(diffs.map(() => diffs[random() % diffs.length])));
    return [quantile(samples, 0.025), quantile(samples, 0.975)];
}
const comparisons = depths.flatMap(k => [['A', 'D' + k], ['C', 'E' + k], ['C', 'F' + k], ['E' + k, 'F' + k]])
    .concat([['B', 'C']]);
const paired = Object.fromEntries(comparisons.map(([base, next]) => [next + '-' + base, Object.fromEntries(keys.map(key => {
    const comparisons = measured.filter(row => isNumeric(row.byMode[base][key]) && isNumeric(row.byMode[next][key]))
        .map(row => ({ id: row.id, diff: row.byMode[next][key] - row.byMode[base][key] }));
    const diffs = comparisons.map(row => row.diff);
    return [key, { n: diffs.length, delta: mean(diffs), ci95: bootstrap(diffs),
        improved: comparisons.filter(row => row.diff > 1e-12).map(row => row.id),
        worsened: comparisons.filter(row => row.diff < -1e-12).map(row => row.id) }];
}))]));
write('metrics-' + split + (humanFile ? '-human' : ''), { provenance, depths, summaries, paired, measured });

if (split !== 'development' && !humanFile) {
    for (const mode of modes) {
        write('control-' + split + '-' + mode[0], { mode, depths, freeze,
            queries: rows.map(({ runs, ...row }) => ({ ...row, runs: runs[mode] })) });
    }
}
