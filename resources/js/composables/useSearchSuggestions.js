import { ref, watch } from 'vue'

const emptyRows = () => ({ artworks: [], authors: [], tags: [] })

export function useSearchSuggestions(query, type, lookup, { delay = 200 } = {}) {
    const rows = ref(emptyRows())
    const error = ref('')

    watch([query, type], ([value, kind], previous, onCleanup) => {
        rows.value = emptyRows()
        error.value = ''
        const text = value.trim()
        if (!text) return

        const controller = new AbortController()
        const timeout = setTimeout(async () => {
            try {
                const suggestions = await lookup(text, kind, controller.signal)
                if (controller.signal.aborted) return
                rows.value = {
                    artworks: suggestions.filter(item => item.type === 'artwork'),
                    authors: suggestions.filter(item => item.type === 'author'),
                    tags: suggestions.filter(item => item.type === 'tag'),
                }
            } catch {
                if (!controller.signal.aborted) error.value = 'Не удалось выполнить поиск. Попробуйте ещё раз.'
            }
        }, delay)

        onCleanup(() => {
            clearTimeout(timeout)
            controller.abort()
        })
    }, { flush: 'sync' })

    return { rows, error }
}
