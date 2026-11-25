import axios from 'axios'
import { computed, onScopeDispose, ref } from 'vue'

export const assistantQueryLimit = 500

const errors = {
    401: 'Войдите в аккаунт, чтобы продолжить поиск.',
    403: 'Для доступа к ассистенту подтвердите адрес электронной почты.',
    404: 'AI-ассистент сейчас недоступен.',
    413: 'Запрос слишком большой. Сократите его и попробуйте ещё раз.',
    419: 'Сессия устарела. Обновите страницу или войдите снова.',
    422: 'Проверьте запрос. Допустимо от 1 до 500 символов.',
    429: 'Достигнут лимит запросов. Попробуйте чуть позже.',
    503: 'Поиск временно недоступен. Попробуйте чуть позже.',
}

const clarifications = {
    ambiguous_subject: 'Уточните, что должно быть изображено. Например, назовите объект или место.',
    too_broad: 'Сузьте запрос. Например, добавьте тему, место или визуальную деталь.',
}

async function requestSearch(query, signal) {
    const { data } = await axios.post('/assistant/search', { query, limit: 10 }, { signal })
    return data
}

function searchResult(data) {
    if (data?.status === 'no_results') return { status: 'empty' }
    if (data?.status === 'clarification_required') {
        const code = data.clarification_code
        return {
            status: 'clarify',
            message: Object.hasOwn(clarifications, code) ? clarifications[code] : 'Попробуйте уточнить запрос, добавив конкретную деталь.',
        }
    }
    if (data?.status === 'ok' && Array.isArray(data.results)) {
        const results = data.results.filter(result => Number.isSafeInteger(result?.artwork_id)
            && result.artwork_id > 0
            && typeof result.title === 'string'
            && result.url === `/artworks/${result.artwork_id}`)
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
                    message: errors[error?.response?.status]
                        ?? 'Не удалось связаться с поиском. Проверьте подключение и попробуйте ещё раз.',
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
