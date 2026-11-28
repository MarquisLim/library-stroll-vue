import assert from 'node:assert/strict';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { jsonBytes, loadStudy, loadUnified, priorities, readJson, reviewId, sha256, stageRoot, stages,
    studies, textKey, unifiedDirectory } from './unified-data.mjs';

export function importRatings(data, queue) {
    assert(data?.schema_version === 2 && data.source === 'manual_ui' && data.kind === queue.kind
        && data.queue_sha256 === queue.fingerprint && typeof data.reviewer_id === 'string'
        && /^[a-z0-9][a-z0-9_-]{0,39}$/.test(data.reviewer_id)
        && Array.isArray(data.ratings), 'Expected a human export for this frozen queue');
    const allowed = new Set(queue.pairs.map(pair => pair.pair_id));
    const ratings = new Map();
    for (const row of data.ratings) {
        assert(allowed.has(row.pair_id) && !ratings.has(row.pair_id), 'Unknown or repeated assessment pair');
        const values = queue.kind === 'relevance' ? [0, 1, 2, null] : [0, 2, null];
        assert(values.includes(row.rating) && (row.rating === null ? row.status === 'unsure' : row.status === 'rated')
            && row.reviewer_id === data.reviewer_id && typeof row.rated_at === 'string' && Number.isFinite(Date.parse(row.rated_at)),
        'Invalid human assessment');
        ratings.set(row.pair_id, row.rating);
    }
    return ratings;
}

const sum = numbers => numbers.reduce((a, b) => a + b, 0);
const mean = numbers => numbers.length ? sum(numbers) / numbers.length : null;
const finite = Number.isFinite;
const depths = [1, 5, 10, 20, 50];
const dcg = grades => sum(grades.map((grade, offset) => (2 ** grade - 1) / Math.log2(offset + 2)));

function countStatus(grades) {
    const rated = grades.filter(finite).length;
    const unsure = grades.filter(value => value === null).length;
    return { returned: grades.length, rated, unsure, unreviewed: grades.length - rated - unsure };
}

export function assessRanking(ids, grades) {
    const heads = Object.fromEntries(depths.map(k => [k, ids.slice(0, k).map(id => grades.get(id))]));
    const coverage = Object.fromEntries(depths.map(k => [k, countStatus(heads[k])]));
    const complete = k => coverage[k].returned > 0 && coverage[k].rated === coverage[k].returned;
    const known = [...grades.values()].filter(finite).sort((a, b) => b - a);
    const knownFull = known.filter(grade => grade === 2).length;
    const ndcg = k => {
        if (!complete(k)) return null;
        const ideal = dcg(known.slice(0, k));
        return ideal > 0 ? dcg(heads[k]) / ideal : null;
    };
    const hit = k => complete(k) ? Number(heads[k].includes(2)) : null;
    const first = heads[5].indexOf(2);
    const recall = k => complete(k) && knownFull ? heads[k].filter(value => value === 2).length / knownFull : null;
    return {
        scores: { ndcg5: ndcg(5), ndcg10: ndcg(10), hit1: hit(1), hit5: hit(5),
            mrr5: complete(5) ? (first < 0 ? 0 : 1 / (first + 1)) : null,
            ...Object.fromEntries(depths.slice(1).map(k => ['recall_known_' + k, recall(k)])) },
        coverage, known_full: knownFull, confirmed_full_top5: heads[5].filter(value => value === 2).length,
    };
}

function bootstrap(differences) {
    if (differences.length < 2) return null;
    let state = 5410301;
    const randomIndex = () => {
        state ^= state << 13;
        state ^= state >>> 17;
        state ^= state << 5;
        return (state >>> 0) % differences.length;
    };
    const draws = Array.from({ length: 10000 }, () => mean(differences.map(() => differences[randomIndex()])));
    draws.sort((a, b) => a - b);
    const bound = p => {
        const i = p * (draws.length - 1);
        return draws[Math.floor(i)] + (draws[Math.ceil(i)] - draws[Math.floor(i)]) * (i % 1);
    };
    return [bound(0.025), bound(0.975)];
}

const planned = family => family === 'colbert'
    ? [['C', 'B'], ['D20', 'A'], ['E20', 'C'], ['F20', 'C'], ['F20', 'E20']]
    : [['B', 'A'], ['C', 'A'], ['D', 'B'], ...['oracle', 'e2e'].flatMap(source =>
        [['E', 'A'], ['F', 'B'], ['G', 'D']].map(([method, base]) => [source + '-' + method, base]))];

function summarize(rows, modes, family) {
    const keys = Object.keys(rows[0].methods[modes[0]].scores);
    const score = (row, mode, key) => row.methods[mode].scores[key];
    return {
        queries: rows.length,
        methods: Object.fromEntries(modes.map(mode => {
            const durations = rows.map(row => row.methods[mode]);
            const entries = Object.fromEntries(keys.map(key => {
                const samples = rows.map(row => score(row, mode, key)).filter(finite);
                return [key, { mean: mean(samples), n: samples.length }];
            }));
            const coverage = Object.fromEntries(depths.map(k => [k, Object.fromEntries(
                ['returned', 'rated', 'unsure', 'unreviewed'].map(field => [field, sum(durations.map(row => row.coverage[k][field]))]),
            )]));
            return [mode, { scores: entries, coverage, confirmed_full_top5: sum(durations.map(row => row.confirmed_full_top5)) }];
        })),
        paired: Object.fromEntries(planned(family).filter(pair => pair.every(mode => modes.includes(mode))).map(([candidate, baseline]) => [
            candidate + ' vs ' + baseline,
            Object.fromEntries(keys.map(key => {
                const paired = rows.map(row => [row.id, score(row, candidate, key), score(row, baseline, key)])
                    .filter(([, left, right]) => finite(left) && finite(right));
                const differences = paired.map(([, left, right]) => left - right);
                return [key, { n: differences.length, delta: mean(differences), ci95: bootstrap(differences),
                    improved: paired.filter((_, i) => differences[i] > 1e-12).map(([id]) => id),
                    worsened: paired.filter((_, i) => differences[i] < -1e-12).map(([id]) => id) }];
            })),
        ])),
    };
}

export function rankingMetrics(ratings, { queues, catalogs }, root = stageRoot) {
    const reviewPairs = new Map(queues.relevance.pairs.map(pair => [pair.pair_id, pair]));
    return Object.fromEntries(studies.map(study => {
        const source = loadStudy(root, study);
        const reference = catalogs.find(item => item.id === study.id);
        assert.equal(source.rank_sha256, reference.rank_sha256, 'Saved ranking checksum differs');
        assert.equal(source.queue_sha256, reference.queue_sha256, 'Original queue checksum differs');
        let modes;
        const rows = [...source.queries].map(([id, { query, methods }]) => {
            const orderedModes = Object.keys(methods).sort();
            modes ??= orderedModes;
            assert.deepEqual(orderedModes, modes);
            const mapping = reference.queries[id];
            assert.equal(mapping.group_id, sha256(textKey(query)));
            const grades = new Map();
            for (const [artwork, pairId] of Object.entries(mapping.artwork_to_pair)) {
                const entry = reviewPairs.get(pairId);
                assert(entry && entry.group_id === mapping.group_id && pairId === reviewId(query, Number(artwork), entry.image_sha256),
                    'Human pair cannot be projected onto the saved rankings');
                if (ratings.has(pairId)) grades.set(Number(artwork), ratings.get(pairId));
            }
            return { id, language: query.lang, methods: Object.fromEntries(Object.entries(methods)
                .map(([mode, ids]) => [mode, assessRanking(ids, grades)])) };
        });
        return [study.id, { cohort: study.cohort, rank_sha256: source.rank_sha256, groups: {
            all: summarize(rows, modes, study.family),
            ru: summarize(rows.filter(row => row.language === 'ru'), modes, study.family),
            en: summarize(rows.filter(row => row.language === 'en'), modes, study.family),
        }, rows }];
    }));
}

export function conditionMetrics(ratings, dataset, root = stageRoot) {
    const evidenceFile = join(root, stages.constraints, 'metrics-evaluation.json');
    assert.equal(sha256(readFileSync(evidenceFile)), dataset.manifest.evidence_source_sha256, 'Condition evidence checksum differs');
    const evidence = readJson(evidenceFile).evidence;
    const key = row => JSON.stringify([row.query_id, row.condition, row.artwork_id]);
    const byKey = new Map(Object.entries(dataset.provenance().conditions).map(([id, row]) => [key(row), id]));
    const states = ['confirmed', 'contradicted', 'insufficient_evidence'];
    const actualState = { 2: states[0], 0: states[1], null: states[2] };
    assert.deepEqual(new Set(byKey.values()), new Set(dataset.queues.conditions.pairs.map(pair => pair.pair_id)));
    const results = Object.fromEntries(['oracle', 'e2e'].map(mode => {
        const matrix = Object.fromEntries(states.map(state => [state, Object.fromEntries(states.map(other => [other, 0]))]));
        const seen = new Set();
        for (const row of evidence[mode].details) {
            const id = byKey.get(key(row));
            assert(id && !seen.has(id) && states.includes(row.actual), 'Condition evidence does not match the queue');
            seen.add(id);
            if (ratings.has(id)) matrix[actualState[ratings.get(id)]][row.actual]++;
        }
        assert.equal(seen.size, byKey.size);
        const scores = Object.fromEntries(states.map(state => {
            const tp = matrix[state][state];
            const observed = sum(Object.values(matrix[state]));
            const predicted = sum(states.map(other => matrix[other][state]));
            return [state, { precision: predicted ? tp / predicted : null, recall: observed ? tp / observed : null,
                f1: observed + predicted ? 2 * tp / (observed + predicted) : null }];
        }));
        return [mode, { confusion: matrix, scores,
            accuracy: ratings.size ? sum(states.map(state => matrix[state][state])) / ratings.size : null }];
    }));
    return { reviewed: ratings.size, unsure: [...ratings.values()].filter(value => value === null).length,
        unreviewed: byKey.size - ratings.size, comparisons: results };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const input = process.argv[2];
    if (!input || process.argv.length !== 3) throw new Error('Usage: node human-metrics.mjs <human-review-export.json>');
    const dataset = loadUnified();
    const data = readJson(input);
    assert(['conditions', 'relevance'].includes(data.kind), 'Unknown assessment kind');
    const queue = dataset.queues[data.kind];
    const ratings = importRatings(data, queue);
    assert(ratings.size > 0, 'The export does not contain any human assessments yet');
    const digest = sha256(readFileSync(input));
    const metrics = data.kind === 'conditions' ? conditionMetrics(ratings, dataset, stageRoot)
        : rankingMetrics(ratings, { queues: dataset.queues, catalogs: dataset.provenance().catalogs });
    const byPriority = Object.fromEntries(priorities.map(priority => {
        const entries = queue.pairs.filter(pair => pair.priority === priority);
        return [priority, countStatus(entries.map(pair => ratings.get(pair.pair_id)))];
    }));
    const report = { schema_version: 1, source: 'manual_ui', kind: data.kind, reviewer_id: data.reviewer_id,
        queue_sha256: queue.fingerprint, export_sha256: digest,
        coverage: countStatus(queue.pairs.map(pair => ratings.get(pair.pair_id))),
        ...(data.kind === 'relevance' ? { by_priority: byPriority } : {}), metrics };
    const output = join(unifiedDirectory, 'human-metrics');
    mkdirSync(output, { recursive: true });
    const file = join(output, `${data.kind}-${data.reviewer_id}-${digest.slice(0, 12)}.json`);
    writeFileSync(file, jsonBytes(report), { flag: 'wx' });
    console.log(file);
}
