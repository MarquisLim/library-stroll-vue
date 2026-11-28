const assert = require('node:assert/strict')
const { createHash } = require('node:crypto')
const { existsSync, readFileSync, writeFileSync } = require('node:fs')
const path = require('node:path')

const output = path.resolve(process.argv[2] ?? 'storage/app/ai-stage-05-04/5.4-1b')
const input = name => readFileSync(path.join(__dirname, name))
const source = input('assistant-query-cases.json')
const equivalence = input('assistant-query-equivalence.json')
const read = name => readFileSync(path.join(output, name), 'utf8')
const write = (name, value) => writeFileSync(path.join(output, name + '.json'), JSON.stringify(value, null, 2) + '\n')
const digest = value => createHash('sha256').update(value).digest('hex')
const freeze = JSON.parse(read('dataset-freeze.json'))
assert.equal(digest(source), freeze.cases_sha256)
assert.equal(digest(equivalence), freeze.equivalence_sha256)
const cases = JSON.parse(source)
const { aliases } = JSON.parse(equivalence)
const normalize = text => text.normalize('NFC').toLowerCase().replaceAll('ё', 'е')
    .replace(/^[\s\p{P}]+|[\s\p{P}]+$/gu, '').replace(/\s+/g, ' ')
const escape = text => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
const aliasToConcept = new Map(Object.entries(aliases).flatMap(([key, forms]) => forms.map(form => [normalize(form), key])))
const canonical = text => {
    const value = normalize(text).replace(/^(?:a|an|the|un|una|une) /, '')
    return aliasToConcept.get(value) ?? value
}
const category = component => component.startsWith('pref:') ? category(component.slice(5))
    : component.split(':', 1)[0].replace(/^notattr$/, 'attr').replace(/^notmedia$/, 'media').replace(/^prefabs$/, 'abs')

function components(structure, query) {
    if (!structure) return []
    const names = structure.objects.map(object => canonical(object.name))
    const textualOrder = structure.objects.map((object, index) => ({ index, position: normalize(query).indexOf(normalize(object.name)) }))
        .sort((a, b) => a.position - b.position || a.index - b.index)
    const order = new Map(textualOrder.map(({ index }, position) => [index, position]))
    const distinct = names.map((name, index) => {
        const repeats = names.map((other, j) => ({ other, j })).filter(row => row.other === name)
        if (repeats.length === 1) return name
        const ordinal = repeats.filter(row => order.get(row.j) <= order.get(index)).length
        return `${name}@${ordinal}`
    })
    const atoms = []
    structure.objects.forEach((object, index) => {
        const who = distinct[index]
        const role = object.role === 'additional' ? 'a' : 'o'
        atoms.push(`${object.mode === 'preferred' ? 'pref:' : ''}${role}:${who}`)
        if (object.count) {
            const { op, n, max } = object.count
            atoms.push(['count', who, op, n, max].filter(value => value != null).join(':'))
        }
    })
    for (const item of structure.conditions) {
        const cue = item.cue ? normalize(item.cue) : null
        let text = normalize(item.text)
        if (cue && text.startsWith(cue + ' ')) text = text.slice(cue.length + 1)
        if (item.kind === 'background_color') {
            text = text.replace(/^(?:on|against|на|с) /, '').replace(/\s*(?:background|фон[а-я]*|fondo|背景)$/u, '')
        }
        const value = canonical(text)
        const preferred = item.mode === 'preferred' || item.mode === 'preferred_excluded'
        const excluded = item.mode === 'excluded' || item.mode === 'preferred_excluded'
        const prefix = preferred ? 'pref:' : ''
        let target = distinct[item.target - 1]
        let other = distinct[item.other - 1]
        if (item.kind === 'relation' && value === 'next_to' && order.get(item.target - 1) > order.get(item.other - 1)) {
            [target, other] = [other, target]
        }
        const atom = {
            attribute: `${excluded ? 'notattr' : 'attr'}:${target}:${value}`,
            color: `${excluded ? 'notcolor' : 'color'}:${target}:${value}`,
            background_color: `${excluded ? 'notbg' : 'bg'}:${value}`,
            relation: `${excluded ? 'notrel' : 'rel'}:${target}:${value}:${other}`,
            presence: `${preferred ? 'prefabs' : 'abs'}:${value}`,
        }[item.kind] ?? `${excluded ? 'not' : ''}${item.kind}:${value}`
        atoms.push(item.kind === 'presence' ? atom : prefix + atom)
    }
    atoms.push(...structure.ambiguities.map(item => `amb:${item.kind}`))
    return [...new Set(atoms)].sort()
}

const dev = cases.development.flatMap(row => row[3])
const vocabulary = type => [...new Set(dev.filter(atom => atom.startsWith(type + ':')).map(atom => atom.split(':').at(-1)))].sort()
const objects = [...new Set([...vocabulary('o'), ...vocabulary('a')])]
const colors = [...new Set([...vocabulary('color'), ...vocabulary('bg')])]
const wordPattern = values => [...new Set(values.flatMap(key => [key, ...(aliases[key] ?? [])]).map(normalize))]
    .sort((a, b) => b.length - a.length).map(escape).join('|')
const wordMatches = (text, values) => [...text.matchAll(new RegExp(`(?<![\\p{L}\\p{N}_])(?:${wordPattern(values)})(?![\\p{L}\\p{N}_])`, 'gu'))]
const colorAtEnd = new RegExp(`(?<![\\p{L}\\p{N}_])(${wordPattern(colors)})$`, 'u')
const numbers = { one: 1, two: 2, three: 3, four: 4, five: 5, один: 1, одна: 1, два: 2, две: 2, двух: 2, три: 3, трех: 3, четыре: 4, пять: 5 }
const quantity = /(?:(не менее|at least|не больше|at most|примерно|about) )?(?<![\p{L}\p{N}_])([0-9]{1,3}|one|two|three|four|five|один|одна|два|две|двух|три|трех|четыре|пять)$/u
const op = { 'не менее': 'at_least', 'at least': 'at_least', 'не больше': 'at_most', 'at most': 'at_most', 'примерно': 'approx', about: 'approx' }

function baseline(query) {
    const text = normalize(query)
    const atoms = []
    for (const match of wordMatches(text, objects)) {
        let before = text.slice(0, match.index).trimEnd()
        if (/(?:без|without|no)$/u.test(before)) continue
        const which = canonical(match[0])
        atoms.push(`o:${which}`)
        const color = colorAtEnd.exec(before)
        if (color) {
            atoms.push(`color:${which}:${canonical(color[1])}`)
            before = before.slice(0, color.index).trimEnd()
        }
        const count = quantity.exec(before)
        if (count) atoms.push(`count:${which}:${op[count[1]] ?? 'exact'}:${numbers[count[2]] ?? Number(count[2])}`)
        if (/(?:несколько|several)$/u.test(before)) atoms.push(`count:${which}:vague`)
    }
    for (const match of text.matchAll(new RegExp(`(${wordPattern(colors)}) (?:фон[а-я]*|background)`, 'gu'))) {
        atoms.push(`bg:${canonical(match[1])}`)
    }
    for (const type of ['scene', 'media', 'style']) {
        for (const match of wordMatches(text, vocabulary(type))) {
            atoms.push(`${type}:${canonical(match[0])}`)
        }
    }
    if (!atoms.length && /(?:something|что-то|что-нибудь)/u.test(text)) atoms.push('amb:missing_subject')
    return [...new Set(atoms)].sort()
}

const ratio = (a, b) => b ? a / b : 0
const sum = values => values.reduce((a, b) => a + b, 0)
function distribution(values) {
    const valid = values.filter(Number.isFinite).sort((a, b) => a - b)
    const q = p => {
        if (!valid.length) return null
        const i = (valid.length - 1) * p
        const lo = Math.floor(i)
        return valid[lo] + (valid[Math.ceil(i)] - valid[lo]) * (i - lo)
    }
    return { n: valid.length, p50: q(0.5), p95: q(0.95) }
}

function quality(rows, mode, include = () => true) {
    const counts = rows.map(row => {
        const wanted = row.expected.filter(include)
        const predicted = row[mode].filter(include)
        const tp = wanted.filter(value => predicted.includes(value)).length
        return { expected: wanted.length, predicted: predicted.length, tp }
    })
    const expected = sum(counts.map(x => x.expected)), predicted = sum(counts.map(x => x.predicted)), tp = sum(counts.map(x => x.tp))
    const precision = ratio(tp, predicted), recall = ratio(tp, expected)
    return { expected, predicted, tp, precision, recall, f1: ratio(2 * precision * recall, precision + recall),
        false_added: predicted - tp, missed: expected - tp }
}

function summarize(rows, mode) {
    const valid = rows.filter(row => mode === 'baseline' || row.result.validation === 'valid').length
    const score = quality(rows, mode)
    return {
        n: rows.length, valid,
        exact: rows.filter(row => (mode === 'baseline' || row.result.validation === 'valid') && JSON.stringify(row[mode]) === JSON.stringify(row.expected)).length,
        ...score,
        false_addition_rate: ratio(score.false_added, score.predicted), omission_rate: ratio(score.missed, score.expected),
        components: Object.fromEntries(Object.entries({ objects: ['o', 'a'], quantity: ['count'], negative: [],
            binding: ['attr', 'color'], object_color: ['color'], background_color: ['bg'], color_scope: ['color', 'bg'],
            media: ['media'], relation: ['rel'], style: ['style'], scene: ['scene'], ambiguity: ['amb'], preference: [] })
            .map(([key, types]) => [key, quality(rows, mode, atom => key === 'negative' ? /^(?:pref:)?(?:not|abs:|prefabs:)/.test(atom)
                : key === 'preference' ? /^(?:pref:|prefabs:)/.test(atom) : types.includes(category(atom)))])),
    }
}

function readLines(name) {
    return read(name + '.ndjson').trim().split(/\r?\n/).map(line => JSON.parse(line))
}

const groups = {}
for (const split of ['development', 'evaluation']) {
    if (!existsSync(path.join(output, `extraction-${split}.ndjson`))) continue
    const raw = readLines(`extraction-${split}`)
    assert.equal(raw.length, cases[split].length)
    const rows = cases[split].map(([id, language, query, gold], index) => {
        const result = raw[index]
        assert.deepEqual([result.id, result.language, result.query], [id, language, query])
        return { id, language, query, expected: [...new Set(gold)].sort(),
            llm: components(result.structure, query), baseline: baseline(query), result }
    })
    const slices = { all: () => true, single: row => row.expected.length === 1, multiple: row => row.expected.length > 1,
        ru: row => row.language === 'ru', en: row => row.language === 'en', other: row => !['ru', 'en'].includes(row.language) }
    groups[split] = {
        scores: Object.fromEntries(Object.entries(slices).map(([key, predicate]) => [key,
            Object.fromEntries(['llm', 'baseline'].map(mode => [mode, summarize(rows.filter(predicate), mode)]))])),
        latency_ms: distribution(raw.map(row => row.ms)),
        tokens: { input: distribution(raw.map(row => row.tokens?.prompt_tokens)), output: distribution(raw.map(row => row.tokens?.output_tokens)) },
        llm_calls: sum(raw.map(row => row.llm_calls ?? 0)),
        unknown_call_count: raw.filter(row => row.llm_calls === null).length,
        fallback: raw.filter(row => row.fallback_reason).map(({ id, fallback_reason }) => [id, fallback_reason]),
        ambiguity_probe: rows.filter(row => row.expected.some(atom => atom.startsWith('amb:')) || row.result.would_clarify)
            .map(row => [row.id, row.expected, row.result.would_clarify, row.llm]),
    }
    write(`controls-${split}`, { rows })
}

function signature(trace) {
    return trace.candidates.filter(row => row.dense_rank !== null || row.lexical_rank !== null || row.hybrid_rank !== null)
        .map(({ artwork_id, lexical_rank, dense_rank, rrf_rank, hybrid_rank, retrieved }) =>
            ({ artwork_id, lexical_rank, dense_rank, rrf_rank, hybrid_rank, retrieved }))
        .sort((a, b) => a.artwork_id - b.artwork_id)
}

if (existsSync(path.join(output, 'paired-B.ndjson'))) {
    const a = readLines('paired-A'), b = readLines('paired-B')
    assert.equal(a.length, 16)
    assert.equal(b.length, a.length)
    const pairs = a.map((left, i) => {
        const right = b[i]
        assert.deepEqual([left.id, left.query, left.turn], [right.id, right.query, right.turn])
        assert.notEqual(left.pair_order, right.pair_order)
        assert.equal(left.status_code, 201)
        assert.equal(right.status_code, 201)
        const A = left.diagnostics, B = right.diagnostics
        assert.equal(A.configuration.flags.assistant_query_understanding_enabled, false)
        assert.equal(B.configuration.flags.assistant_query_understanding_enabled, true)
        assert.equal(A.query_understanding, null)
        return { id: left.id, turn: left.turn, comparable: A.execution.retrieval_started && B.execution.retrieval_started,
            query_equal: A.query.used === left.query && B.query.used === left.query,
            ranks_equal: JSON.stringify(signature(A)) === JSON.stringify(signature(B)),
            rag_sources_equal: JSON.stringify(A.rag?.available) === JSON.stringify(B.rag?.available),
            before_ms: left.response_ms, after_ms: right.response_ms, extraction_ms: B.query_understanding?.ms ?? null,
            extraction_valid: B.query_understanding?.validation === 'valid', delta_ms: right.response_ms - left.response_ms }
    })
    const comparable = pairs.filter(pair => pair.comparable)
    const all = rows => ({ n: rows.length, same_query: rows.filter(row => row.query_equal).length,
        same_ranks: rows.filter(row => row.ranks_equal).length, same_rag_sources: rows.filter(row => row.rag_sources_equal).length,
        before_ms: distribution(rows.map(row => row.before_ms)), after_ms: distribution(rows.map(row => row.after_ms)),
        extraction_ms: distribution(rows.map(row => row.extraction_ms)), delta_ms: distribution(rows.map(row => row.delta_ms)) })
    groups.paired = { rows: pairs, all: all(comparable), first: all(comparable.filter(row => row.turn === 'first')),
        after_search: all(comparable.filter(row => row.turn === 'after_search')) }
    write('controls-A', { mode: 'intention_only', runs: a })
    write('controls-B', { mode: 'intention_and_understanding', runs: b })
    const probes = readLines('routing-probes')
    groups.routing_probes = probes.map(row => ({ id: row.id, intent: row.diagnostics.intent?.intent,
        retrieval: row.diagnostics.execution.retrieval_started, would_clarify: row.diagnostics.query_understanding?.would_clarify ?? null }))
    write('routing-probes', { runs: probes })
}
write('metrics', { dataset: freeze, ...groups })
