<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
defineOptions({ layout: DashboardLayout })
const props = defineProps({ initial: Object })
const data = ref(props.initial)
const diagnostics = ref(null)
const tab = ref('overview')
const automatic = ref(true)
const loading = ref(false)
const checking = ref(false)
const error = ref('')
const actionMessage = ref('')
const retrying = ref(null)
const status = ref(
    new URLSearchParams(window.location.search).get('status') || ''
)
const search = ref(
    new URLSearchParams(window.location.search).get('search') || ''
)
const appliedFilters = ref({ status: status.value, search: search.value })
const query = ref('')
const searching = ref(false)
const searchResult = ref(null)
const searchError = ref('')
const selected = ref(null)
const clock = ref(Date.now())
const labels = {
    indexed: 'В индексе',
    pending: 'В очереди',
    processing: 'Обработка',
    failed: 'Ошибка',
    stale: 'Устарело',
    missing: 'Без метаданных',
    success: 'Успешно',
    skipped: 'Пропущено',
}
const stages = {
    analysis: 'Анализ изображения и текста',
    embedding_and_indexing: 'Создание вектора и запись в Qdrant',
    completed: 'Завершено',
    skipped: 'Пропущено',
    processing: 'Обработка',
}
const metadataLabels = {
    chat_model: 'Текстовая модель',
    vision_model: 'Модель изображения',
    embedding_model: 'Векторная модель',
    embedding_dimension: 'Размерность',
    qdrant_point_id: 'ID точки Qdrant',
}
const serviceLabels = {
    ok: 'Доступно',
    degraded: 'Требует внимания',
    unavailable: 'Недоступно',
    disabled: 'AI выключен',
    not_async: 'Нет фоновой очереди',
    not_checked: 'Не проверено',
    unknown: 'Нет данных',
}
const canRetry = computed(
    () =>
        data.value.enabled &&
        !['sync', 'null'].includes(data.value.settings.queue_driver)
)
const busy = (item) =>
    ['pending', 'processing'].includes(item.status) &&
    item.updated_at &&
    clock.value - new Date(item.updated_at).getTime() < 900000
const date = (value) => (value ? new Date(value).toLocaleString('ru-RU') : '—')
const elapsed = (value) =>
    value
        ? `${Math.max(
              0,
              Math.floor((clock.value - new Date(value).getTime()) / 1000)
          )} с`
        : '—'
const tone = (value) =>
    ['indexed', 'success', 'ok'].includes(value)
        ? 'text-success'
        : ['failed', 'unavailable', 'degraded'].includes(value)
        ? 'text-error'
        : 'text-base-content/65'
let timer, controller
let lastCheck = 0
async function refresh(page = data.value.artworks.current_page) {
    if (loading.value) return
    loading.value = true
    error.value = ''
    try {
        const response = await axios.get(route('admin.ai.data'), {
            params: {
                page,
                status: appliedFilters.value.status || undefined,
                search: appliedFilters.value.search || undefined,
            },
            signal: controller.signal,
        })
        data.value = response.data
    } catch (e) {
        if (e.code !== 'ERR_CANCELED')
            error.value =
                'Не удалось обновить данные. На экране последняя успешная загрузка.'
    } finally {
        loading.value = false
    }
}
async function check() {
    if (checking.value) return
    checking.value = true
    lastCheck = Date.now()
    try {
        diagnostics.value = (
            await axios.post(
                route('admin.ai.diagnostics'),
                {},
                { signal: controller.signal }
            )
        ).data
    } catch (e) {
        if (e.code !== 'ERR_CANCELED')
            error.value =
                'Не удалось выполнить проверку сервисов. Попробуйте позже.'
    } finally {
        checking.value = false
    }
}
function applyFilters() {
    if (loading.value) return
    appliedFilters.value = { status: status.value, search: search.value }
    selected.value = null
    refresh(1)
}
async function retry(item) {
    if (retrying.value) return
    retrying.value = item.id
    actionMessage.value = ''
    try {
        actionMessage.value = (
            await axios.post(
                route('admin.artworks.reindex', item.id),
                {},
                { signal: controller.signal }
            )
        ).data.message
        await refresh()
    } catch (e) {
        if (e.code !== 'ERR_CANCELED')
            actionMessage.value =
                e.response?.data?.message ||
                'Не удалось поставить работу в очередь.'
    } finally {
        retrying.value = null
    }
}
async function testSearch() {
    if (searching.value) return
    searching.value = true
    searchError.value = ''
    searchResult.value = null
    try {
        searchResult.value = (
            await axios.post(
                route('admin.ai.search'),
                { query: query.value },
                { signal: controller.signal }
            )
        ).data
    } catch (e) {
        if (e.code !== 'ERR_CANCELED')
            searchError.value =
                e.response?.data?.message || 'Проверка поиска не выполнена.'
    } finally {
        searching.value = false
    }
}
function visible() {
    if (!document.hidden && automatic.value) {
        refresh()
        if (Date.now() - lastCheck > 30000) check()
    }
}
function toggleAutomatic() {
    automatic.value = !automatic.value
    visible()
}
function refreshAll() {
    refresh()
    check()
}
onMounted(() => {
    controller = new AbortController()
    check()
    timer = setInterval(() => {
        clock.value = Date.now()
        if (!document.hidden && automatic.value) {
            if (Math.floor(clock.value / 1000) % 5 === 0) refresh()
            if (clock.value - lastCheck >= 30000) check()
        }
    }, 1000)
    document.addEventListener('visibilitychange', visible)
})
onUnmounted(() => {
    clearInterval(timer)
    controller?.abort()
    document.removeEventListener('visibilitychange', visible)
})
</script>

<template>
    <Head title="AI и векторы" />
    <WorkspaceHeader
        eyebrow="Администрирование / AI"
        title="AI и векторы"
        description="От публикации работы до семантического поиска. Состояние моделей, очередь и история обработки в одном месте."
    >
        <template #actions>
            <button
                class="btn btn-ghost btn-sm"
                :aria-pressed="automatic"
                @click="toggleAutomatic"
            >
                <i
                    :class="[
                        'mdi',
                        automatic
                            ? 'mdi-pause-circle-outline'
                            : 'mdi-play-circle-outline',
                    ]"
                />{{ automatic ? 'Приостановить' : 'Автообновление' }}
            </button>
            <button
                class="btn btn-primary btn-sm"
                :disabled="loading || checking"
                @click="refreshAll"
            >
                <i
                    class="mdi mdi-refresh"
                    :class="{ 'animate-spin': checking }"
                />Проверить сейчас
            </button>
        </template>
    </WorkspaceHeader>
    <div
        class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-base-content/60"
        role="status"
    >
        <span class="flex items-center gap-2"
            ><span
                class="h-2 w-2 rounded-full"
                :class="automatic ? 'bg-success' : 'bg-base-content/30'"
            />{{
                automatic
                    ? 'Данные каждые 5 с · сервисы каждые 30 с'
                    : 'Автообновление приостановлено'
            }}</span
        ><span>Данные: {{ date(data.checked_at) }}</span>
    </div>
    <p
        v-if="error"
        class="mb-4 rounded-xl border border-error/25 bg-error/5 p-4 text-sm"
        role="alert"
    >
        {{ error }}
    </p>
    <p
        v-if="!data.enabled"
        class="mb-6 rounded-xl border border-warning/30 bg-warning/10 p-4 text-sm"
    >
        AI выключен в настройках сервера. История доступна; новые проверки
        моделей, поиск и повторная обработка выключены.
    </p>
    <nav
        class="mb-7 flex gap-1 overflow-x-auto rounded-2xl border border-base-300 bg-base-200/50 p-1"
        aria-label="Разделы мониторинга"
    >
        <button
            v-for="item in [
                { id: 'overview', name: 'Обзор системы' },
                { id: 'index', name: 'Работы и индекс' },
                { id: 'history', name: 'Журнал запусков' },
                { id: 'guide', name: 'Как это работает' },
            ]"
            :key="item.id"
            class="whitespace-nowrap rounded-xl px-4 py-3 text-sm font-semibold transition"
            :class="
                tab === item.id
                    ? 'bg-base-100 text-primary shadow-sm'
                    : 'text-base-content/60 hover:text-base-content'
            "
            :aria-current="tab === item.id ? 'page' : undefined"
            @click="tab = item.id"
        >
            {{ item.name }}
        </button>
    </nav>

    <section v-if="tab === 'overview'" class="space-y-6">
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <article
                v-for="item in [
                    {
                        label: 'В индексе',
                        value: data.stats.indexed,
                        icon: 'mdi-vector-polyline',
                        hint: `из ${data.stats.total} опубликованных`,
                    },
                    {
                        label: 'В очереди',
                        value: data.stats.pending,
                        icon: 'mdi-timer-sand',
                        hint: 'по статусу метаданных',
                    },
                    {
                        label: 'Обрабатываются',
                        value: data.stats.processing,
                        icon: 'mdi-brain',
                        hint: 'по статусу метаданных',
                    },
                    {
                        label: 'Требуют внимания',
                        value:
                            data.stats.failed +
                            data.stats.stale +
                            data.stats.missing,
                        icon: 'mdi-alert-circle-outline',
                        hint: 'ошибки, устаревшие и без данных',
                    },
                ]"
                :key="item.label"
                class="monitor-panel p-5"
            >
                <i :class="['mdi text-xl text-primary', item.icon]" />
                <p class="mt-3 text-3xl font-bold">{{ item.value }}</p>
                <h2 class="mt-1 text-sm font-semibold">{{ item.label }}</h2>
                <p class="mt-2 text-xs text-base-content/55">{{ item.hint }}</p>
            </article>
        </div>
        <article class="monitor-panel p-5">
            <div class="flex justify-between gap-3">
                <h2 class="font-bold">Покрытие индекса</h2>
                <span class="text-sm font-bold text-primary"
                    >{{ data.stats.coverage }}%</span
                >
            </div>
            <progress
                class="progress progress-primary mt-4 w-full"
                :value="data.stats.coverage"
                max="100"
                aria-label="Доля опубликованных работ со статусом В индексе"
            />
            <p class="mt-2 text-xs text-base-content/60">
                Статусы Laravel для всех опубликованных работ, включая приватные
                и заблокированные. Это покрытие каталога, а не процент
                выполнения задания.
            </p>
        </article>
        <div class="grid gap-5 xl:grid-cols-3">
            <article class="monitor-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold">Ollama · модели</h2>
                    <span
                        class="text-xs font-semibold"
                        :class="tone(diagnostics?.ollama?.status)"
                        >{{
                            serviceLabels[diagnostics?.ollama?.status] ||
                            'Не проверено'
                        }}</span
                    >
                </div>
                <div
                    v-for="model in diagnostics?.ollama?.models || []"
                    :key="model.role"
                    class="mt-4 border-t border-base-300 pt-3"
                >
                    <div class="flex justify-between gap-3 text-xs">
                        <span class="uppercase text-base-content/55">{{
                            model.role
                        }}</span
                        ><span
                            :class="
                                model.installed ? 'text-success' : 'text-error'
                            "
                            >{{
                                model.installed ? 'Установлена' : 'Отсутствует'
                            }}</span
                        >
                    </div>
                    <p class="mt-1 break-all text-sm font-semibold">
                        {{ model.name }}
                    </p>
                </div>
                <p class="mt-4 text-xs leading-relaxed text-base-content/60">
                    Проверяем список установленных моделей. Фактический запуск
                    модели подтверждается обработкой работы или тестом поиска.
                </p>
            </article>
            <article class="monitor-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold">Qdrant · векторы</h2>
                    <span
                        class="text-xs font-semibold"
                        :class="tone(diagnostics?.qdrant?.status)"
                        >{{
                            serviceLabels[diagnostics?.qdrant?.status] ||
                            'Не проверено'
                        }}</span
                    >
                </div>
                <dl class="mt-4 space-y-3 text-sm">
                    <div
                        v-for="[label, value] in [
                            ['Коллекция', diagnostics?.qdrant?.collection],
                            ['Точек', diagnostics?.qdrant?.points_count],
                            [
                                'Векторов в HNSW',
                                diagnostics?.qdrant?.indexed_vectors_count,
                            ],
                            ['Размерность', diagnostics?.qdrant?.dimension],
                            ['Расстояние', diagnostics?.qdrant?.distance],
                            [
                                'Состояние коллекции',
                                diagnostics?.qdrant?.collection_status,
                            ],
                        ]"
                        :key="label"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-base-content/60">{{ label }}</dt>
                        <dd
                            class="max-w-[60%] break-all text-right font-semibold"
                        >
                            {{ value ?? '—' }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-base-content/60">
                    Число точек и векторов HNSW может различаться: маленькие
                    сегменты Qdrant ищет без HNSW.
                </p>
            </article>
            <article class="monitor-panel p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-bold">
                        Очередь · {{ data.settings.queue }}
                    </h2>
                    <span
                        class="text-xs font-semibold"
                        :class="tone(diagnostics?.queue?.status)"
                        >{{
                            serviceLabels[diagnostics?.queue?.status] ||
                            'Не проверено'
                        }}</span
                    >
                </div>
                <p class="mt-3 text-xs text-base-content/60">
                    Подключение: {{ data.settings.queue_connection }} ·
                    {{ data.settings.queue_driver }}
                </p>
                <dl class="mt-4 space-y-3 text-sm">
                    <div
                        v-for="[label, value] in [
                            ['Ожидают', diagnostics?.queue?.pending],
                            ['Зарезервированы', diagnostics?.queue?.reserved],
                            ['Отложены', diagnostics?.queue?.delayed],
                        ]"
                        :key="label"
                        class="flex justify-between"
                    >
                        <dt class="text-base-content/60">{{ label }}</dt>
                        <dd class="font-semibold">{{ value ?? '—' }}</dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs leading-relaxed text-base-content/60">
                    Счётчики очереди не подтверждают работу worker. Смотрите
                    изменение этапов и журнал. Для повторной обработки нужна
                    фоновая очередь.
                </p>
            </article>
        </div>
        <p class="text-xs text-base-content/60" role="status">
            Проверка сервисов: {{ date(diagnostics?.checked_at) }} ·
            {{
                checking
                    ? 'Проверяем…'
                    : serviceLabels[diagnostics?.status] || 'Ожидание'
            }}<span v-if="diagnostics?.message"
                >. {{ diagnostics.message }}</span
            >
        </p>
        <article class="monitor-panel p-5">
            <div class="flex flex-wrap justify-between gap-2">
                <h2 class="font-bold">Текущие запуски</h2>
                <span class="text-xs text-base-content/60"
                    >Последние 20 активных записей</span
                >
            </div>
            <p
                v-if="!data.active_runs.length"
                class="py-7 text-center text-sm text-base-content/55"
            >
                Активных запусков в журнале нет.
            </p>
            <div
                v-for="run in data.active_runs"
                :key="run.id"
                class="mt-4 rounded-xl border border-base-300 p-4"
            >
                <div class="flex flex-wrap justify-between gap-2">
                    <p class="font-semibold">
                        #{{ run.artwork_id }} · {{ run.title }}
                    </p>
                    <span class="text-sm tabular-nums text-primary">{{
                        elapsed(run.started_at)
                    }}</span>
                </div>
                <p class="mt-2 text-sm text-base-content/65">
                    <i class="mdi mdi-progress-clock mr-1" />{{
                        stages[run.stage] || run.stage
                    }}
                </p>
                <p v-if="run.overdue" class="mt-2 text-sm text-error">
                    Дольше ожидаемого лимита. Возможно, процесс прерван:
                    проверьте worker и журнал ошибок.
                </p>
            </div>
        </article>
        <article class="monitor-panel p-5">
            <h2 class="font-bold">Проверить семантический поиск</h2>
            <p class="mt-2 text-sm text-base-content/60">
                Запрос → embedding-модель → Qdrant → публичные работы. Проверка
                действительно запускает модель; генерация ответа LLM и
                переранжирование сюда не входят.
            </p>
            <form
                class="mt-4 flex flex-wrap gap-3"
                @submit.prevent="testSearch"
            >
                <label class="min-w-0 flex-1"
                    ><span class="sr-only">Тестовый запрос</span
                    ><input
                        v-model="query"
                        class="input input-bordered w-full"
                        placeholder="Например: тихий город в вечернем свете"
                        maxlength="300"
                        required
                        :disabled="!data.enabled" /></label
                ><button
                    class="btn btn-primary"
                    :disabled="!data.enabled || searching || !query.trim()"
                >
                    {{ searching ? 'Ищем…' : 'Проверить поиск' }}
                </button>
            </form>
            <p v-if="searchError" role="alert" class="mt-3 text-sm text-error">
                {{ searchError }}
            </p>
            <div v-if="searchResult" class="mt-4">
                <p class="text-xs text-base-content/60">
                    {{ searchResult.model }} · {{ searchResult.latency_ms }} мс
                    · {{ date(searchResult.checked_at) }}. Score — мера
                    близости, не вероятность.
                </p>
                <p v-if="!searchResult.results.length" class="mt-4 text-sm">
                    Публичных совпадений нет. Проверьте покрытие индекса и
                    фильтры видимости.
                </p>
                <a
                    v-for="item in searchResult.results"
                    :key="item.id"
                    :href="`/artworks/${item.id}`"
                    class="mt-3 flex items-center justify-between gap-3 rounded-xl border border-base-300 p-3 hover:border-primary"
                    ><span class="text-sm"
                        >#{{ item.id }} · {{ item.title }}</span
                    ><span class="font-mono text-xs text-primary">{{
                        Number(item.score).toFixed(4)
                    }}</span></a
                >
            </div>
        </article>
    </section>

    <section v-if="tab === 'index'" class="space-y-5">
        <form
            class="monitor-panel flex flex-wrap gap-3 p-4"
            @submit.prevent="applyFilters"
        >
            <label class="min-w-0 flex-1"
                ><span class="sr-only">Название или ID работы</span
                ><input
                    v-model="search"
                    class="input input-bordered w-full"
                    maxlength="100"
                    placeholder="Название или ID работы" /></label
            ><label
                ><span class="sr-only">Статус обработки</span
                ><select v-model="status" class="select select-bordered">
                    <option value="">Все статусы</option>
                    <option
                        v-for="(label, key) in Object.fromEntries(
                            Object.entries(labels).slice(0, 6)
                        )"
                        :key="key"
                        :value="key"
                    >
                        {{ label }}
                    </option>
                </select></label
            ><button class="btn btn-primary" :disabled="loading">
                Применить
            </button>
        </form>
        <p
            v-if="actionMessage"
            role="status"
            class="rounded-xl border border-primary/25 bg-primary/5 p-4 text-sm"
        >
            {{ actionMessage }}
        </p>
        <p class="text-xs text-base-content/60">
            Только опубликованные работы. Повторный запуск выполняет анализ и
            индексацию заново. Найдено: {{ data.artworks.total }}.
        </p>
        <p
            v-if="data.enabled && !canRetry"
            class="text-sm text-base-content/65"
        >
            Повторная обработка недоступна: на сервере нужна фоновая очередь
            Redis или database.
        </p>
        <div class="monitor-panel overflow-hidden">
            <div
                v-if="!data.artworks.data.length"
                class="p-10 text-center text-base-content/60"
            >
                Работ с такими условиями нет.
            </div>
            <article
                v-for="item in data.artworks.data"
                :key="item.id"
                class="border-b border-base-300 p-5 last:border-0"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <button
                        class="min-w-0 flex-1 text-left"
                        :aria-expanded="selected === item.id"
                        @click="
                            selected = selected === item.id ? null : item.id
                        "
                    >
                        <span class="text-xs text-base-content/50"
                            >#{{ item.id
                            }}{{ item.is_private ? ' · Приватная' : '' }}</span
                        >
                        <h2 class="mt-1 break-words font-semibold">
                            {{ item.title }}
                        </h2>
                        <span
                            class="mt-2 block text-xs"
                            :class="tone(item.status)"
                            >{{ labels[item.status] || item.status }} · Попыток:
                            {{ item.attempts }}</span
                        ></button
                    ><button
                        class="btn btn-ghost btn-sm"
                        :disabled="!canRetry || retrying !== null || busy(item)"
                        @click="retry(item)"
                    >
                        <i class="mdi mdi-refresh" />{{
                            retrying === item.id ? 'Отправляем…' : 'Повторить'
                        }}
                    </button>
                </div>
                <p
                    v-if="item.error"
                    class="mt-3 break-words text-sm text-error"
                >
                    {{ item.error }}
                </p>
                <div
                    v-if="selected === item.id"
                    class="mt-4 space-y-3 rounded-xl bg-base-200/60 p-4 text-sm"
                >
                    <p>
                        Обновлено: {{ date(item.updated_at) }} · Индексировано:
                        {{ date(item.indexed_at) }}
                    </p>
                    <p v-if="item.run">
                        Этап: {{ stages[item.run.stage] || item.run.stage }} ·
                        {{ elapsed(item.run.started_at) }}
                    </p>
                    <template v-if="item.metadata"
                        ><p class="leading-relaxed">
                            {{
                                item.metadata.summary ||
                                'Описание ещё не получено.'
                            }}
                        </p>
                        <p class="break-words text-xs text-base-content/65">
                            {{
                                [
                                    ...(item.metadata.tags_ru || []),
                                    ...(item.metadata.tags_en || []),
                                ].join(' · ')
                            }}
                        </p>
                        <dl class="space-y-2 text-xs">
                            <div
                                v-for="(label, key) in metadataLabels"
                                :key="key"
                                class="flex flex-wrap gap-x-3"
                            >
                                <dt class="text-base-content/55">
                                    {{ label }}
                                </dt>
                                <dd class="break-all">
                                    {{ item.metadata[key] ?? '—' }}
                                </dd>
                            </div>
                        </dl></template
                    >
                    <p v-else>AI-метаданных пока нет.</p>
                </div>
            </article>
        </div>
        <div class="flex items-center justify-between gap-3 text-sm">
            <button
                class="btn btn-ghost btn-sm"
                :disabled="loading || data.artworks.current_page <= 1"
                @click="refresh(data.artworks.current_page - 1)"
            >
                Назад</button
            ><span
                >{{ data.artworks.current_page }} /
                {{ data.artworks.last_page }}</span
            ><button
                class="btn btn-ghost btn-sm"
                :disabled="
                    loading ||
                    data.artworks.current_page >= data.artworks.last_page
                "
                @click="refresh(data.artworks.current_page + 1)"
            >
                Далее
            </button>
        </div>
    </section>

    <section v-if="tab === 'history'" class="monitor-panel overflow-hidden">
        <div class="border-b border-base-300 p-5">
            <h2 class="font-bold">Последние 20 запусков</h2>
            <p class="mt-2 text-sm text-base-content/60">
                Одна запись на попытку. Автоматический повтор создаёт новую
                запись; отменённая ревизия отмечается как пропущенная.
            </p>
        </div>
        <p
            v-if="!data.history.length"
            class="p-10 text-center text-base-content/60"
        >
            Запусков пока нет.
        </p>
        <article
            v-for="run in data.history"
            :key="run.id"
            class="space-y-2 border-b border-base-300 p-5 last:border-0"
        >
            <div class="flex flex-wrap justify-between gap-2">
                <h3 class="font-semibold">
                    #{{ run.artwork_id || '—' }} · {{ run.title }}
                </h3>
                <span class="text-sm font-semibold" :class="tone(run.status)">{{
                    labels[run.status] || run.status
                }}</span>
            </div>
            <p class="text-sm text-base-content/65">
                {{ stages[run.stage] || run.stage }} · {{ run.operation }} ·
                {{
                    run.latency_ms !== null
                        ? `${run.latency_ms} мс`
                        : elapsed(run.started_at)
                }}
            </p>
            <p class="text-xs text-base-content/55">
                {{ date(run.started_at) }} → {{ date(run.finished_at) }} ·
                {{ run.model || 'Модель не записана' }}
            </p>
            <p class="break-all font-mono text-xs text-base-content/40">
                {{ run.request_id }}
            </p>
            <p v-if="run.error" class="break-words text-sm text-error">
                {{ run.error }}
            </p>
            <p v-if="run.reason" class="text-sm text-base-content/65">
                {{
                    run.reason === 'revision_changed'
                        ? 'Работа изменилась во время обработки; запрошена новая ревизия.'
                        : 'Работа удалена или снята с публикации.'
                }}
            </p>
            <p
                v-for="(warning, i) in run.warnings"
                :key="i"
                class="break-words text-xs text-warning"
            >
                {{ warning }}
            </p>
            <p v-if="run.overdue" class="text-sm text-error">
                Запись не завершена дольше лимита. Проверьте, не был ли прерван
                worker.
            </p>
        </article>
    </section>

    <section v-if="tab === 'guide'" class="space-y-5">
        <article class="monitor-panel p-6">
            <h2 class="text-xl font-bold">Путь работы до поиска</h2>
            <ol class="mt-6 space-y-6">
                <li
                    v-for="(step, i) in [
                        {
                            title: 'Публикация и очередь',
                            text: 'Laravel вычисляет хеш содержимого и ставит задание в AI-очередь. Изменение текста, тегов или изображения делает старую ревизию неактуальной.',
                        },
                        {
                            title: 'Анализ изображения и текста',
                            text: 'AI API использует vision/chat-модели Ollama для описания, тегов, стиля и других метаданных. Если сервис использовал запасной путь анализа, предупреждение сохраняется в журнале.',
                        },
                        {
                            title: 'Вектор и Qdrant',
                            text: 'Из описания и тегов собирается поисковый текст. Embedding-модель переводит его в вектор, Qdrant сохраняет точку и признаки видимости. После успешной записи Laravel устанавливает статус «В индексе».',
                        },
                        {
                            title: 'Семантический поиск',
                            text: 'Embedding-модель превращает запрос в вектор, Qdrant ищет близкие работы. Публичный поиск применяет ограничения видимости. Основной поиск может объединять текстовые и векторные результаты; тест на этой странице проверяет именно векторную часть.',
                        },
                    ]"
                    :key="step.title"
                    class="flex gap-4"
                >
                    <span
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-sm font-bold text-primary"
                        >{{ i + 1 }}</span
                    >
                    <div>
                        <h3 class="font-semibold">{{ step.title }}</h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-base-content/65"
                        >
                            {{ step.text }}
                        </p>
                    </div>
                </li>
            </ol>
        </article>
        <article class="monitor-panel p-6">
            <h2 class="font-bold">Как читать мониторинг</h2>
            <div
                class="mt-4 space-y-4 text-sm leading-relaxed text-base-content/65"
            >
                <p>
                    «В очереди» и «Обработка» — статусы метаданных Laravel.
                    Записи этапов появляются только после обновления worker до
                    этой версии кода. Старые записи журнала не содержат
                    промежуточных этапов.
                </p>
                <p>
                    «Ошибка» — попытка завершилась неудачно; очередь может
                    повторить её автоматически. «Устарело» — содержимое
                    изменилось. «Без метаданных» — опубликованная работа ещё не
                    имеет записи AI.
                </p>
                <p>
                    Если этап не меняется дольше 660 секунд, это повод проверить
                    worker и логи. Панель не может подтвердить его остановку
                    только по времени. Не показываем вымышленный процент
                    выполнения LLM.
                </p>
                <p>
                    Размер коллекции Qdrant и количество индексированных работ
                    Laravel могут различаться из-за незавершённых обновлений и
                    удаления. Проверка читает состояние коллекции и не создаёт
                    её заново.
                </p>
                <p>
                    Метка «Создано с AI» у работы — авторская отметка. Она не
                    означает, что работа прошла анализ и попала в векторный
                    индекс.
                </p>
            </div>
            <dl
                class="mt-5 grid gap-3 border-t border-base-300 pt-4 text-xs sm:grid-cols-2"
            >
                <div>
                    Автоанализ при публикации:
                    <strong>{{
                        data.settings.auto_analyze ? 'включён' : 'выключен'
                    }}</strong>
                </div>
                <div>
                    Переиндексация при изменении:
                    <strong>{{
                        data.settings.auto_reindex ? 'включена' : 'выключена'
                    }}</strong>
                </div>
                <div>Версия схемы: {{ data.settings.schema_version }}</div>
                <div>Версия промпта: {{ data.settings.prompt_version }}</div>
            </dl>
        </article>
    </section>
</template>

<style scoped>
.monitor-panel {
    @apply min-w-0 rounded-2xl border border-base-300 bg-base-100;
    overflow-wrap: anywhere;
}
</style>
