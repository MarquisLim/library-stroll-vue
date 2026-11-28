const assert = require('node:assert/strict')
const { readFileSync, writeFileSync } = require('node:fs')
const path = require('node:path')

const output = path.resolve(process.argv[2] ?? 'storage/app/ai-stage-05-04/5.4-1b')
const read = name => JSON.parse(readFileSync(path.join(output, name + '.json'), 'utf8'))
const { controls, states } = require('./assistant-query-vision-controls.json')
const before = read('vision-corpus-before')
const after = read('vision-corpus-after')
assert.deepEqual(before, after)
assert.equal(before.current_vision, 511)
const metadata = read('vision-selected')
const fields = Object.keys(before.field_availability)
const matrix = rows => Object.fromEntries(states.map(visual => [visual, Object.fromEntries(states.map(automatic => [automatic,
    rows.filter(row => row.visual === visual && row.automatic === automatic).length]))]))
const summarize = rows => ({ n: rows.length, matrix: matrix(rows),
    insufficient_metadata: rows.filter(row => row.automatic === 'insufficient').length,
    agreement_with_decisive_visual: rows.filter(row => row.visual !== 'insufficient' && row.automatic === row.visual).length,
    disagree_with_decisive_visual: rows.filter(row => row.visual !== 'insufficient' && row.automatic !== 'insufficient' && row.automatic !== row.visual).length })

const reviews = controls.map(([id, artwork, component, condition, visual, visual_note, patterns]) => {
    assert(states.includes(visual))
    assert(metadata[artwork])
    const by_field = Object.fromEntries(fields.map(field => {
        const source = [metadata[artwork][field]].flat().filter(value => typeof value === 'string')
        const evidence = Object.fromEntries(['confirmed', 'contradicted'].map(state => [state, patterns[field]?.[state]
            ? source.filter(value => new RegExp(patterns[field][state], 'iu').test(value)) : []]))
        const decision = Object.keys(evidence).filter(key => evidence[key].length)
        return [field, { state: decision.length === 1 ? decision[0] : 'insufficient', evidence }]
    }))
    const decisions = [...new Set(Object.values(by_field).map(row => row.state).filter(state => state !== 'insufficient'))]
    return { id, artwork, component, condition, visual, visual_note, automatic: decisions.length === 1 ? decisions[0] : 'insufficient', by_field }
})
const categories = [...new Set(reviews.map(row => row.component))]
writeFileSync(path.join(output, 'vision-review.json'), JSON.stringify({
    corpus: before, summary: summarize(reviews),
    by_component: Object.fromEntries(categories.map(key => [key, summarize(reviews.filter(row => row.component === key))])),
    by_field: Object.fromEntries(fields.map(field => [field, summarize(reviews.map(row => ({ visual: row.visual, automatic: row.by_field[field].state })))])),
    reviews,
}, null, 2) + '\n')
