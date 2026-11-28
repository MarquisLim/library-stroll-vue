const assert = require('node:assert/strict')
const { createHash } = require('node:crypto')
const { readFileSync, writeFileSync } = require('node:fs')
const path = require('node:path')

const out = path.resolve(process.argv[2] ?? 'storage/app/ai-stage-05-04/5.4-1c')
const read = name => JSON.parse(readFileSync(path.join(out, `${name}.json`), 'utf8'))
const write = (name, value) => writeFileSync(path.join(out, `${name}.json`), JSON.stringify(value, null, 2) + '\n')
const bytes = readFileSync(path.join(__dirname, 'assistant-hybrid-cases.json'))
const { evaluation } = JSON.parse(bytes)
const modes = ['A', 'B', 'C', 'D']
const freeze = read('dataset-freeze')
const { rows, dataset_sha256 } = read('runs-evaluation')
const pools = read('pool-evaluation')
const judgments = require('./assistant-hybrid-judgments.json')
assert.equal(createHash('sha256').update(bytes).digest('hex'), freeze.sha256)
assert.equal(dataset_sha256, freeze.sha256)
assert.deepEqual(rows.map(row => [row.id, row.language, row.category, row.query, row.criterion]), evaluation.map(row => row.slice(0, 5)))
assert.deepEqual(Object.keys(judgments).sort(), rows.map(row => row.id).sort())

const at = (list, k) => list.slice(0, k)
const sum = list => list.reduce((a, b) => a + b, 0)
const average = list => list.length ? sum(list) / list.length : null
const dcg = values => sum(values.map((grade, index) => (2 ** grade - 1) / Math.log2(index + 2)))
const gain = (ids, grades, k) => dcg(at(ids, k).map(id => grades.get(id) ?? 0))
const percentile = (list, p) => {
    const sorted = list.filter(Number.isFinite).sort((a, b) => a - b)
    if (!sorted.length) return null
    const i = (sorted.length - 1) * p
    const j = Math.floor(i)
    return sorted[j] + (sorted[Math.ceil(i)] - sorted[j]) * (i - j)
}
const times = list => ({ n: list.filter(Number.isFinite).length, p50: percentile(list, 0.5), p95: percentile(list, 0.95) })

function metrics(ids, grades, threshold) {
    const relevant = id => (grades.get(id) ?? 0) >= threshold
    const ideal = [...grades.values()].filter(Number.isFinite).sort((a, b) => b - a)
    const known = ideal.filter(grade => grade >= threshold).length
    const ndcg = k => dcg(at(ideal, k)) ? gain(ids, grades, k) / dcg(at(ideal, k)) : 0
    const hits = k => at(ids, k).filter(relevant).length
    const rank = at(ids, 5).findIndex(relevant)
    return { ndcg5: ndcg(5), ndcg10: ndcg(10), hit1: Number(hits(1) > 0), hit5: Number(hits(5) > 0),
        recall20: known ? hits(20) / known : null, recall50: known ? hits(50) / known : null,
        mrr5: rank >= 0 ? 1 / (rank + 1) : 0 }
}

const rankedIds = (trace, stage) => trace.candidates.filter(candidate => candidate[stage] != null)
    .sort((a, b) => a[stage] - b[stage]).map(candidate => candidate.artwork_id)

const measured = rows.map(row => {
    const pool = pools[row.id]
    const grade = new Map(pool.map(id => [id, 0]))
    const label = judgments[row.id]
    for (const [key, value] of [['full', 2], ['partial', 1], ['unknown', null]]) {
        for (const id of label[key] ?? []) {
            assert.equal(grade.get(id), 0, `${row.id} #${id} must be in the pool exactly once`)
            grade.set(id, value)
        }
    }
    const trace = mode => row.runs[mode][0].diagnostics
    assert.deepEqual(rankedIds(trace('A'), 'dense_rank'), rankedIds(trace('B'), 'dense_rank'))
    assert.deepEqual(rankedIds(trace('A'), 'dense_rank'), rankedIds(trace('D'), 'dense_rank'))
    assert.deepEqual(rankedIds(trace('C'), 'lexical_rank'), rankedIds(trace('D'), 'lexical_rank'))
    const strict = id => grade.get(id) === 2
    for (const mode of modes) {
        assert.equal(row.runs[mode].length, 3)
        for (const run of row.runs[mode]) assert.deepEqual(run.ids, row.runs[mode][0].ids)
        at(row.runs[mode][0].ids, 15).forEach(id => assert(grade.has(id), `${row.id} #${id} missing from review`))
    }
    const byMode = Object.fromEntries(modes.map(mode => {
        const ids = row.runs[mode][0].ids
        return [mode, { strict: metrics(ids, grade, 2), inclusive: metrics(ids, grade, 1),
            covered10: at(ids, 10).filter(id => Number.isFinite(grade.get(id))).length,
            covered50: at(ids, 50).filter(id => Number.isFinite(grade.get(id))).length,
            latency_ms: row.runs[mode].map(run => run.ms),
            lexical_empty: mode === 'A' ? null : row.runs[mode][0].components.lexical.count === 0,
            top5: at(ids, 5).map(id => [id, grade.get(id) ?? null]), depths: [at(ids, 10).length, ids.length] }]
    }))
    const old = row.runs.B[0].ids
    const dense = row.runs.A[0].ids
    const combined = row.runs.D[0].ids
    return { id: row.id, query: row.query, category: row.category, language: row.language,
        grades: Object.fromEntries(grade), notes: label.notes,
        new_relevant_not_in_dense_or_legacy: combined.filter(id => strict(id) && !dense.includes(id) && !old.includes(id)),
        new_relevant_at20: at(combined, 20).filter(id => strict(id) && !at(old, 20).includes(id)),
        lost_relevant_at20: at(old, 20).filter(id => strict(id) && !at(combined, 20).includes(id)),
        modes: byMode }
})

function aggregate(group, mode) {
    const data = group.map(row => row.modes[mode])
    const averageMetrics = kind => Object.fromEntries(Object.keys(data[0][kind]).map(key => [key,
        average(data.map(row => row[kind][key]).filter(Number.isFinite))]))
    return { queries: group.length, strict: averageMetrics('strict'), inclusive: averageMetrics('inclusive'),
        covered10: sum(data.map(row => row.covered10)), depth10: sum(data.map(row => row.depths[0])),
        covered50: sum(data.map(row => row.covered50)), depth50: sum(data.map(row => row.depths[1])),
        latency_ms: times(data.flatMap(row => row.latency_ms)),
        lexical_empty: mode === 'A' ? null : data.filter(row => row.lexical_empty).length,
        queries_with_known_full_matches: data.filter(row => row.strict.recall50 !== null).length }
}

function bootstrap(diffs) {
    let state = 54103
    const random = () => {
        state ^= state << 13
        state ^= state >>> 17
        state ^= state << 5
        return state >>> 0
    }
    const sample = Array.from({ length: 10000 }, () => average(diffs.map(() => diffs[random() % diffs.length])))
    return [percentile(sample, 0.025), percentile(sample, 0.975)]
}

const groups = { all: measured, ru: measured.filter(row => row.language === 'ru'), en: measured.filter(row => row.language === 'en') }
const summary = Object.fromEntries(Object.entries(groups).map(([name, data]) => [name,
    Object.fromEntries(modes.map(mode => [mode, aggregate(data, mode)]))]))
const differences = Object.fromEntries(['ndcg5', 'ndcg10', 'hit1', 'hit5', 'mrr5'].map(key => {
    const diffs = measured.map(row => row.modes.D.strict[key] - row.modes.B.strict[key])
    return [key, { mean: average(diffs), increased: diffs.filter(x => x > 1e-10).length, decreased: diffs.filter(x => x < -1e-10).length,
        worsened_ids: measured.filter((_, i) => diffs[i] < -1e-10).map(row => row.id), bootstrap95: bootstrap(diffs) }]
}))
const components = Object.fromEntries(modes.map(mode => [mode, Object.fromEntries(['lexical', 'dense', 'rrf', 'hybrid', 'assistant_visibility']
    .map(stage => [stage, times(rows.flatMap(row => row.runs[mode].map(run => run.components[stage]?.ms)))]))]))
write('metrics', { dataset_sha256, summary, differences, components,
    judgments: { total_pairs: sum(measured.map(row => Object.keys(row.grades).length)),
        uncertain: sum(measured.map(row => Object.values(row.grades).filter(grade => grade === null).length)) },
    new_relevant_not_in_dense_or_legacy: measured.flatMap(row => row.new_relevant_not_in_dense_or_legacy.map(id => [row.id, id])),
    new_relevant_at20: sum(measured.map(row => row.new_relevant_at20.length)), lost_relevant_at20: sum(measured.map(row => row.lost_relevant_at20.length)),
    rows: measured })
for (const mode of modes) write(`controls-${mode}`, { mode, dataset_sha256, rows: rows.map(({ runs, ...row }) => ({ ...row, runs: runs[mode] })) })
