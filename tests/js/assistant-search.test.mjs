import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope } from 'vue'
import { useAssistantSearch } from '../../resources/js/composables/useAssistantSearch.js'

function fixture(t) {
    const scope = effectScope()
    const calls = []
    const chat = scope.run(() => useAssistantSearch((query, signal) => new Promise((resolve, reject) => {
        calls.push({ query, signal, resolve, reject })
    })))
    t.after(() => scope.stop())
    return { chat, calls, scope }
}

function artwork(id, url = `/artworks/${id}`) {
    return { artwork_id: id, title: `Работа ${id}`, url }
}

test('sends once, trims the query and preserves the order of canonical artwork links', async t => {
    const { chat, calls } = fixture(t)
    chat.draft.value = '  птицы у воды  '
    const request = chat.send()
    chat.send()
    assert.equal(chat.pending.value, true)
    assert.equal(chat.turns.value[0].reply.status, 'pending')
    assert.equal(chat.draft.value, '')
    assert.deepEqual(calls.map(call => call.query), ['птицы у воды'])

    const results = [artwork(8), artwork(5)]
    calls[0].resolve({ status: 'ok', results: [results[0], artwork(3, '//example.test/artworks/3'), artwork(4, '/artworks/9'), artwork(7, '/artworks/7?x=1'), results[1]] })
    await request
    assert.deepEqual(chat.turns.value[0].reply, { status: 'success', results })
    assert.equal(chat.pending.value, false)
})

test('checks the 500-character Unicode limit and rejects empty input', t => {
    const { chat } = fixture(t)
    chat.draft.value = '  '
    assert.equal(chat.canSend.value, false)
    chat.draft.value = '🪶'.repeat(500)
    assert.equal(chat.canSend.value, true)
    chat.draft.value += 'a'
    assert.equal(chat.canSend.value, false)
})

test('shows empty results and uses fixed copy for known and unknown clarification codes', async t => {
    const { chat, calls } = fixture(t)
    const responses = [
        { status: 'no_results', results: [] },
        { status: 'clarification_required', clarification_code: 'too_broad' },
        { status: 'clarification_required', clarification_code: '<b>unexpected</b>' },
    ]
    for (const [index, response] of responses.entries()) {
        chat.draft.value = 'картины'
        const request = chat.send()
        calls[index].resolve(response)
        await request
    }
    const replies = chat.turns.value.map(turn => turn.reply)
    assert.deepEqual(replies.map(reply => reply.status), ['empty', 'clarify', 'clarify'])
    assert.match(replies[1].message, /^Сузьте запрос/)
    assert.doesNotMatch(replies[2].message, /unexpected/)
})

test('reports HTTP and network failures and retries only when asked', async t => {
    const { chat, calls } = fixture(t)
    chat.draft.value = 'горы'
    const request = chat.send()
    calls[0].reject({ response: { status: 429, data: 'private server detail' } })
    await request
    assert.equal(calls.length, 1)
    assert.match(chat.turns.value[0].reply.message, /лимит запросов/)

    const retry = chat.retry(chat.turns.value[0])
    calls[1].reject(new Error('private network detail'))
    await retry
    assert.match(chat.turns.value[0].reply.message, /Проверьте подключение/)
    assert.equal(chat.turns.value.length, 1)
    assert.deepEqual(calls.map(call => call.query), ['горы', 'горы'])
})

test('cancels, ignores late replies after reset and never modifies a new conversation', async t => {
    const { chat, calls } = fixture(t)
    chat.draft.value = 'пейзаж'
    const previous = chat.send()
    chat.cancel()
    assert.equal(calls[0].signal.aborted, true)
    assert.equal(chat.turns.value[0].reply.status, 'cancelled')
    chat.reset()
    assert.deepEqual(chat.turns.value, [])

    chat.draft.value = 'архитектура'
    const current = chat.send()
    calls[0].resolve({ status: 'ok', results: [artwork(1)] })
    await previous
    assert.equal(chat.pending.value, true)
    assert.equal(chat.turns.value.length, 1)
    assert.equal(chat.turns.value[0].reply.status, 'pending')
    calls[1].resolve({ status: 'no_results' })
    await current
    assert.equal(chat.turns.value[0].reply.status, 'empty')
})

test('leaving the page aborts the active request', async t => {
    const { chat, calls, scope } = fixture(t)
    chat.draft.value = 'море'
    const request = chat.send()
    scope.stop()
    assert.equal(calls[0].signal.aborted, true)
    calls[0].resolve({ status: 'ok', results: [artwork(1)] })
    await request
    assert.equal(chat.turns.value[0].reply.status, 'cancelled')
})
