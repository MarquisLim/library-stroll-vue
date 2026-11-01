<script setup>
import { ref, nextTick } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { useSearchSuggestions } from '@/composables/useSearchSuggestions'

const q        = ref('')
const type     = ref('all')
const { rows, error: searchError } = useSearchSuggestions(q, type, async (query, kind, signal) => {
    const { data } = await axios.get(route('search.suggestions'), { params: { q: query, type: kind }, signal })
    return data.suggestions
})

const modalRef = ref(null)
const inputRef = ref(null)

const tabs = [
    { value: 'all',     label: 'Все' },
    { value: 'artwork', label: 'Артворки' },
    { value: 'author',  label: 'Авторы' },
    { value: 'tag',     label: 'Теги' },
]

function showBlock(kind) {
    return type.value === 'all' || type.value === kind
}

function reset() {
    q.value = ''
    type.value = 'all'
    rows.value = { artworks: [], authors: [], tags: [] }
}

function open() {
    reset()
    modalRef.value?.showModal()

    // Ждём, пока модалка откроется, затем пытаемся сфокусировать поле
    nextTick(() => {
        setTimeout(() => {
            inputRef.value?.focus()
        }, 0)
    })
}

function close() {
    modalRef.value?.close()
}

function goSearch() {
    if (!q.value.trim()) return
    router.get(route('search.index'), { q: q.value, type: type.value })
    close()
}

defineExpose({ open })
</script>

<template>
    <Teleport to="body">
        <dialog ref="modalRef" class="modal items-start pt-safe-t" aria-label="Поиск по LibraryStroll" @close="reset">
            <!-- Добавляем боковые отступы на мобильных (mx-4) и небольшой верхний (mt-4) -->
            <div class="modal-box w-full max-w-3xl max-h-[90vh] sm:max-h-[80vh] p-0 flex flex-col overflow-hidden mx-4 mt-4">
                <!-- HEADER -->
                <div class="flex items-center gap-3 p-4 sm:p-6 border-b border-base-300 bg-base-100">
                    <MagnifyingGlassIcon class="w-6 h-6 text-base-content/60" />
                    <input
                        v-model="q"
                        ref="inputRef"
                        class="min-w-0 flex-1 input input-lg input-primary text-base sm:text-xl"
                        aria-label="Найти работы, авторов или теги"
                        placeholder="Что вас вдохновляет?"
                        @keydown.enter.prevent="goSearch"
                    />
                    <form method="dialog">
                        <button class="btn btn-sm btn-circle btn-ghost" aria-label="Закрыть поиск">✕</button>
                    </form>
                </div>

                <div v-if="!q.trim()" class="px-6 py-10 text-center">
                    <p class="text-lg font-semibold">Начните с того, что вам близко</p>
                    <p class="ui-muted mx-auto mt-3 max-w-md text-sm">Название работы, имя автора или любимая тема — вдохновение найдётся.</p>
                </div>
                <transition name="fade">
                    <div v-if="q.trim()" class="flex-1 flex flex-col overflow-hidden">
                        <!-- TABS -->
                        <div class="flex text-sm sm:text-base font-medium border-b border-base-300 bg-base-100 sticky top-0 z-10">
                            <button
                                v-for="t in tabs"
                                :key="t.value"
                                class="flex-1 py-2 sm:py-3 transition"
                                :class="type === t.value
                  ? 'border-b-2 border-primary text-primary'
                  : 'hover:bg-base-200'"
                                @click="type = t.value"
                            >
                                {{ t.label }}
                            </button>
                        </div>

                        <!-- RESULTS -->
                        <div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-8">
                            <p v-if="searchError" role="alert" class="text-error text-sm">{{ searchError }}</p>
                            <!-- Артворки -->
                            <template v-if="showBlock('artwork')">
                                <h3 class="block-title">Артворки</h3>
                                <div class="grid grid-cols-2 xs:grid-cols-3 sm:grid-cols-4 gap-3">
                                    <template v-if="rows.artworks.length">
                                        <Link
                                            v-for="a in rows.artworks"
                                            :key="a.id"
                                            :href="route('artworks.show', a.id)"
                                            class="group"
                                        >
                                            <img
                                                :src="a.thumb"
                                                class="w-full h-32 sm:h-40 object-cover rounded-lg group-hover:opacity-80"
                                                loading="lazy"
                                            />
                                            <p class="truncate text-xs mt-1">{{ a.name }}</p>
                                        </Link>
                                    </template>
                                    <p v-else class="col-span-full text-center text-gray-500">Не найдено</p>
                                </div>
                            </template>

                            <!-- Авторы -->
                            <template v-if="showBlock('author')">
                                <h3 class="block-title">Авторы</h3>
                                <div class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-3 gap-3">
                                    <template v-if="rows.authors.length">
                                        <Link
                                            v-for="u in rows.authors"
                                            :key="u.id"
                                            :href="`/profile/${u.id}`"
                                            class="flex items-center gap-3 p-2 rounded-lg hover:bg-base-300 transition"
                                        >
                                            <img
                                                :src="u.avatar"
                                                class="w-10 h-10 rounded-full object-cover shrink-0"
                                                loading="lazy"
                                            />
                                            <span class="truncate">{{ u.name }}</span>
                                        </Link>
                                    </template>
                                    <p v-else class="col-span-full text-center text-gray-500">Не найдено</p>
                                </div>
                            </template>

                            <!-- Теги -->
                            <template v-if="showBlock('tag')">
                                <h3 class="block-title">Теги</h3>
                                <div class="flex flex-wrap gap-2">
                                    <template v-if="rows.tags.length">
                                        <Link
                                            v-for="t in rows.tags"
                                            :key="t.id"
                                            :href="`/search?q=${encodeURIComponent(t.name)}&type=tag`"
                                            class="badge badge-outline hover:badge-primary transition"
                                        >
                                            #{{ t.name }}
                                        </Link>
                                    </template>
                                    <p v-else class="w-full text-center text-gray-500">Не найдено</p>
                                </div>
                            </template>
                        </div>
                    </div>
                </transition>
            </div>
            <form method="dialog" class="modal-backdrop">
                <button>Закрыть</button>
            </form>
        </dialog>
    </Teleport>
</template>

<style scoped>
.block-title {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
.fade-enter-to,
.fade-leave-from {
    opacity: 1;
}
</style>
