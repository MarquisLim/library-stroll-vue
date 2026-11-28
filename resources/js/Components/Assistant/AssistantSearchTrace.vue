<script setup>
import { computed } from 'vue'

const { trace } = defineProps({ trace: Object })

const stages = {
    recent_history: 'История', intent: 'Классификация намерения', query_understanding: 'Структура запроса',
    resolver_context: 'Контекст диалога', resolver: 'Выбор прежних источников',
    normalizer: 'Нормализация', lexical: 'Лексический поиск', dense: 'Dense Search',
    rrf: 'Объединение RRF', hybrid: 'Hybrid Search', assistant_visibility: 'Доступные изображения',
    previous_sources: 'Источники из диалога', rag_pack: 'Подготовка фактов', composer: 'Выбор фактов',
    rag_recheck: 'Проверка источников', final: 'Итоговые карточки',
}
const actions = { new_search: 'Новый поиск', use_previous: 'Источники предыдущих ответов', clarify: 'Уточнение', informational: 'Информационный ответ' }
const methods = { rule: 'Локальное правило', llm: 'LLM', fallback: 'Резервное правило' }
const packing = { legacy: 'Стандартная', visual_priority: 'Приоритет визуальных фактов' }
const number = value => Number.isFinite(value) ? value.toLocaleString('ru-RU', { maximumFractionDigits: 3 }) : '—'
const execution = value => value === true ? 'Запущен' : value === false ? 'Пропущен' : '—'
const executed = computed(() => Object.entries(trace?.stages ?? {}).filter(([, details]) => details != null))
const lexical = computed(() => trace?.stages?.lexical)
const ranked = computed(() => [...(trace?.candidates ?? [])].filter(row => row.lexical_rank != null || row.dense_rank != null)
    .sort((left, right) => (left.rrf_rank ?? Infinity) - (right.rrf_rank ?? Infinity) || left.artwork_id - right.artwork_id))
</script>

<template>
    <details class="mt-4 rounded-xl border border-base-300 bg-base-100 text-sm">
        <summary class="cursor-pointer px-3 py-2.5 font-semibold">Как выполнен поиск</summary>
        <div v-if="trace" class="space-y-3 px-3 pb-3">
            <dl class="grid gap-x-4 gap-y-1 [overflow-wrap:anywhere] sm:grid-cols-[auto_1fr]">
                <dt>Исходный запрос</dt><dd>{{ trace.query.original }}</dd>
                <dt>Поисковый запрос</dt><dd>{{ trace.query.used ?? '—' }}</dd>
                <dt>Обработка диалога</dt><dd>{{ actions[trace.dialogue?.action] ?? '—' }}</dd>
                <dt>Решение обработчика</dt><dd>{{ actions[trace.dialogue?.resolver_action] ?? '—' }}</dd>
                <template v-if="trace.intent">
                    <dt>Намерение</dt><dd>{{ trace.intent.intent }}</dd>
                    <dt>Классификация</dt><dd>{{ methods[trace.intent.method] }} · {{ number(trace.intent.ms) }} мс</dd>
                    <dt>Причина fallback</dt><dd>{{ trace.intent.fallback_reason ?? trace.intent.context_reason ?? '—' }}</dd>
                    <dt>Токены классификации</dt><dd>{{ number(trace.intent.tokens?.prompt_tokens) }} вход, {{ number(trace.intent.tokens?.output_tokens) }} выход</dd>
                </template>
                <template v-if="trace.execution">
                    <dt>Поиск кандидатов</dt><dd>{{ execution(trace.execution.retrieval_started) }}</dd>
                    <dt>Composer</dt><dd>{{ execution(trace.execution.composer_started) }}</dd>
                    <dt>Вызовы LLM</dt><dd>{{ Object.entries(trace.llm_calls ?? {}).map(([stage, count]) => `${stages[stage]}: ${number(count)}`).join(' · ') }}</dd>
                </template>
                <dt>Фильтры</dt><dd>{{ trace.filters ? JSON.stringify(trace.filters) : '—' }}</dd>
                <dt>Время запроса</dt><dd>{{ number(trace.performance.total_ms) }} мс</dd>
                <dt>Запись диагностики</dt><dd>{{ number(trace.performance.capture_ms) }} мс</dd>
                <template v-if="trace.rag?.strategy">
                    <dt>Упаковка RAG</dt><dd>{{ packing[trace.rag.strategy] ?? trace.rag.strategy }}</dd>
                    <dt>Контекст RAG</dt><dd>{{ number(trace.rag.context_bytes) }} / {{ number(trace.rag.context_limit_bytes) }} байт</dd>
                    <dt>Токены composer</dt><dd>{{ number(trace.rag.composer_usage?.prompt_tokens) }} вход, {{ number(trace.rag.composer_usage?.output_tokens) }} выход</dd>
                </template>
            </dl>
            <details v-if="trace.query_understanding">
                <summary class="cursor-pointer font-semibold">Структура запроса</summary>
                <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
                    <dt>Извлечение</dt><dd>{{ methods[trace.query_understanding.method] }} · {{ number(trace.query_understanding.ms) }} мс</dd>
                    <dt>Валидация</dt><dd>{{ trace.query_understanding.validation }}</dd>
                    <dt>Причина fallback</dt><dd>{{ trace.query_understanding.fallback_reason ?? '—' }}</dd>
                    <dt>Токены извлечения</dt><dd>{{ number(trace.query_understanding.tokens?.prompt_tokens) }} вход, {{ number(trace.query_understanding.tokens?.output_tokens) }} выход</dd>
                </dl>
                <p v-if="trace.query_understanding.would_clarify" class="mt-2">Экспериментальная проверка предлагает уточнить запрос.</p>
                <p v-if="trace.query_understanding.redacted" class="mt-2">Структура скрыта при очистке исходного текста.</p>
                <pre v-else-if="trace.query_understanding.structure" class="mt-2 whitespace-pre-wrap [overflow-wrap:anywhere]">{{ JSON.stringify(trace.query_understanding.structure, null, 2) }}</pre>
            </details>
            <div v-if="executed.length" class="overflow-x-auto">
                <table class="table table-xs w-full">
                    <thead><tr><th>Этап</th><th>Количество</th><th>Время, мс</th></tr></thead>
                    <tbody>
                        <tr v-for="[key, stage] in executed" :key="key">
                            <th scope="row">{{ stages[key] ?? key }}</th><td>{{ stage.count ?? '—' }}</td><td>{{ number(stage.ms) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="trace.dense_service">
                Embedding {{ number(trace.dense_service.embedding_ms) }} мс · Qdrant {{ number(trace.dense_service.qdrant_ms) }} мс
                · Проверка доступности {{ number(trace.dense_service.visibility_ms) }} мс
            </p>
            <details v-if="lexical?.strategy" class="space-y-2">
                <summary class="cursor-pointer font-semibold">Лексическая выдача</summary>
                <dl class="grid gap-x-4 gap-y-1 [overflow-wrap:anywhere] sm:grid-cols-[auto_1fr]">
                    <dt>Стратегия</dt><dd>{{ lexical.strategy }}</dd>
                    <dt>Термины</dt><dd>{{ lexical.terms_redacted ? 'Скрыты' : lexical.terms?.join(', ') || '—' }}</dd>
                    <template v-if="lexical.fallback_reason">
                        <dt>Запрошенная стратегия</dt><dd>{{ lexical.requested_strategy }}</dd>
                    </template>
                    <dt>Причина fallback</dt><dd>{{ lexical.fallback_reason ?? '—' }}</dd>
                    <template v-if="lexical.scanned_documents != null">
                        <dt>Проверено работ</dt><dd>{{ lexical.scanned_documents }}</dd>
                        <dt>Чтение / оценки / доступ, мс</dt><dd>{{ number(lexical.load_ms) }} / {{ number(lexical.score_ms) }} / {{ number(lexical.visibility_ms) }}</dd>
                        <dt>Исключено из-за доступа или отсутствия файла</dt><dd>{{ lexical.excluded_visibility_or_media }}</dd>
                    </template>
                </dl>
                <div v-if="ranked.length" class="overflow-x-auto">
                    <table class="table table-xs w-full">
                        <thead><tr><th>Работа</th><th>Lexical ранг / оценка</th><th>Поля</th><th>Dense ранг / оценка</th><th>RRF ранг / оценка</th><th>Исключения</th></tr></thead>
                        <tbody>
                            <tr v-for="row in ranked" :key="row.artwork_id">
                                <th scope="row">#{{ row.artwork_id }}<span v-if="row.lexical_only" class="block font-normal">Только Lexical</span></th>
                                <td>{{ number(row.lexical_rank) }} / {{ number(row.lexical_score) }}</td>
                                <td>{{ Object.entries(row.lexical_fields ?? {}).map(([field, match]) => `${field} (${match})`).join(', ') || '—' }}</td>
                                <td>{{ number(row.dense_rank) }} / {{ number(row.dense_score) }}</td>
                                <td>{{ number(row.rrf_rank) }} / {{ number(row.rrf_score) }}</td>
                                <td>{{ row.exclusions?.join(', ') || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
            <template v-if="trace.rag?.facts.length">
                <h3 class="font-semibold">Факты RAG</h3>
                <ul class="space-y-1 [overflow-wrap:anywhere]">
                    <li v-for="fact in trace.rag.facts" :key="`${fact.source_ref}-${fact.fact_ref}`">
                        #{{ fact.artwork_id }} · {{ fact.fact_ref }} · {{ fact.value }}
                    </li>
                </ul>
                <p>Выбор composer {{ trace.rag.selected.map(fact => `#${fact.artwork_id} · ${fact.fact_ref}`).join(', ') || '—' }}</p>
            </template>
            <ul v-if="trace.errors.length" class="space-y-1 text-warning">
                <li v-for="(error, index) in trace.errors" :key="index">
                    {{ stages[error.stage] ?? error.stage }} · {{ error.code }}{{ error.http_status ? ` (${error.http_status})` : '' }}
                </li>
            </ul>
        </div>
        <p v-else class="px-3 pb-3 text-base-content/70">Для этого ответа диагностика не сохранена.</p>
    </details>
</template>
