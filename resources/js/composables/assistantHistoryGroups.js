export function groupConversations(conversations, now = new Date()) {
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
    const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1)
    const week = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 7)
    const groups = [
        { label: 'Сегодня', items: [] }, { label: 'Вчера', items: [] },
        { label: 'Предыдущие 7 дней', items: [] }, { label: 'Более ранние', items: [] },
    ]
    for (const item of conversations) {
        const time = new Date(item.updated_at)
        const index = time >= today ? 0 : time >= yesterday ? 1 : time >= week ? 2 : 3
        groups[index].items.push(item)
    }
    return groups.filter(group => group.items.length)
}
