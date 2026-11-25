import { test } from 'node:test'
import assert from 'node:assert/strict'
import { groupConversations } from '../../resources/js/composables/assistantHistoryGroups.js'

test('groups last activity using local calendar days', () => {
    const now = new Date(2026, 10, 25, 15)
    const dates = [new Date(2026, 10, 25, 2), new Date(2026, 10, 24), new Date(2026, 10, 19), new Date(2026, 10, 17)]
    const groups = groupConversations(dates.map((date, id) => ({ id, updated_at: date.toISOString() })), now)
    assert.deepEqual(groups.map(group => group.label), ['Сегодня', 'Вчера', 'Предыдущие 7 дней', 'Более ранние'])
    assert.deepEqual(groups.map(group => group.items[0].id), [0, 1, 2, 3])
})
