export const assistantQueryLimit = 500
let previewRequest = 0

export function assistantPreviewUrl(artworkId) {
    // A fresh card must reach the access check even if this document already decoded the image.
    return `/assistant/artworks/${artworkId}/preview?v=${Date.now()}-${++previewRequest}`
}

const errors = {
    401: 'Войдите в аккаунт, чтобы продолжить.',
    403: 'У вашего аккаунта нет прав на это действие.',
    404: 'Этот раздел ассистента сейчас недоступен.',
    409: 'Диалог изменился, достигнут лимит или источники больше не актуальны.',
    413: 'Запрос слишком большой. Сократите его и попробуйте ещё раз.',
    419: 'Сессия устарела. Обновите страницу или войдите снова.',
    422: 'Проверьте запрос. Допустимо от 1 до 500 символов.',
    429: 'Достигнут лимит запросов. Попробуйте чуть позже.',
    503: 'Сервис временно недоступен. Попробуйте чуть позже.',
}

export function assistantError(error) {
    return errors[error?.response?.status] ?? 'Не удалось связаться с ассистентом. Проверьте подключение.'
}

const clarifications = {
    ambiguous_subject: 'Уточните, что должно быть изображено. Например, назовите объект или место.',
    too_broad: 'Сузьте запрос. Например, добавьте тему, место или визуальную деталь.',
}

export function clarification(code) {
    return Object.hasOwn(clarifications, code) ? clarifications[code] : 'Попробуйте уточнить запрос, добавив конкретную деталь.'
}

export function isArtworkCard(card) {
    return Number.isSafeInteger(card?.artwork_id) && card.artwork_id > 0
        && (card.title === null || typeof card.title === 'string') && card.url === `/artworks/${card.artwork_id}`
}

export function ragReply(data, fromHistory = false) {
    const sourceStatus = fromHistory ? data.source_status : null
    const status = fromHistory ? data.result_status : data.status
    const sources = []
    const references = new Map()
    if (sourceStatus !== 'unavailable' && sourceStatus !== 'none' && Array.isArray(data.sources)) {
        const counts = new Map()
        for (const source of data.sources) counts.set(source?.source_ref, (counts.get(source?.source_ref) ?? 0) + 1)
        for (const source of data.sources) {
            if (!isArtworkCard(source) || typeof source.source_ref !== 'string'
                || !/^s[1-9]\d*$/.test(source.source_ref) || counts.get(source.source_ref) !== 1) continue
            const card = { artwork_id: source.artwork_id, title: source.title, url: source.url, number: sources.length + 1 }
            references.set(source.source_ref, card)
            sources.push(card)
        }
    }

    const fragments = []
    if (status === 'ok' && Array.isArray(data.answer)) {
        for (const fragment of data.answer) {
            const source = references.get(fragment?.source_ref)
            if (!source || typeof fragment.text !== 'string' || !fragment.text.trim()
                || typeof fragment.fact_ref !== 'string' || !/^f(?:[1-9]|1[01])$/.test(fragment.fact_ref)) continue
            fragments.push({ text: fragment.text, source })
        }
    }

    return { status, fragments, results: sources, sourceStatus, message: status === 'clarification_required' ? clarification(data.clarification_code) : null,
        ...(Object.hasOwn(data, 'diagnostics') ? { diagnostics: data.diagnostics } : {}) }
}
