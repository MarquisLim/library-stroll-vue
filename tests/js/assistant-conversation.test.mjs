import assert from 'node:assert/strict'
import { test } from 'node:test'
import { effectScope } from 'vue'
import { assistantPreviewUrl, ragReply } from '../../resources/js/composables/assistantReplies.js'
import { useAssistantConversation } from '../../resources/js/composables/useAssistantConversation.js'

const id = number => `00000000-0000-4000-8000-${String(number).padStart(12, '0')}`
const first = id(1)
const second = id(2)
const source = (number, artwork_id = number, title = 'Работа') => ({ source_ref: `s${number}`, artwork_id, title, url: `/artworks/${artwork_id}` })
const fact = (number, text = 'Проверенный факт') => ({ source_ref: `s${number}`, fact_ref: 'f1', text })
const response = (sequence, status = 'ok') => ({ turn_id: id(sequence + 10), sequence, status, answer: [fact(1)], sources: [source(1)] })
const saved = (sequence, user_query = `Запрос ${sequence}`, source_status = 'available') => ({
    ...response(sequence), id: id(sequence + 10), user_query, result_status: 'ok', source_status,
})
const historyPage = (conversation, turns = [], has_more = false) => ({ conversation: { id: conversation }, turns, pagination: { has_more } })
const list = (items, has_more = false) => ({ conversations: items.map(id => ({ id })), pagination: { has_more } })
const http = status => ({ response: { status } })

function fixture(t, overrides = {}) {
    const calls = []
    const client = {
        list: async page => { calls.push(['list', page]); return list([first, second]) },
        show: async (id, page) => { calls.push(['show', id, page]); return historyPage(id) },
        create: async () => { calls.push(['create']); return { conversation: { id: first } } },
        send: async (id, query) => { calls.push(['send', id, query]); return response(1) },
        remove: async id => { calls.push(['remove', id]) },
        ...overrides,
    }
    const scope = effectScope()
    const chat = scope.run(() => useAssistantConversation(client))
    t.after(() => scope.stop())
    return { chat, calls, scope }
}

test('creates on first send, filters double clicks and keeps the same server UUID for a follow-up', async t => {
    let finish
    const sent = []
    const { chat, calls } = fixture(t, { send: (id, query) => {
        sent.push([id, query])
        if (sent.length === 2) return Promise.resolve(response(2, 'clarification_required'))
        return new Promise(resolve => { finish = resolve })
    } })
    chat.open()
    assert.deepEqual(calls, [])
    chat.draft.value = ' птицы у воды '
    const request = chat.send()
    const duplicate = chat.send()
    await duplicate
    assert.equal(chat.pending.value, true)
    finish(response(1))
    await request
    assert.equal(chat.activeId.value, first)
    assert.equal(chat.turns.value[0].reply.fragments[0].source.url, '/artworks/1')

    chat.draft.value = 'А какие из них относятся к XIX веку?'
    await chat.send()
    assert.equal(calls.filter(([action]) => action === 'create').length, 1)
    assert.deepEqual(sent, [[first, 'птицы у воды'], [first, 'А какие из них относятся к XIX веку?']])
    assert.deepEqual(chat.turns.value.map(turn => turn.sequence), [1, 2])
    assert.equal(chat.turns.value[1].reply.status, 'clarification_required')
})

test('restores chronological history and deduplicates older and repeated pages', async t => {
    const { chat } = fixture(t, {
        list: async page => page === 1 ? list([first, second], true) : list([second, id(3)]),
        show: async (id, page) => page === 1 ? historyPage(id, [saved(4), saved(3)], true) : historyPage(id, [saved(3), saved(2), saved(1)]),
    })
    await chat.loadList()
    await chat.loadList(true)
    assert.deepEqual(chat.conversations.value.map(item => item.id), [first, second, id(3)])
    await chat.open(first)
    await chat.loadHistory(true)
    assert.deepEqual(chat.turns.value.map(turn => turn.sequence), [1, 2, 3, 4])
    assert.equal(chat.turns.value[0].reply.fragments.length, 1)
})

test('a late GET after switching or refreshing cannot overwrite current history', async t => {
    const requests = []
    const { chat } = fixture(t, { show: (id, page, signal) => new Promise(resolve => requests.push({ id, signal, resolve })) })
    const a = chat.open(first)
    const b = chat.open(second)
    const refresh = chat.refresh()
    assert(requests[0].signal.aborted)
    assert(requests[1].signal.aborted)
    requests[2].resolve(historyPage(second, [saved(5)]))
    await refresh
    requests[1].resolve(historyPage(second, [saved(4)]))
    requests[0].resolve(historyPage(first, [saved(8)]))
    await Promise.all([a, b])
    assert.equal(chat.activeId.value, second)
    assert.deepEqual(chat.turns.value.map(turn => turn.sequence), [5])
})

test('409 refreshes actual history without repeating the POST', async t => {
    const { chat, calls } = fixture(t, { send: async () => { throw http(409) } })
    await chat.open(first)
    chat.draft.value = 'поиск'
    await chat.send()
    assert.deepEqual(calls, [['show', first, 1], ['show', first, 1]])
    assert.match(chat.notice.value.message, /Диалог изменился/)
    assert.equal(chat.notice.value.refreshRequired, false)
    chat.restoreDraft()
    assert.equal(chat.draft.value, 'поиск')
})

test('cancelled POST can be recovered from server history and ignores its late body', async t => {
    let finish
    let signal
    let updates = 0
    const { chat } = fixture(t, {
        show: async id => historyPage(id, ++updates === 1 ? [] : [saved(1, 'море')]),
        send: (id, query, requestSignal) => new Promise(resolve => { signal = requestSignal; finish = resolve }),
    })
    await chat.open(first)
    chat.draft.value = 'море'
    const post = chat.send()
    chat.cancel()
    assert(signal.aborted)
    assert.match(chat.notice.value.message, /Ход мог сохраниться/)
    chat.draft.value = 'новый запрос'
    assert.equal(chat.canSend.value, false)
    await chat.refresh()
    assert.equal(chat.notice.value.saved, true)
    finish(response(2))
    await post
    assert.deepEqual(chat.turns.value.map(turn => turn.sequence), [1])
})

test('a failed connection after POST requires a refresh and preserves the draft for manual recovery', async t => {
    let attempts = 0
    const { chat } = fixture(t, { send: async () => { attempts++; throw new Error('network') } })
    await chat.open(first)
    chat.draft.value = 'морской сюжет'
    await chat.send()
    assert(chat.notice.value.refreshRequired)
    await chat.refresh()
    chat.restoreDraft()
    assert.equal(attempts, 1)
    assert.equal(chat.draft.value, 'морской сюжет')
})

test('deleting the active dialog drops its old reply and returns to an empty workspace', async t => {
    let finish
    let signal
    let removed = false
    const { chat } = fixture(t, {
        list: async () => list(removed ? [second] : [first, second]),
        remove: async () => { removed = true },
        send: (id, query, requestSignal) => new Promise(resolve => { signal = requestSignal; finish = resolve }),
    })
    await chat.loadList()
    await chat.open(first)
    chat.draft.value = 'горы'
    const pending = chat.send()
    assert.equal(await chat.remove(first), true)
    assert(signal.aborted)
    finish(response(1))
    await pending
    assert.equal(chat.activeId.value, null)
    assert.deepEqual(chat.turns.value, [])
    assert.deepEqual(chat.conversations.value.map(item => item.id), [second])
})

test('RAG binds citations per turn, rejects noncanonical sources and supports null titles without preview metadata', () => {
    const state = ragReply({ status: 'ok', sources: [source(7, 42, null), { ...source(1), url: '//external.example/artworks/1' }], answer: [fact(7), fact(1), fact(2)] })
    assert.equal(state.fragments.length, 1)
    assert.deepEqual(state.fragments[0].source, { artwork_id: 42, title: null, url: '/artworks/42', number: 1 })
    const other = ragReply({ status: 'ok', sources: [source(7, 50)], answer: [fact(7)] })
    assert.equal(other.fragments[0].source.artwork_id, 50)
})

test('uses only rechecked sources in partial history and does not restore fallback cards', () => {
    const partial = ragReply({ ...saved(1, 'старый запрос', 'partial'), answer: [fact(1), fact(2)] }, true)
    assert.equal(partial.fragments.length, 1)
    assert.equal(partial.sourceStatus, 'partial')
    const unavailable = ragReply(saved(1, 'старый запрос', 'unavailable'), true)
    assert.equal(unavailable.fragments.length, 0)
    assert.equal(unavailable.results.length, 0)
    const liveFallback = ragReply(response(1, 'search_fallback'))
    assert.equal(liveFallback.results.length, 1)
    assert.equal(liveFallback.fragments.length, 0)
    const savedFallback = ragReply({ ...saved(1), result_status: 'search_fallback', source_status: 'none' }, true)
    assert.equal(savedFallback.results.length, 0)
})

test('fresh cards request a new access check from the protected thumbnail route', () => {
    const first = assistantPreviewUrl(42)
    const second = assistantPreviewUrl(42)
    assert.match(first, /^\/assistant\/artworks\/42\/preview\?v=\d+-\d+$/)
    assert.notEqual(first, second)
})
