import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const [split, output = 'storage/app/ai-stage-05-04/5.4-1d-2'] = process.argv.slice(2);
const read = path => JSON.parse(readFileSync(path, 'utf8').replace(/^\uFEFF/, ''));
const save = (name, data) => writeFileSync(join(output, name + '.json'), JSON.stringify(data, null, 2) + '\n');
const path = name => join(output, name + '.json');
const hash = path => createHash('sha256').update(readFileSync(path)).digest('hex');
const run = read(path('runs-' + split));
assert.equal(run.split, split);
assert.deepEqual(run.freeze, read(path('experiment-freeze')));
assert.deepEqual(run.protocol, read(path('ranking-freeze')));
const previous = split === 'regression-1c' ? read(join(output, '../5.4-1c/metrics.json')) : null;
const positive = x => x > 0;
const sum = values => values.reduce((total, value) => total + value, 0);
const mean = values => values.length ? sum(values) / values.length : null;
const quantile = (values, p) => {
    if (!values.length) return null;
    const sorted = [...values].sort((a, b) => a - b);
    const index = (sorted.length - 1) * p;
    return sorted[Math.floor(index)] + (index % 1) * (sorted[Math.ceil(index)] - sorted[Math.floor(index)]);
};
const times = values => ({ n: values.length, p50: quantile(values, 0.5), p95: quantile(values, 0.95) });
const dcg = values => sum(values.map((grade, index) => grade == null ? 0 : (2 ** grade - 1) / Math.log2(index + 2)));
const depths = [5, 10, 20, 50];
const metrics = ['ndcg5', 'ndcg10', 'hit1', 'hit5', 'mrr5', ...depths.map(k => 'recall' + k)];
const pairs = { E: 'A', F: 'B', G: 'D' };
const labels = row => previous ? new Map(Object.entries(previous.rows.find(old => old.id === row.id).grades)
    .map(([id, grade]) => [Number(id), grade])) : new Map(
    [['zero', 0], ['partial', 1], ['full', 2]].flatMap(([key, grade]) => row.labels[key].map(id => [id, grade])));

function ranking(row, values, baseline) {
    const ids = values[0].ids;
    assert.equal(new Set(ids).size, ids.length);
    for (const result of values) assert.deepEqual(result.ids, ids);
    const grades = labels(row);
    const known = [...grades.values()].filter(Number.isFinite);
    const ideal = known.filter(grade => positive(grade)).sort((a, b) => b - a);
    const full = [...grades].filter(([, grade]) => grade === 2).map(([id]) => id);
    const first = ids.slice(0, 5).findIndex(id => grades.get(id) === 2);
    const ndcg = k => ideal.length ? dcg(ids.slice(0, k).map(id => grades.get(id))) / dcg(ideal.slice(0, k)) : null;
    const countBefore = baseline ? new Map(baseline[0].ids.map((id, i) => [id, i])) : null;
    const promotions = { full_entered_rag: [], full_left_rag: [], full_downgraded: [] };
    if (countBefore) {
        assert.deepEqual(new Set(ids), new Set(baseline[0].ids));
        for (const id of full) {
            const before = countBefore.get(id);
            if (before === undefined) continue;
            const after = ids.indexOf(id);
            if (before >= 5 && after < 5) promotions.full_entered_rag.push(id);
            if (before < 5 && after >= 5) promotions.full_left_rag.push(id);
            if (after > before) promotions.full_downgraded.push(id);
        }
    }
    const head = ids.slice(0, 5).map(id => grades.has(id) ? grades.get(id) : undefined);
    const distribution = { full: head.filter(x => x === 2).length, partial: head.filter(x => x === 1).length,
        zero: head.filter(x => x === 0).length, ambiguous: head.filter(x => x === null).length,
        unjudged: head.filter(x => x === undefined).length };
    const coverage = Object.fromEntries(depths.map(k => [k, {
        judged: ids.slice(0, k).filter(id => Number.isFinite(grades.get(id))).length, returned: Math.min(k, ids.length),
    }]));
    const scores = { ndcg5: ndcg(5), ndcg10: ndcg(10),
        hit1: full.length ? Number(first === 0) : null, hit5: full.length ? Number(first >= 0) : null,
        mrr5: full.length ? (first >= 0 ? 1 / (first + 1) : 0) : null,
        ...Object.fromEntries(depths.map(k => ['recall' + k,
            full.length ? ids.slice(0, k).filter(id => grades.get(id) === 2).length / full.length : null])) };
    return { scores, promotions, coverage, distribution, latency: values.map((r, i) => (baseline?.[i].ms ?? 0) + r.ms),
        constraint_ms: baseline ? values.map(r => r.ms) : [],
        colbert_ms: values.map(r => r.colbert_ms).filter(Number.isFinite),
        fallbacks: values.map(r => r.fallback).filter(Boolean) };
}

const variants = Object.keys(run.rows[0].trials);
const options = Object.keys(run.rows[0].trials[variants[0]]);
const rowsFor = (method, option) => run.rows.map(row => {
    const measured = Object.fromEntries(Object.entries(row.baselines).map(([mode, values]) => [mode, ranking(row, values)]));
    for (const [mode, source] of Object.entries(pairs)) {
        const baseline = row.baselines[source];
        const m = ranking(row, row.trials[method][option][mode], baseline);
        if (method === 'e2e') m.latency = m.latency.map(value => value + row.extraction.ms);
        measured[mode] = m;
    }
    return { id: row.id, language: row.language, ...measured };
});

if (split === 'development') {
    const explored = options.map(option => {
        const [depth, strength] = option.split('/').map(Number);
        const rows = rowsFor('oracle', option);
        const objective = mean(rows.flatMap(row => ['E', 'F', 'G'].map(mode => row[mode].scores.ndcg5)).filter(Number.isFinite));
        return { depth, strength, objective, latency: times(rows.flatMap(row => Object.keys(pairs).flatMap(mode => row[mode].constraint_ms))) };
    });
    const [selected] = [...explored].sort((a, b) => b.objective - a.objective || a.depth - b.depth || a.strength - b.strength);
    save('ranking-lock', { ...selected, alternatives: explored, development_sha256: hash(path('runs-development')),
        metrics_sha256: hash(new URL(import.meta.url)) });
}

function bootstrap(diffs) {
    if (!diffs.length) return [null, null];
    let seed = 54102;
    const rand = () => {
        seed ^= seed << 13;
        seed ^= seed >>> 17;
        seed ^= seed << 5;
        return seed >>> 0;
    };
    const draws = Array.from({ length: 10000 }, () => mean(diffs.map(() => diffs[rand() % diffs.length])));
    return [quantile(draws, 0.025), quantile(draws, 0.975)];
}

const tally = values => values.reduce((all, next) => ({ ...all, [next]: (all[next] ?? 0) + 1 }), {});
const compare = (group, mode, base) => Object.fromEntries(metrics.map(metric => {
    const observed = group.map(row => [row.id, row[mode].scores[metric], row[base].scores[metric]])
        .filter(([, newer, old]) => Number.isFinite(newer) && Number.isFinite(old));
    const diffs = observed.map(([, newer, old]) => newer - old);
    return [metric, { n: diffs.length, delta: mean(diffs), ci95: bootstrap(diffs),
        improved: observed.filter((_, i) => diffs[i] > 1e-12).map(([id]) => id),
        worsened: observed.filter((_, i) => diffs[i] < -1e-12).map(([id]) => id) }];
}));
const optionsToReport = split === 'development' ? [options.find(option => {
    const lock = read(path('ranking-lock'));
    return option === lock.depth + '/' + lock.strength;
})] : options;

function summarize(rows) {
    const groups = { all: rows, ru: rows.filter(row => row.language === 'ru'), en: rows.filter(row => row.language === 'en') };
    return Object.fromEntries(Object.entries(groups).map(([lang, group]) => [lang, Object.fromEntries([...'ABCDEFG'].map(mode => {
        const results = group.map(row => row[mode]);
        const combine = key => Object.fromEntries(Object.keys(results[0][key]).map(field => [
            field, sum(results.map(row => Array.isArray(row[key][field]) ? row[key][field].length : row[key][field])),
        ]));
        return [mode, {
            queries: results.length, n_known_full: results.filter(r => r.scores.hit5 !== null).length,
            scores: Object.fromEntries(metrics.map(key => [key, mean(results.map(row => row.scores[key]).filter(Number.isFinite))])),
            coverage: Object.fromEntries(depths.map(k => [k, { judged: sum(results.map(row => row.coverage[k].judged)),
                returned: sum(results.map(row => row.coverage[k].returned)) }])),
            distribution: combine('distribution'), promotions: combine('promotions'),
            latency_ms: times(results.flatMap(row => row.latency)), constraint_ms: times(results.flatMap(row => row.constraint_ms)),
            colbert_ms: times(results.flatMap(row => row.colbert_ms)),
            fallbacks: tally(results.flatMap(row => row.fallbacks)),
        }];
    }))]));
}

const evidenceStates = ['confirmed', 'contradicted', 'insufficient_evidence'];
const gold = { 2: 'confirmed', 0: 'contradicted', 1: 'insufficient_evidence' };
function evidenceMetrics(rows) {
    const confusion = Object.fromEntries(evidenceStates.map(a => [a, Object.fromEntries(evidenceStates.map(b => [b, 0]))]));
    for (const { expected, actual } of rows) confusion[expected][actual]++;
    const scores = Object.fromEntries(evidenceStates.map(state => {
        const tp = confusion[state][state];
        const predicted = sum(evidenceStates.map(expected => confusion[expected][state]));
        const observed = sum(Object.values(confusion[state]));
        return [state, { precision: predicted ? tp / predicted : null, recall: observed ? tp / observed : null,
            f1: observed + predicted ? 2 * tp / (observed + predicted) : null }];
    }));
    return { n: rows.length, matched_conditions: rows.filter(r => r.present).length, confusion, scores,
        accuracy: rows.length ? sum(evidenceStates.map(state => confusion[state][state])) / rows.length : null,
        macro_f1: mean(Object.values(scores).map(r => r.f1).filter(Number.isFinite)),
        false_confirmations: rows.filter(r => r.actual === 'confirmed' && r.expected !== 'confirmed').length,
        false_contradictions: rows.filter(r => r.actual === 'contradicted' && r.expected !== 'contradicted').length,
        abstentions: rows.filter(r => r.actual === 'insufficient_evidence').length };
}

const evidence = Object.fromEntries(variants.map(variant => {
    const details = [];
    const keys = { missed: 0, extra: 0 };
    for (const row of run.rows) {
        const oracle = row.evidence.oracle;
        const test = row.evidence[variant];
        if (!oracle) continue;
        const canonical = new Map(Object.entries(test.conditions).map(([id, condition]) => [condition.key, id]));
        const expectedKeys = new Set(Object.values(oracle.conditions).map(condition => condition.key));
        keys.missed += [...expectedKeys].filter(k => !canonical.has(k)).length;
        keys.extra += [...canonical.keys()].filter(k => !expectedKeys.has(k)).length;
        for (const [condition, grades] of Object.entries(row.checks)) {
            const rule = oracle.conditions[condition];
            assert(rule, 'Unknown oracle condition ' + row.id + ' ' + condition);
            const id = canonical.get(rule.key);
            for (const [work, label] of Object.entries(grades)) {
                const decision = test.ranks[work]?.evidence[id];
                details.push({ query_id: row.id, artwork_id: Number(work), kind: rule.kind, condition, expected: gold[label],
                    actual: decision?.state ?? 'insufficient_evidence', present: id !== undefined, decision: decision ?? null });
            }
        }
    }
    return [variant, { ...evidenceMetrics(details), extraction_difference: keys,
        by_type: Object.fromEntries([...new Set(details.map(row => row.kind))].sort()
            .map(kind => [kind, evidenceMetrics(details.filter(row => row.kind === kind))])), details }];
}));

const rankingResults = Object.fromEntries(variants.map(variant => {
    const rows = rowsFor(variant, optionsToReport[0]);
    const comparisons = [...Object.entries(pairs), ['B', 'A'], ['D', 'B']];
    return [variant, { summary: summarize(rows),
        paired: Object.fromEntries(comparisons.map(([mode, base]) => [mode + '-' + base, compare(rows, mode, base)])), rows }];
}));
const extraction = {
    n: run.rows.length,
    validation: tally(run.rows.map(row => row.extraction.validation)),
    fallbacks: tally(run.rows.map(row => row.extraction.fallback_reason).filter(Boolean)),
    model_calls: sum(run.rows.map(row => row.extraction.llm_calls ?? 0)),
    unknown_model_calls: run.rows.filter(row => row.extraction.llm_calls === null).length,
    latency_ms: times(run.rows.map(row => row.extraction.ms)),
    prompt_tokens: times(run.rows.map(row => row.extraction.tokens.prompt_tokens).filter(Number.isFinite)),
    output_tokens: times(run.rows.map(row => row.extraction.tokens.output_tokens).filter(Number.isFinite)),
};
save('metrics-' + split, { split, replay: run.replay, option: optionsToReport[0], extraction, ranking: rankingResults, evidence });
if (split !== 'development') {
    for (const method of variants) save('controls-' + split + '-' + method, { option: optionsToReport[0], method,
        rows: run.rows.map(({ trials, ...row }) => ({ ...row, variants: trials[method][optionsToReport[0]] })) });
}
