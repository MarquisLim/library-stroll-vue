const assert = require('node:assert/strict')
const { createHash } = require('node:crypto')
const { readFileSync, writeFileSync } = require('node:fs')
const path = require('node:path')

const output = path.resolve(process.argv[2] ?? 'storage/app/ai-stage-05-04/5.4-1a')
const source = readFileSync(path.join(__dirname, 'assistant-intent-cases.json'))
const datasetHash = createHash('sha256').update(source).digest('hex')
const { cases } = JSON.parse(source)
const freeze = JSON.parse(readFileSync(path.join(output, 'dataset-freeze.json')))
const manifest = JSON.parse(readFileSync(path.join(output, 'run-manifest.json')))
assert.equal(datasetHash, freeze.sha256)
assert.equal(datasetHash, manifest.dataset_sha256)
assert.equal(cases.length, freeze.cases)
assert.equal(new Set(cases.map(row => row.id)).size, cases.length)

const modes = ['legacy', 'router']
const intents = ['search_media', 'greeting', 'capabilities', 'help', 'followup', 'media_question',
    'unsupported_media', 'organize_media', 'out_of_scope', 'clarify']
const projections = { new_search: 'search_media', use_previous: 'followup', clarify: 'clarify' }
const counts = (values, keys) => Object.fromEntries(keys.map(key => [key, values.filter(value => value === key).length]))
const sum = values => values.reduce((total, value) => total + value, 0)
const ratio = (part, total) => total > 0 ? part / total : 0
const predicted = (mode, turn) => (mode === 'router' ? turn.diagnostics?.intent?.intent : projections[turn.diagnostics?.dialogue?.action]) ?? 'unclassified'

const read = name => readFileSync(path.join(output, name), 'utf8')
const write = (name, value) => writeFileSync(path.join(output, name + '.json'), JSON.stringify(value, null, 2) + '\n')
const runs = Object.fromEntries(modes.map(mode => {
    const rows = read(`controls-${mode}.ndjson`).trim().split(/\r?\n/).map(line => JSON.parse(line))
    assert.deepEqual(rows.map(row => row.case), cases)
    for (const row of rows) {
        if (row.status_code !== 201) continue
        assert.equal(row.sequence, row.case.turn === 'first' ? 1 : 2)
        assert.equal(row.diagnostics.configuration.flags.assistant_intent_routing_enabled, mode === 'router')
        assert.equal(row.diagnostics.execution.retrieval_started, row.diagnostics.stages.lexical !== null)
        assert.equal(row.diagnostics.execution.composer_started, row.diagnostics.stages.composer !== null)
    }
    write(`controls-${mode}`, { mode, dataset_sha256: datasetHash, runs: rows })
    return [mode, rows]
}))

function quantile(values, probability) {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b)
    if (!sorted.length) return null
    const index = (sorted.length - 1) * probability
    const lower = Math.floor(index)
    const upper = Math.ceil(index)
    return sorted[lower] + (sorted[upper] - sorted[lower]) * (index - lower)
}

function distribution(values) {
    return { n: values.filter(Number.isFinite).length, p50: quantile(values, 0.5), p95: quantile(values, 0.95) }
}

function callCounts(rows, stage) {
    const values = rows.map(row => row.diagnostics?.llm_calls[stage])
    return { known_calls: sum(values.filter(Number.isFinite)), unknown_turns: values.filter(value => !Number.isFinite(value)).length }
}

function metrics(mode, rows) {
    const labels = rows.map(row => predicted(mode, row))
    const confusion = Object.fromEntries(intents.map(intent => [intent,
        counts(labels.filter((_, index) => rows[index].case.intent === intent), [...intents, 'unclassified'])]))
    const classes = Object.fromEntries(intents.map(intent => {
        const truePositive = confusion[intent][intent]
        const support = sum(Object.values(confusion[intent]))
        const selected = labels.filter(label => label === intent).length
        const precision = ratio(truePositive, selected)
        const recall = ratio(truePositive, support)
        return [intent, { support, selected, true_positive: truePositive, precision, recall, f1: ratio(2 * precision * recall, precision + recall) }]
    }))
    const supported = Object.values(classes).filter(row => row.support > 0)
    const falseRetrieval = rows.filter(row => row.case.intent !== 'search_media' && row.diagnostics?.execution.retrieval_started)
    const missedRetrieval = rows.filter(row => row.case.intent === 'search_media' && !row.diagnostics?.execution.retrieval_started)
    const classification = rows.map(row => row.diagnostics?.intent).filter(Boolean)
    const inferred = classification.filter(row => row.requests > 0)
    return {
        n: rows.length,
        correct: sum(Object.values(classes).map(value => value.true_positive)),
        accuracy: ratio(sum(Object.values(classes).map(value => value.true_positive)), rows.length),
        macro_f1: ratio(sum(supported.map(value => value.f1)), supported.length),
        confusion,
        classes,
        false_retrieval: falseRetrieval.map(row => row.case.id),
        missed_retrieval: missedRetrieval.map(row => row.case.id),
        retrieval_started: rows.filter(row => row.diagnostics?.execution.retrieval_started).length,
        composer_started: rows.filter(row => row.diagnostics?.execution.composer_started).length,
        actions: counts(rows.map(row => row.diagnostics?.dialogue?.action), ['new_search', 'use_previous', 'informational', 'clarify']),
        latencies_ms: {
            classification: distribution(classification.map(row => row.ms)),
            classifier_request_turns: distribution(inferred.map(row => row.ms)),
            response: distribution(rows.map(row => row.response_ms)),
        },
        classification_tokens: {
            input: distribution(inferred.map(row => row.tokens?.prompt_tokens)),
            output: distribution(inferred.map(row => row.tokens?.output_tokens)),
        },
        classifier_methods: counts(classification.map(row => row.method), ['rule', 'llm', 'fallback']),
        llm_calls: Object.fromEntries(['intent', 'normalizer', 'resolver', 'composer'].map(stage => [stage, callCounts(rows, stage)])),
        dense_started: rows.filter(row => row.diagnostics?.stages.dense).length,
        embedding_reported: rows.filter(row => Number.isFinite(row.diagnostics?.dense_service?.embedding_ms)).length,
        fallbacks: {
            classifier: rows.filter(row => row.diagnostics?.intent?.fallback_reason).map(row => [row.case.id, row.diagnostics.intent.fallback_reason]),
            contextual: rows.filter(row => row.diagnostics?.intent?.context_reason).map(row => [row.case.id, row.diagnostics.intent.context_reason]),
            search: rows.filter(row => row.status === 'search_fallback').map(row => row.case.id),
        },
        errors: rows.flatMap(row => (row.diagnostics?.errors ?? []).map(error => ({ id: row.case.id, ...error }))),
        http_errors: rows.filter(row => row.status_code !== 201).map(row => [row.case.id, row.status_code]),
    }
}

function signature(trace) {
    return trace.candidates.filter(row => row.dense_rank !== null || row.lexical_rank !== null || row.hybrid_rank !== null)
        .map(({ artwork_id, lexical_rank, dense_rank, rrf_rank, hybrid_rank, retrieved }) =>
            ({ artwork_id, lexical_rank, dense_rank, rrf_rank, hybrid_rank, retrieved }))
        .sort((a, b) => a.artwork_id - b.artwork_id)
}

const pairs = cases.map((item, i) => {
    const legacy = runs.legacy[i]
    const router = runs.router[i]
    assert.notEqual(legacy.pair_order, router.pair_order)
    const comparable = item.intent === 'search_media' && modes.every(mode => runs[mode][i].diagnostics?.execution.retrieval_started)
    const comparison = comparable ? {
        both_queries_equal_original: legacy.diagnostics.query.used === item.query && router.diagnostics.query.used === item.query,
        candidate_ranks_equal: JSON.stringify(signature(legacy.diagnostics)) === JSON.stringify(signature(router.diagnostics)),
        rag_sources_equal: JSON.stringify(legacy.diagnostics.rag?.available) === JSON.stringify(router.diagnostics.rag?.available),
        model_equal: legacy.diagnostics.configuration.models.dense === router.diagnostics.configuration.models.dense,
        delta_response_ms: router.response_ms - legacy.response_ms,
    } : null
    return { ...item, comparison, ...Object.fromEntries(modes.map(mode => {
        const row = runs[mode][i]
        return [mode, { prediction: predicted(mode, row), action: row.diagnostics?.dialogue?.action ?? null,
            status: row.status ?? null, retrieval: row.diagnostics?.execution.retrieval_started ?? false,
            composer: row.diagnostics?.execution.composer_started ?? false, response_ms: row.response_ms,
            classification_ms: row.diagnostics?.intent?.ms ?? null }]
    })) }
})

const groups = {
    all: () => true,
    first: row => row.case.turn === 'first',
    after_search: row => row.case.turn === 'after_search',
    ru: row => row.case.lang === 'ru',
    en: row => row.case.lang === 'en',
    ja: row => row.case.lang === 'ja',
    es: row => row.case.lang === 'es',
    independent: row => row.case.set === 'independent',
    specified: row => row.case.set === 'specified',
}
const summary = Object.fromEntries(Object.entries(groups).map(([name, predicate]) => ([name,
    Object.fromEntries(modes.map(mode => [mode, metrics(mode, runs[mode].filter(predicate))]))])))
const matched = pairs.filter(row => row.comparison)
function matchedMetrics(rows) {
    return {
        n: rows.length,
        exact_query: rows.filter(row => row.comparison.both_queries_equal_original).length,
        same_ranks: rows.filter(row => row.comparison.candidate_ranks_equal).length,
        same_rag_sources: rows.filter(row => row.comparison.rag_sources_equal).length,
        same_embedding_model: rows.filter(row => row.comparison.model_equal).length,
        classification_ms: distribution(rows.map(row => row.router.classification_ms)),
        paired_delta_response_ms: distribution(rows.map(row => row.comparison.delta_response_ms)),
        ...Object.fromEntries(modes.map(mode => [mode, distribution(rows.map(row => row[mode].response_ms))])),
    }
}
const matchedSearch = { ...matchedMetrics(matched), first: matchedMetrics(matched.filter(row => row.turn === 'first')),
    after_search: matchedMetrics(matched.filter(row => row.turn === 'after_search')) }
write('metrics', { dataset_sha256: datasetHash, summary, matched_search: matchedSearch, pairs })
