import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../../storage/app/ai-stage-04-08/', import.meta.url));
const OUT = join(ROOT, '4.8b-1');
const CUTS = [5, 10];
const IMPROVEMENTS = [9, 12, 18, 26, 28, 36];
const MONTE_CARLO = { seed: 48081, repetitions: 1_000_000, generator: 'SplitMix64' };
const TOLERANCE = 1e-12;
const sum = values => values.reduce((a, b) => a + b, 0);
const mean = values => sum(values) / values.length;
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
const read = (...parts) => JSON.parse(readFileSync(join(ROOT, ...parts)));
const compare = (a, b) => assert.ok(Math.abs(a - b) <= TOLERANCE, `${a} differs from ${b}`);

function frozen(inputs, ...parts) {
    const folder = parts.slice(0, -1);
    const bytes = readFileSync(join(ROOT, ...parts));
    const digest = sha(bytes);
    assert.equal(digest, read(...folder, 'checksums.json').files[parts.at(-1)]);
    inputs[parts.join('/')] = digest;
    return JSON.parse(bytes);
}

function bootstrap(values, { seed, replicates, sample_size, confidence_level }) {
    assert.equal(sample_size, values.length);
    let state = seed >>> 0;
    const samples = Float64Array.from({ length: replicates }, () => {
        let total = 0;
        for (let i = 0; i < sample_size; i++) {
            state ^= state << 13;
            state ^= state >>> 17;
            state ^= state << 5;
            total += values[Math.floor((state >>> 0) / 2 ** 32 * sample_size)];
        }
        return total / sample_size;
    }).sort();
    const quantile = p => {
        const rank = (samples.length - 1) * p;
        const lo = Math.floor(rank);
        return samples[lo] + (rank - lo) * (samples[Math.ceil(rank)] - samples[lo]);
    };
    const tail = (1 - confidence_level) / 2;
    return { estimate: mean(values), lower: quantile(tail), upper: quantile(1 - tail) };
}

function signedSums(values) {
    const sums = new Float64Array(2 ** values.length);
    let active = 1;
    for (const value of values) {
        for (let i = 0; i < active; i++) {
            sums[i + active] = sums[i] - value;
            sums[i] += value;
        }
        active *= 2;
    }
    return sums;
}

function lowerBound(sorted, target) {
    let left = 0;
    let right = sorted.length;
    while (left < right) {
        const mid = (left + right) >>> 1;
        if (sorted[mid] < target) left = mid + 1;
        else right = mid;
    }
    return left;
}

// Sign-sum distributions are symmetric, including multiplicities from zero differences.
function exactPermutation(values) {
    const observed = Math.abs(sum(values));
    const permutations = 2 ** values.length;
    if (observed <= TOLERANCE) return { permutations, extreme: permutations, p_two_sided: 1 };
    const half = Math.floor(values.length / 2);
    const tail = signedSums(values.slice(half)).sort();
    let upper = 0;
    for (const head of signedSums(values.slice(0, half))) {
        upper += tail.length - lowerBound(tail, observed - TOLERANCE - head);
    }
    const extreme = 2 * upper;
    return { permutations, extreme, p_two_sided: extreme / permutations };
}

function samplePermutations(metrics, { seed, repetitions }) {
    let state = BigInt(seed);
    const thresholds = metrics.map(values => Math.abs(sum(values)) - TOLERANCE);
    const counts = metrics.map(() => 0);
    for (let draw = 0; draw < repetitions; draw++) {
        state = BigInt.asUintN(64, state + 0x9e3779b97f4a7c15n);
        let bits = BigInt.asUintN(64, (state ^ (state >> 30n)) * 0xbf58476d1ce4e5b9n);
        bits = BigInt.asUintN(64, (bits ^ (bits >> 27n)) * 0x94d049bb133111ebn);
        bits ^= bits >> 31n;
        const low = Number(bits & 0xffffffffn);
        const high = Number(bits >> 32n);
        for (let metric = 0; metric < metrics.length; metric++) {
            let randomized = 0;
            for (let i = 0; i < metrics[metric].length; i++) {
                const mask = i < 32 ? low : high;
                randomized += (mask >>> (i % 32) & 1) ? metrics[metric][i] : -metrics[metric][i];
            }
            counts[metric] += Number(Math.abs(randomized) >= thresholds[metric]);
        }
    }
    return counts.map(extreme => ({ extreme, p_two_sided_plus_one: (extreme + 1) / (repetitions + 1) }));
}

const contribution = (relevance, rank) => (2 ** (relevance ?? 0) - 1) / Math.log2(rank + 1);

function queryDetails(query, saved, plan, judgments, titles) {
    const definite = [...judgments.values()].filter(row => row.status === 'rated');
    const ideal = definite.map(row => row.relevance).sort((a, b) => b - a);
    const ids = Object.fromEntries(['A', 'B'].map(method => [method, query.methods[method].results.map(hit => hit.id)]));
    assert.deepEqual(ids.A.slice(30), ids.B.slice(30));
    assert.deepEqual(new Set(ids.A.slice(0, 30)), new Set(ids.B.slice(0, 30)));
    const position = (method, id) => ids[method].indexOf(id) + 1 || null;
    const top5 = {};
    for (const method of ['A', 'B']) {
        top5[method] = ids[method].slice(0, 5).map((id, i) => {
            const label = judgments.get(id);
            assert.ok(label, 'Top-5 pair missing from human review.');
            return { artwork_id: id, title: titles.get(id), rank: i + 1, status: label.status, relevance: label.relevance };
        });
        for (const k of CUTS) {
            const idcg = sum(ideal.slice(0, k).map((grade, i) => contribution(grade, i + 1)));
            const dcg = sum(ids[method].slice(0, k).map((id, i) => contribution(judgments.get(id)?.relevance, i + 1)));
            compare(idcg ? dcg / idcg : 0, saved.methods[method][`ndcg${k}`]);
        }
    }
    const relevant = definite.filter(row => row.relevance > 0).map(({ artwork_id, relevance }) => ({
        artwork_id, title: titles.get(artwork_id), relevance, rank_A: position('A', artwork_id), rank_B: position('B', artwork_id),
    }));
    return {
        ...plan, top5,
        relevant_movements: relevant, A: saved.methods.A, B: saved.methods.B,
        delta: Object.fromEntries(CUTS.map(k => [k, saved.methods.B[`ndcg${k}`] - saved.methods.A[`ndcg${k}`]])),
    };
}

function byGroup(rows) {
    return Object.fromEntries(CUTS.map(k => {
        const deltas = rows.map(row => row.delta[k]);
        return [k, {
            queries: rows.length, mean_A: mean(rows.map(row => row.A[`ndcg${k}`])), mean_B: mean(rows.map(row => row.B[`ndcg${k}`])),
            delta: mean(deltas), improved: deltas.filter(delta => delta > TOLERANCE).length,
            worsened: deltas.filter(delta => delta < -TOLERANCE).length, tied: deltas.filter(delta => Math.abs(delta) <= TOLERANCE).length,
        }];
    }));
}

function caseTables(cases) {
    const labels = hits => hits.map(hit => `${hit.artwork_id} (${hit.relevance ?? '?'})`).join(', ');
    const moves = rows => rows
        .filter(row => row.rank_A !== row.rank_B)
        .map(row => `${row.artwork_id} (${row.relevance}) ${row.rank_A ?? '—'} → ${row.rank_B ?? '—'}`).join(', ');
    return Object.entries(cases).map(([name, rows]) => [
        `## ${name}`,
        '', '| № | Запрос | Топ-5 A | Топ-5 B | Δ nDCG@5 | Перемещения известных релевантных работ |',
        '| ---: | --- | --- | --- | ---: | --- |',
        ...rows.map(row => `| ${row.id} | ${row.text} | ${labels(row.top5.A)} | ${labels(row.top5.B)} | ${row.delta[5].toFixed(4)} | ${moves(row.relevant_movements)} |`),
    ].join('\n')).join('\n\n') + '\n';
}

function selfTest() {
    assert.deepEqual(exactPermutation([1, 2, 3]), { permutations: 8, extreme: 2, p_two_sided: 0.25 });
    assert.deepEqual(exactPermutation([1, 1, 0]), { permutations: 8, extreme: 4, p_two_sided: 0.5 });
    assert.deepEqual(exactPermutation([1, -1]), { permutations: 4, extreme: 4, p_two_sided: 1 });
    const toy = [1.5, -0.4, 0, 2, -0.2, 0.7];
    const brute = Array.from({ length: 2 ** toy.length }, (_, mask) =>
        Math.abs(sum(toy.map((value, i) => mask & (1 << i) ? value : -value))));
    assert.equal(exactPermutation(toy).extreme, brute.filter(value => value >= Math.abs(sum(toy)) - TOLERANCE).length);
    assert.deepEqual(samplePermutations([[0, 0]], { seed: 31, repetitions: 500 }), [{ extreme: 500, p_two_sided_plus_one: 1 }]);
    assert.ok(Math.abs(samplePermutations([[1, 2, 3]], { seed: 31, repetitions: 2000 })[0].p_two_sided_plus_one - 0.25) < 0.05);
    assert.deepEqual(bootstrap([0.25, 0.25], { seed: 31, replicates: 100, sample_size: 2, confidence_level: 0.95 }),
        { estimate: 0.25, lower: 0.25, upper: 0.25 });
    compare(contribution(2, 3), 1.5);
    compare(contribution(null, 1), 0);
}

function main() {
    const inputs = {};
    const plan = frozen(inputs, 'query-plan.json');
    const rankings = frozen(inputs, '4.8a-2', 'rankings.json').queries;
    const human = frozen(inputs, '4.8a-4', 'human-qrels.json');
    const metrics = frozen(inputs, '4.8a-4', 'metrics-by-query.json');
    const summary = frozen(inputs, '4.8a-4', 'summary.json').all.methods;
    const savedBootstrap = frozen(inputs, '4.8a-4', 'bootstrap.json');
    const sensitivity = frozen(inputs, '4.8a-4', 'sensitivity.json');
    const directory45 = join(ROOT, '..', 'ai-stage-04-05');
    const documentBytes = readFileSync(join(directory45, 'documents.json'));
    const documentsHash = sha(documentBytes);
    assert.equal(documentsHash, JSON.parse(readFileSync(join(directory45, 'dense-integrity.json'))).documents_sha256);
    inputs['../ai-stage-04-05/documents.json'] = documentsHash;
    const titles = new Map(JSON.parse(documentBytes).map(row => [row.id, row.title]));
    assert.equal(titles.size, 100);
    assert.equal(rankings.length, 40);
    assert.equal(human.source, 'manual_ui');
    assert.equal(human.ratings.length, 595);
    assert.equal(human.unsure.length, 12);
    const judgments = new Map(metrics.map(row => [row.id, new Map()]));
    for (const row of [...human.ratings, ...human.unsure]) {
        const query = judgments.get(row.query_id);
        assert.ok(query && !query.has(row.artwork_id));
        query.set(row.artwork_id, row);
    }
    assert.deepEqual(rankings.map(row => row.id), metrics.map(row => row.id));
    assert.deepEqual(plan.map(({ id, lang, category }) => ({ id, lang, category })),
        metrics.map(({ id, lang, category }) => ({ id, lang, category })));
    const rows = rankings.map((row, i) => queryDetails(row, metrics[i], plan[i], judgments.get(row.id), titles));
    const all = byGroup(rows);
    const deltas = CUTS.map(k => rows.map(row => row.delta[k]));
    const monteCarlo = samplePermutations(deltas, MONTE_CARLO);
    const comparison = Object.fromEntries(CUTS.map((k, i) => {
        for (const method of ['A', 'B']) compare(all[k][`mean_${method}`], summary[method][`ndcg${k}`]);
        return [k, {
            ...all[k], bootstrap: bootstrap(deltas[i], savedBootstrap.parameters),
            exact_permutation: exactPermutation(deltas[i]), monte_carlo: monteCarlo[i],
            unsure_effect_bounds: sensitivity.exact_bounds['B-A'][`ndcg${k}`],
        }];
    }));
    for (const key of ['estimate', 'lower', 'upper']) compare(comparison[5].bootstrap[key], savedBootstrap.contrasts['B-A'][key]);
    const ordered = CUTS.toSorted((a, b) => comparison[a].exact_permutation.p_two_sided - comparison[b].exact_permutation.p_two_sided);
    let adjusted = 0;
    for (const [index, k] of ordered.entries()) {
        adjusted = Math.min(1, Math.max(adjusted, (ordered.length - index) * comparison[k].exact_permutation.p_two_sided));
        comparison[k].holm_p = adjusted;
    }
    const categories = [...new Set(rows.map(row => row.category))];
    assert.equal(categories.length, 7);
    const leaveOneOut = Object.fromEntries(categories.map(category => {
        const remaining = rows.filter(row => row.category !== category);
        const group = byGroup(remaining);
        return [category, Object.fromEntries(CUTS.map(k => {
            const values = remaining.map(row => row.delta[k]);
            return [k, { ...group[k], exact_permutation: exactPermutation(values) }];
        }))];
    }));
    const cases = {
        Ухудшения: rows.filter(row => row.delta[5] < -TOLERANCE),
        Улучшения: IMPROVEMENTS.map(id => rows.find(row => row.id === id)),
    };
    assert.equal(cases.Ухудшения.length, 14);
    assert.ok(cases.Улучшения.every(row => row.delta[5] > TOLERANCE));
    const statistics = {
        bootstrap_parameters: savedBootstrap.parameters, matched_previous_bootstrap_ndcg5: true,
        permutation: { alternative: 'two-sided', statistic: 'mean paired B-A difference', tie_tolerance_on_sum: TOLERANCE, ...MONTE_CARLO },
        comparison, leave_one_category_out: leaveOneOut,
        languages: Object.fromEntries(['ru', 'en'].map(lang => [lang, byGroup(rows.filter(row => row.lang === lang))])),
        categories: Object.fromEntries(categories.map(category => [category, byGroup(rows.filter(row => row.category === category))])),
        abstract_without_query_40: byGroup(rows.filter(row => row.category === 'abstract_decorative' && row.id !== 40)),
    };
    mkdirSync(OUT, { recursive: true });
    const output = {
        'statistics.json': JSON.stringify(statistics, null, 2) + '\n',
        'queries.json': JSON.stringify(rows, null, 2) + '\n',
        'case-tables.md': caseTables(cases),
    };
    for (const [name, content] of Object.entries(output)) writeFileSync(join(OUT, name), content);
    writeFileSync(join(OUT, 'checksums.json'), JSON.stringify({
        inputs, node: process.version, script_sha256: sha(readFileSync(fileURLToPath(import.meta.url))),
        outputs: Object.fromEntries(Object.entries(output).map(([name, content]) => [name, sha(content)])),
    }, null, 2) + '\n');
    console.log(JSON.stringify({ comparison, worsened: cases.Ухудшения.map(row => row.id), improved_examples: IMPROVEMENTS }));
}

selfTest();
if (process.argv[2] === '--self-test') console.log('Exact and sampled sign flips, bootstrap and DCG checks passed.');
else if (process.argv.length === 2) main();
else throw new Error('Usage: node colbert_error_analysis.mjs [--self-test]');
