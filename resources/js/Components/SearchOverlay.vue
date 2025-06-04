<script setup>
import { ref, watch, nextTick } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const q        = ref('')
const type     = ref('all')
const rows     = ref({ artworks: [], authors: [], tags: [] })

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

async function fetch() {
    if (!q.value.trim()) { reset(); return }
    const { data } = await axios.get(route('search.suggestions'), {
        params: { q: q.value, type: type.value }
    })
    rows.value.artworks = data.suggestions.filter(i => i.type === 'artwork')
    rows.value.authors  = data.suggestions.filter(i => i.type === 'author')
    rows.value.tags     = data.suggestions.filter(i => i.type === 'tag')
}

watch(q, fetch)

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
        <dialog ref="modalRef" class="modal items-start pt-safe-t">
            <!-- Добавляем боковые отступы на мобильных (mx-4) и небольшой верхний (mt-4) -->
            <div class="modal-box w-full max-w-3xl max-h-[90vh] sm:max-h-[80vh] p-0 flex flex-col overflow-hidden mx-4 mt-4">
                <!-- HEADER -->
                <div class="flex items-center gap-3 p-4 sm:p-6 border-b border-base-300 bg-base-100">
                    <MagnifyingGlassIcon class="w-6 h-6 text-base-content/60" />
                    <input
                        v-model="q"
                        ref="inputRef"
                        class="flex-1 input input-lg input-primary text-lg sm:text-2xl"
                        placeholder="Поиск..."
                        @keydown.enter.prevent="goSearch"
                    />
                    <form method="dialog">
                        <button class="btn btn-sm btn-circle btn-ghost">✕</button>
                    </form>
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
                                @click="type = t.value; fetch()"
                            >
                                {{ t.label }}
                            </button>
                        </div>

                        <!-- RESULTS -->
                        <div class="flex-1 overflow-y-auto p-4 sm:p-6 space-y-8">
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
