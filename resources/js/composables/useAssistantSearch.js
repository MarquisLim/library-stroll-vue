import axios from 'axios'
import { computed, onScopeDispose, ref } from 'vue'
import { assistantError, assistantQueryLimit, clarification, isArtworkCard } from './assistantReplies.js'

export { assistantQueryLimit } from './assistantReplies.js'

async function requestSearch(query, signal) {
    const { data } = await axios.post('/assistant/search', { query, limit: 10 }, { signal })
    return data
}

function searchResult(data) {
    if (data?.status === 'no_results') return { status: 'empty' }
    if (data?.status === 'clarification_required') {
        return { status: 'clarify', message: clarification(data.clarification_code) }
    }
    if (data?.status === 'ok' && Array.isArray(data.results)) {
        const results = data.results.filter(isArtworkCard)
        if (results.length) return { status: 'success', results }
    }

    return { status: 'error', message: 'Не удалось прочитать результаты поиска. Попробуйте ещё раз.' }
}

export function useAssistantSearch(search = requestSearch) {
    const draft = ref('')
    const turns = ref([])
    const active = ref(null)
    const pending = computed(() => active.value !== null)
    const canSend = computed(() => {
        const query = draft.value.trim()
        return !pending.value && query.length > 0 && Array.from(query).length <= assistantQueryLimit
    })
    let nextId = 0

    async function run(turn) {
        const controller = new AbortController()
        const requestId = ++nextId
        active.value = { requestId, controller, turn }
        turn.reply = { status: 'pending' }

        try {
            const data = await search(turn.query, controller.signal)
            if (active.value?.requestId === requestId) turn.reply = searchResult(data)
        } catch (error) {
            if (active.value?.requestId === requestId) {
                turn.reply = {
                    status: 'error',
                    message: assistantError(error),
                }
            }
        } finally {
            if (active.value?.requestId === requestId) active.value = null
        }
    }

    function send() {
        if (!canSend.value) return
        const turn = { id: ++nextId, query: draft.value.trim(), reply: { status: 'pending' } }
        draft.value = ''
        turns.value.push(turn)
        return run(turns.value.at(-1))
    }

    function cancel() {
        if (!active.value) return
        const { controller, turn } = active.value
        active.value = null
        turn.reply = { status: 'cancelled' }
        controller.abort()
    }

    function retry(turn) {
        if (!pending.value && ['error', 'cancelled'].includes(turn.reply.status)) return run(turn)
    }

    function reset() {
        cancel()
        draft.value = ''
        turns.value = []
    }

    onScopeDispose(cancel)

    return { draft, turns, pending, canSend, send, cancel, retry, reset }
}
