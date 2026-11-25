import axios from 'axios'
import { computed, onScopeDispose, ref, shallowRef } from 'vue'
import { assistantError, assistantQueryLimit, ragReply } from './assistantReplies.js'

const endpoint = '/assistant/conversations'
const uuid = /^[a-f\d]{8}(?:-[a-f\d]{4}){3}-[a-f\d]{12}$/i
const validId = (id) => typeof id === 'string' && uuid.test(id)
const options = (signal) => ({ signal, timeout: 90_000, headers: { Accept: 'application/json' } })
const api = {
    list: (page, signal) => axios.get(endpoint, { ...options(signal), params: { page } }).then(response => response.data),
    show: (id, page, signal) => axios.get(`${endpoint}/${id}`, { ...options(signal), params: { page } }).then(response => response.data),
    create: (signal) => axios.post(endpoint, {}, options(signal)).then(response => response.data),
    send: (id, query, signal) => axios.post(`${endpoint}/${id}/turns`, { query, limit: 10 }, options(signal)).then(response => response.data),
    remove: (id, signal) => axios.delete(`${endpoint}/${id}`, options(signal)),
}

function mergeById(existing, incoming) {
    return [...new Map([...existing, ...incoming].map(item => [item.id, item])).values()]
}

function sortTurns(turns) {
    return turns.sort((first, second) => first.sequence - second.sequence)
}

function historyTurn(turn) {
    return { id: turn.id, sequence: turn.sequence, query: turn.user_query, reply: ragReply(turn, true) }
}

export function useAssistantConversation(client = api) {
    const draft = ref('')
    const activeId = ref(null)
    const rows = ref([])
    const conversations = ref([])
    const listPage = ref(0)
    const listMore = ref(false)
    const listError = ref('')
    const listRequest = shallowRef(null)
    const historyPage = ref(0)
    const historyMore = ref(false)
    const historyError = ref('')
    const historyRequest = shallowRef(null)
    const post = shallowRef(null)
    const deletion = shallowRef(null)
    const deleteError = ref('')
    const recoveries = ref(new Map())
    const notice = computed(() => recoveries.value.get(activeId.value))
    const pending = computed(() => post.value !== null)
    const turns = computed(() => post.value ? [...rows.value, {
        id: 'pending', query: post.value.query, reply: { status: 'pending', message: 'Готовим ответ…' },
    }] : rows.value)
    const canSend = computed(() => !pending.value && !historyRequest.value && !deletion.value
        && (!activeId.value || historyPage.value > 0 || rows.value.length > 0) && !notice.value?.refreshRequired
        && draft.value.trim().length > 0 && Array.from(draft.value.trim()).length <= assistantQueryLimit)

    async function loadList(more = false) {
        if (more && (!listMore.value || listRequest.value)) return false
        listRequest.value?.abort()
        const operation = new AbortController()
        listRequest.value = operation
        listError.value = ''
        const page = more ? listPage.value + 1 : 1
        try {
            const data = await client.list(page, operation.signal)
            if (listRequest.value !== operation) return false
            const items = data.conversations.filter(item => validId(item.id))
            conversations.value = mergeById(more ? conversations.value : [], items)
            listPage.value = page
            listMore.value = data.pagination.has_more
            return true
        } catch (error) {
            if (listRequest.value === operation) listError.value = assistantError(error)
            return false
        } finally {
            if (listRequest.value === operation) listRequest.value = null
        }
    }

    async function loadHistory(more = false) {
        const id = activeId.value
        if (!id || pending.value || (more && (!historyMore.value || historyRequest.value))) return false
        historyRequest.value?.abort()
        const operation = new AbortController()
        historyRequest.value = operation
        const page = more ? historyPage.value + 1 : 1
        historyError.value = ''
        if (!more) {
            rows.value = []
            historyPage.value = 0
            historyMore.value = false
        }
        const current = () => historyRequest.value === operation && activeId.value === id
        try {
            const data = await client.show(id, page, operation.signal)
            if (!current()) return false
            if (data.conversation.id !== id) throw new Error('Unexpected conversation')
            const incoming = data.turns.filter(turn => validId(turn.id) && Number.isSafeInteger(turn.sequence)).map(historyTurn)
            // Overlapping older pages can include a turn already refreshed or received from POST.
            rows.value = sortTurns(mergeById(incoming, more ? rows.value : []))
            historyPage.value = page
            historyMore.value = data.pagination.has_more
            const recovery = recoveries.value.get(id)
            if (recovery && !more) {
                const saved = incoming.some(turn => turn.sequence > recovery.after && turn.query === recovery.query)
                recoveries.value.set(id, { ...recovery, refreshRequired: false, saved: recovery.saved || saved })
            }
            return true
        } catch (error) {
            if (current()) historyError.value = error?.response?.status === 404 ? 'Диалог больше недоступен.' : assistantError(error)
            return false
        } finally {
            if (current()) historyRequest.value = null
        }
    }

    function recordRecovery(operation, message, refreshRequired) {
        recoveries.value.set(operation.id, { message, query: operation.query, after: operation.after, refreshRequired })
    }

    function cancel() {
        const operation = post.value
        if (!operation) return
        post.value = null
        recordRecovery(operation, operation.id
            ? 'Ожидание отменено. Ход мог сохраниться. Обновите историю перед повторной отправкой.'
            : 'Ожидание отменено. Диалог мог создаться. Обновите список истории перед повторной отправкой.', true)
        operation.controller.abort()
    }

    function open(id = null) {
        if (id !== null && !validId(id)) return
        cancel()
        historyRequest.value?.abort()
        historyRequest.value = null
        activeId.value = id
        rows.value = []
        draft.value = ''
        historyPage.value = 0
        historyMore.value = false
        historyError.value = ''
        if (id) return loadHistory()
    }

    async function refresh() {
        if (activeId.value) return loadHistory()
        const refreshed = await loadList()
        const recovery = recoveries.value.get(null)
        if (refreshed && recovery) recoveries.value.set(null, { ...recovery, refreshRequired: false })
        return refreshed
    }

    function restoreDraft() {
        if (!notice.value || notice.value.saved || notice.value.refreshRequired) return
        draft.value = notice.value.query
        recoveries.value.delete(activeId.value)
    }

    async function send() {
        if (!canSend.value) return
        const operation = { id: activeId.value, query: draft.value.trim(), after: rows.value.at(-1)?.sequence ?? 0, controller: new AbortController() }
        const current = () => post.value === operation && activeId.value === operation.id
        post.value = operation
        draft.value = ''
        recoveries.value.delete(operation.id)
        let reload = false
        try {
            if (!operation.id) {
                const { conversation } = await client.create(operation.controller.signal)
                if (!current()) return
                if (!validId(conversation.id)) throw new Error('Invalid conversation')
                operation.id = conversation.id
                activeId.value = conversation.id
                historyPage.value = 1
                conversations.value = mergeById([conversation], conversations.value.filter(item => item.id !== conversation.id))
                void loadList()
            }
            const data = await client.send(operation.id, operation.query, operation.controller.signal)
            if (!current()) return
            if (!validId(data.turn_id) || !Number.isSafeInteger(data.sequence)) throw new Error('Unconfirmed turn')
            rows.value = sortTurns(mergeById(rows.value, [{ id: data.turn_id, sequence: data.sequence, query: operation.query, reply: ragReply(data) }]))
            if (historyMore.value) {
                // POST shifted offset-based pages. Re-read the current boundary on the next pagination request.
                historyPage.value = Math.max(0, historyPage.value - 1)
            }
            void loadList()
        } catch (error) {
            if (!current()) return
            const code = error?.response?.status
            const uncertain = !code || code >= 500
            reload = code === 409
            recordRecovery(operation, uncertain
                ? `${assistantError(error)} ${operation.id ? 'Ход мог сохраниться. Обновите историю' : 'Диалог мог создаться. Обновите список истории'} перед повторной отправкой.`
                : assistantError(error), uncertain || reload)
        } finally {
            if (current()) post.value = null
        }
        if (reload) await refresh()
    }

    async function remove(id) {
        if (!validId(id) || deletion.value) return false
        const operation = new AbortController()
        deletion.value = operation
        deleteError.value = ''
        if (activeId.value === id) cancel()
        try {
            await client.remove(id, operation.signal)
            if (deletion.value !== operation) return false
            if (activeId.value === id) open()
            recoveries.value.delete(id)
            conversations.value = conversations.value.filter(item => item.id !== id)
            void loadList()
            return true
        } catch (error) {
            if (deletion.value === operation) deleteError.value = assistantError(error)
            return false
        } finally {
            if (deletion.value === operation) deletion.value = null
        }
    }

    onScopeDispose(() => {
        for (const request of [listRequest, historyRequest, deletion]) {
            request.value?.abort()
            request.value = null
        }
        post.value?.controller.abort()
        post.value = null
    })

    return { draft, activeId, turns, conversations, listMore, listError, listRequest, historyMore, historyError,
        historyRequest, pending, canSend, notice, deletion, deleteError, loadList, loadHistory, open, refresh, send, cancel, restoreDraft, remove }
}
