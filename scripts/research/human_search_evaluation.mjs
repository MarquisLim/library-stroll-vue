import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadQueue } from './relevance-review/server.mjs';

const ROOT = fileURLToPath(new URL('../../storage/app/ai-stage-04-08/', import.meta.url));
const PREVIOUS = join(ROOT, '4.8a-2');
const OUTPUT = join(ROOT, '4.8a-4');
const REVIEWER = 'anna-01';
const QUEUE_SHA256 = 'ad325a5c567d4936e3f1988f0cd370679e14365419ee3433599cb578dfdb8a61';
const METHODS = ['A', 'B', 'C', 'D'];
const CONTRASTS = { 'B-A': ['B', 'A'], 'D-C': ['D', 'C'], 'C-A': ['C', 'A'], 'D-B': ['D', 'B'] };
const BOOTSTRAP = {
    seed: 4804, replicates: 20000, sample_size: 40, confidence_level: 0.95,
    prng: 'xorshift32', unit: 'paired query', interval: 'percentile', quantile: 'linear interpolation at (n-1)*p',
};
const read = path => JSON.parse(readFileSync(path, 'utf8'));
const hash = path => createHash('sha256').update(readFileSync(path)).digest('hex');
const key = ({ query_id, artwork_id }) => `${query_id}:${artwork_id}`;
const mean = values => values.reduce((total, item) => total + item, 0) / values.length;
const dcg = (grades, k) => grades.slice(0, k).reduce((sum, grade, i) => sum + (2 ** grade - 1) / Math.log2(i + 2), 0);

// Same gains, cutoffs and empty-ideal convention as the frozen 4.8A-2 calculation.
function evaluate(ids, judgments) {
    const grades = ids.map(id => judgments.get(id) ?? 0);
    const ideal = [...judgments.values()].sort((a, b) => b - a);
    const nDCG = k => dcg(ideal, k) ? dcg(grades, k) / dcg(ideal, k) : 0;
    const results = { ndcg5: nDCG(5), ndcg10: nDCG(10) };
    for (const threshold of [1, 2]) {
        const positives = ideal.filter(grade => grade >= threshold).length;
        const rank = grades.slice(0, 5).findIndex(grade => grade >= threshold) + 1;
        results[`recall30_grade${threshold}`] = positives ? grades.slice(0, 30).filter(grade => grade >= threshold).length / positives : 0;
        results[`hit1_grade${threshold}`] = Number(rank === 1);
        results[`hit5_grade${threshold}`] = Number(rank > 0);
        results[`mrr5_grade${threshold}`] = rank ? 1 / rank : 0;
    }
    return results;
}

function percentile(sorted, p) {
    const index = (sorted.length - 1) * p;
    const lo = Math.floor(index);
    const hi = Math.ceil(index);
    return sorted[lo] + (index - lo) * (sorted[hi] - sorted[lo]);
}

function pairedBootstrap(differences, { seed, replicates, confidence_level }) {
    let state = seed >>> 0;
    const draws = [];
    assert.ok(state !== 0 && differences.length > 0 && replicates > 0);
    for (let trial = 0; trial < replicates; trial++) {
        let sum = 0;
        for (let i = 0; i < differences.length; i++) {
            state ^= state << 13;
            state ^= state >>> 17;
            state ^= state << 5;
            const index = Math.floor((state >>> 0) / 2 ** 32 * differences.length);
            sum += differences[index];
        }
        draws.push(sum / differences.length);
    }
    draws.sort((a, b) => a - b);
    const tail = (1 - confidence_level) / 2;
    return { estimate: mean(differences), lower: percentile(draws, tail), upper: percentile(draws, 1 - tail) };
}

function normalize(row) {
    const { query_id, artwork_id, status, relevance, reviewer_id, rated_at } = row;
    assert.equal(reviewer_id, REVIEWER);
    assert.equal(new Date(rated_at).toISOString(), rated_at);
    assert.ok((status === 'rated' && [0, 1, 2].includes(relevance)) || (status === 'unsure' && relevance === null));
    return { query_id, artwork_id, status, relevance, reviewer_id, rated_at };
}

function validateReview(review) {
    assert.equal(review.schema_version, 1);
    assert.equal(review.reviewer_id, REVIEWER);
    assert.equal(review.source, 'manual_ui');
    assert.equal(review.queue_sha256, QUEUE_SHA256);
}

function loadReviews(sessionPath, exportPath, queue) {
    const raw = read(sessionPath);
    validateReview(raw);
    assert.ok(raw.ratings && typeof raw.ratings === 'object' && !Array.isArray(raw.ratings));
    const rows = Object.entries(raw.ratings).map(([position, row]) => {
        assert.ok(/^\d+$/.test(position) && queue.pairs[Number(position)]);
        assert.equal(key(row), key(queue.pairs[Number(position)]));
        assert.equal(row.value, row.status === 'unsure' ? 'unsure' : row.relevance);
        return normalize(row);
    });
    const byPair = new Map(rows.map(row => [key(row), row]));
    assert.equal(rows.length, 607);
    assert.equal(byPair.size, 607);
    const counts = { 0: 0, 1: 0, 2: 0, unsure: 0 };
    for (const row of rows) counts[row.relevance ?? 'unsure']++;
    assert.deepEqual(counts, { 0: 380, 1: 109, 2: 106, unsure: 12 });
    if (exportPath) {
        const exported = read(exportPath);
        validateReview(exported);
        const received = new Map(exported.ratings.map(row => [key(row), normalize(row)]));
        assert.equal(exported.ratings.length, 607);
        assert.deepEqual(received, byPair, 'Attached export differs from the local review.');
    }
    const dates = rows.map(row => row.rated_at).sort();
    return { rows, byPair, counts, first_rated_at: dates[0], last_rated_at: dates.at(-1) };
}

function contrast(after, before) {
    return Object.fromEntries(Object.entries(after).map(([metric, value]) => {
        const absolute = value - before[metric];
        return [metric, { absolute, relative_percent: before[metric] === 0 ? null : 100 * absolute / before[metric] }];
    }));
}

function summarize(rows) {
    const methods = Object.fromEntries(METHODS.map(method => {
        const measures = Object.keys(rows[0].methods[method]).map(metric => [metric, mean(rows.map(row => row.methods[method][metric]))]);
        return [method, Object.fromEntries(measures)];
    }));
    const differences = Object.fromEntries(Object.entries(CONTRASTS).map(([label, [a, b]]) => [label, contrast(methods[a], methods[b])]));
    return { queries: rows.length, methods, differences };
}

function groupedSummary(rows) {
    const splitBy = field => Object.fromEntries([...new Set(rows.map(row => row[field]))].map(value =>
        [value, summarize(rows.filter(row => row[field] === value))]));
    return { all: summarize(rows), languages: splitBy('lang'), categories: splitBy('category') };
}

function scoreRows(plan, ranks, judges) {
    return plan.map(({ id, lang, category }) => ({
        id, lang, category, methods: Object.fromEntries(METHODS.map(method =>
            [method, evaluate(ranks.get(id)[method], judges.get(id))])),
    }));
}

function agreement(ai, human) {
    const matrix = Object.fromEntries([0, 1, 2].map(grade => [grade, { 0: 0, 1: 0, 2: 0, unsure: 0 }]));
    let higher = 0;
    let lower = 0;
    for (const item of ai) {
        const row = human.get(key(item));
        assert.ok(row, 'AI-rated pair is absent from the human queue.');
        matrix[item.relevance][row.relevance ?? 'unsure']++;
        if (row.relevance !== null) {
            higher += Number(row.relevance > item.relevance);
            lower += Number(row.relevance < item.relevance);
        }
    }
    const exact = [0, 1, 2].reduce((sum, grade) => sum + matrix[grade][grade], 0);
    const unsure = [0, 1, 2].reduce((sum, grade) => sum + matrix[grade].unsure, 0);
    assert.equal(exact + unsure + higher + lower, ai.length);
    return { total: ai.length, determinate: ai.length - unsure, exact, different: higher + lower, higher, lower, unsure, matrix };
}

function sensitivity(plan, rankings, judges, uncertain) {
    const grades = [0, 1, 2];
    const constant = Object.fromEntries(grades.map(grade => {
        const guide = new Map([...judges].map(([id, values]) => [id, new Map(values)]));
        for (const row of uncertain) guide.get(row.query_id).set(row.artwork_id, grade);
        return [grade, groupedSummary(scoreRows(plan, rankings, guide))];
    }));
    // Macro-averaged scores and paired differences are sums of query-level terms.
    // Enumerating within a query therefore gives exact aggregate extrema over independent choices.
    const metrics = Object.keys(evaluate([], new Map()));
    const labels = [...METHODS, ...Object.keys(CONTRASTS)];
    const bounds = Object.fromEntries(labels.map(label => [label, Object.fromEntries(metrics.map(metric => [metric, { min: 0, max: 0 }]))]));
    for (const { id } of plan) {
        let cases = [judges.get(id)];
        for (const row of uncertain.filter(row => row.query_id === id)) {
            cases = cases.flatMap(values => grades.map(grade => new Map(values).set(row.artwork_id, grade)));
        }
        const scores = cases.map(values => Object.fromEntries(METHODS.map(method => [method, evaluate(rankings.get(id)[method], values)])));
        for (const [label, byMetric] of Object.entries(bounds)) {
            for (const [metric, range] of Object.entries(byMetric)) {
                const values = scores.map(item => {
                    if (METHODS.includes(label)) return item[label][metric];
                    const [a, b] = CONTRASTS[label];
                    return item[a][metric] - item[b][metric];
                });
                range.min += Math.min(...values) / plan.length;
                range.max += Math.max(...values) / plan.length;
            }
        }
    }
    return { assumptions: 'All unsure pairs take a common value in uniform; independent choices in exact_bounds.', uniform: constant, exact_bounds: bounds };
}

function selfTest() {
    const rows = new Map([[1, 2], [2, 1], [4, 0], [5, 2]]);
    const scores = evaluate([9, 2, 1, 4], rows);
    const expected = (1 / Math.log2(3) + 3 / 2) / (3 + 3 / Math.log2(3) + 1 / 2);
    assert.ok(Math.abs(scores.ndcg5 - expected) < 1e-12);
    assert.equal(scores.recall30_grade1, 2 / 3);
    assert.equal(scores.recall30_grade2, 1 / 2);
    assert.equal(scores.mrr5_grade1, 1 / 2);
    assert.equal(scores.mrr5_grade2, 1 / 3);
    assert.equal(evaluate([1], new Map()).ndcg5, 0);
    assert.deepEqual(pairedBootstrap([0.25, 0.25, 0.25], BOOTSTRAP), { estimate: 0.25, lower: 0.25, upper: 0.25 });
    assert.deepEqual(pairedBootstrap([0, 1], BOOTSTRAP), { estimate: 0.5, lower: 0, upper: 1 });
    assert.equal(percentile([0, 2], 0.25), 0.5);
    assert.equal(contrast({ ndcg5: 0.5 }, { ndcg5: 0 }).ndcg5.relative_percent, null);

    const plan = [{ id: 1, lang: 'en', category: 'example' }];
    const rankings = new Map([[1, { A: [1, 2], B: [2, 1], C: [1, 2], D: [2, 1] }]]);
    const guide = new Map([[1, new Map([[1, 2]])]]);
    const scenarios = sensitivity(plan, rankings, guide, [{ query_id: 1, artwork_id: 2 }]);
    assert.ok(Math.abs(scenarios.exact_bounds['B-A'].ndcg5.min - (1 / Math.log2(3) - 1)) < 1e-12);
    assert.equal(scenarios.exact_bounds['B-A'].ndcg5.max, 0);
    assert.equal(guide.get(1).has(2), false);
}

function run(exportPath) {
    const queue = loadQueue();
    assert.equal(queue.fingerprint, QUEUE_SHA256);
    const manifest = read(join(PREVIOUS, 'checksums.json'));
    for (const name of ['rankings.json', 'summary.json', 'review-pool.json']) {
        assert.equal(hash(join(PREVIOUS, name)), manifest.files[name], name);
    }
    const sessionPath = join(ROOT, '4.8a-3-1', 'human-review', `reviewer-${REVIEWER}.json`);
    const { rows, byPair, counts, first_rated_at, last_rated_at } = loadReviews(sessionPath, exportPath, queue);
    const pool = read(join(PREVIOUS, 'review-pool.json'));
    assert.equal(new Set(pool.map(key)).size, 563);
    assert.ok(pool.every(row => byPair.has(key(row))));
    const sure = rows.filter(row => row.status === 'rated');
    const uncertain = rows.filter(row => row.status === 'unsure');
    const plan = read(join(ROOT, 'query-plan.json'));
    const rankSource = read(join(PREVIOUS, 'rankings.json')).queries;
    assert.deepEqual(rankSource.map(row => row.id), plan.map(row => row.id));
    const rankings = new Map(rankSource.map(row => [row.id, Object.fromEntries(METHODS.map(method =>
        [method, row.methods[method].results.map(hit => hit.id)]))]));
    const judges = new Map(plan.map(query => [query.id, new Map()]));
    for (const row of sure) judges.get(row.query_id).set(row.artwork_id, row.relevance);
    const results = scoreRows(plan, rankings, judges);
    const summary = groupedSummary(results);
    const baseline = read(join(PREVIOUS, 'summary.json')).all.methods;
    const priorComparison = Object.fromEntries(METHODS.map(method => {
        const ai = baseline[method].quality;
        const human = summary.all.methods[method];
        return [method, { ai, human, human_minus_ai: contrast(human, ai) }];
    }));
    const pendingRanks = uncertain.map(row => ({
        ...row, ranks: Object.fromEntries(METHODS.map(method =>
            [method, rankings.get(row.query_id)[method].indexOf(row.artwork_id) + 1 || null])),
    }));
    for (const row of results) {
        row.differences = Object.fromEntries(Object.entries(CONTRASTS).map(([label, [a, b]]) => [label, contrast(row.methods[a], row.methods[b])]));
        row.known_positive_counts = {
            grade1: [...judges.get(row.id).values()].filter(grade => grade >= 1).length,
            grade2: [...judges.get(row.id).values()].filter(grade => grade === 2).length,
        };
    }
    const inputs = {
        human_session_sha256: hash(sessionPath), query_plan_sha256: hash(join(ROOT, 'query-plan.json')),
        ai_qrels_sha256: hash(join(ROOT, 'qrels.json')), old_checksums_sha256: hash(join(PREVIOUS, 'checksums.json')),
        rankings_sha256: manifest.files['rankings.json'], ai_summary_sha256: manifest.files['summary.json'],
        ...(exportPath ? { attached_export_sha256: hash(exportPath) } : {}),
    };
    const grades = {
        schema_version: 1, source: 'manual_ui', reviewer_id: REVIEWER, queue_sha256: QUEUE_SHA256,
        first_rated_at, last_rated_at, counts, inputs, ratings: sure, unsure: uncertain,
    };
    const agreementReport = agreement(read(join(ROOT, 'qrels.json')), byPair);
    const intervals = Object.fromEntries(['B-A', 'D-C'].map(label => [label,
        pairedBootstrap(results.map(row => row.differences[label].ndcg5.absolute), BOOTSTRAP)]));
    const coverage = {
        reviewed_pairs: rows.length, determinate_pairs: sure.length, unsure_pairs: uncertain.length,
        top10_pool_pairs: pool.length, top10_pool_reviewed: pool.filter(row => byPair.has(key(row))).length,
        top10_pool_unsure: pool.filter(row => byPair.get(key(row)).status === 'unsure').length,
        unsure_positions: pendingRanks,
    };
    const output = {
        'human-qrels.json': grades, 'summary.json': summary, 'metrics-by-query.json': results,
        'ai-comparison.json': { methods: priorComparison, agreement: agreementReport },
        'sensitivity.json': { coverage, ...sensitivity(plan, rankings, judges, uncertain) },
        'bootstrap.json': { parameters: BOOTSTRAP, contrasts: intervals },
    };
    mkdirSync(OUTPUT, { recursive: true });
    for (const [name, value] of Object.entries(output)) writeFileSync(join(OUTPUT, name), JSON.stringify(value, null, 2) + '\n');
    const hashes = {
        stage: '4.8A-4', queue_sha256: QUEUE_SHA256, inputs, node: process.version,
        script_sha256: hash(fileURLToPath(import.meta.url)),
        files: Object.fromEntries(Object.keys(output).map(name => [name, hash(join(OUTPUT, name))])),
    };
    writeFileSync(join(OUTPUT, 'checksums.json'), JSON.stringify(hashes, null, 2) + '\n');
    console.log(JSON.stringify({ queries: results.length, counts, reviewed_top10: coverage.top10_pool_reviewed, agreement: agreementReport, intervals }));
}

selfTest();
if (process.argv[2] === '--self-test' && process.argv.length === 3) {
    console.log('Graded metrics, percentiles and paired bootstrap passed synthetic checks.');
} else if (process.argv.length <= 3) {
    run(process.argv[2]);
} else {
    throw new Error('Usage: node human_search_evaluation.mjs [review-export.json | --self-test]');
}
