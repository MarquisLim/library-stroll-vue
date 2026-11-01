import { test } from 'node:test'
import assert from 'node:assert/strict'
import { ref, effectScope } from 'vue'
import { useSearchSuggestions } from '../../resources/js/composables/useSearchSuggestions.js'

const tick = () => new Promise(resolve => setTimeout(resolve, 10))
function fixture(lookup, options = { delay: 0 }) {
    const query = ref('')
    const type = ref('all')
    const scope = effectScope()
    const state = scope.run(() => useSearchSuggestions(query, type, lookup, options))
    return { query, type, scope, ...state }
}

test('a late response never replaces results from the newer query', async () => {
    const pending = []
    const search = fixture((query, type, signal) => new Promise(resolve => pending.push({ query, signal, resolve })))
    try {
        search.query.value = 'old'
        await tick()
        search.query.value = 'new'
        await tick()
        assert.equal(pending[0].signal.aborted, true)
        pending[1].resolve([{ id: 2, type: 'artwork' }])
        await tick()
        pending[0].resolve([{ id: 1, type: 'artwork' }])
        await tick()
        assert.deepEqual(search.rows.value.artworks.map(item => item.id), [2])
    } finally { search.scope.stop() }
})

test('clearing input cancels requests and keeps results empty', async () => {
    let finish
    const search = fixture(() => new Promise(resolve => { finish = resolve }))
    try {
        search.query.value = 'art'
        await tick()
        search.query.value = ''
        finish([{ id: 1, type: 'tag' }])
        await tick()
        assert.deepEqual(search.rows.value, { artworks: [], authors: [], tags: [] })
    } finally { search.scope.stop() }
})

test('network failures are handled and changing tabs triggers a fresh lookup', async () => {
    const requests = []
    const search = fixture(async (query, type) => {
        requests.push(type)
        if (type === 'all') throw new Error('offline')
        return [{ id: 3, type: 'author' }]
    })
    try {
        search.query.value = 'artist'
        await tick()
        assert.notEqual(search.error.value, '')
        search.type.value = 'author'
        await tick()
        assert.deepEqual(requests, ['all', 'author'])
        assert.equal(search.error.value, '')
        assert.equal(search.rows.value.authors[0].id, 3)
    } finally { search.scope.stop() }
})

test('rapid edits are debounced and unmount cancels pending work', async () => {
    const calls = []
    const search = fixture(async query => { calls.push(query); return [] }, { delay: 20 })
    search.query.value = 'a'
    search.query.value = 'ab'
    search.query.value = 'abc'
    await new Promise(resolve => setTimeout(resolve, 35))
    assert.deepEqual(calls, ['abc'])
    search.query.value = 'unmounted'
    search.scope.stop()
    await new Promise(resolve => setTimeout(resolve, 35))
    assert.deepEqual(calls, ['abc'])
})
