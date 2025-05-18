<script setup>
import { ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import { MagnifyingGlassIcon } from '@heroicons/vue/24/outline'

const isOpen = ref(false)
const q      = ref('')
const type   = ref('all')
const rows   = ref({ artworks:[], authors:[], tags:[] })

const tabs = [
    { value:'all',     label:'Все' },
    { value:'artwork', label:'Артворки' },
    { value:'author',  label:'Авторы' },
    { value:'tag',     label:'Теги' },
]

function showBlock(kind){ return type.value==='all' || type.value===kind }

function reset(){
    q.value='' ; type.value='all'
    rows.value={ artworks:[], authors:[], tags:[] }
}

async function fetch(){
    if(!q.value.trim()){ reset(); return }
    const { data } = await axios.get(route('search.suggestions'), {
        params:{ q:q.value, type:type.value }
    })
    rows.value.artworks = data.suggestions.filter(i=>i.type==='artwork')
    rows.value.authors  = data.suggestions.filter(i=>i.type==='author')
    rows.value.tags     = data.suggestions.filter(i=>i.type==='tag')
}
watch(q, fetch)

function open(){ isOpen.value=true; reset() }
function close(){ isOpen.value=false }
function goSearch(){
    if(!q.value.trim()) return
    router.get(route('search.index'), { q:q.value, type:type.value })
    close()
}

defineExpose({ open })
</script>

<template>
    <Teleport to="body">
        <transition name="fade">
            <div
                v-if="isOpen"
                class="fixed inset-0 z-50 flex items-start justify-center p-4 sm:p-8 bg-black/60"
                @click.self="close"
            >
                <div
                    class="w-full max-w-3xl bg-base-100 rounded-2xl shadow-2xl flex flex-col max-h-screen sm:max-h-[80vh] overflow-y-auto"
                >
                    <div class="flex items-center gap-3 p-4 sm:p-6 border-b sticky top-0 bg-base-100">
                        <MagnifyingGlassIcon class="w-6 h-6 text-base-content/60"/>
                        <input
                            v-model="q"
                            class="flex-1 input input-lg input-bordered text-lg sm:text-2xl"
                            placeholder="Начните вводить запрос…"
                            @keydown.enter.prevent="goSearch"
                            autofocus
                        />
                        <button class="btn btn-ghost btn-circle" @click="close">✕</button>
                    </div>

                    <div class="flex text-sm sm:text-base font-medium border-b">
                        <button
                            v-for="t in tabs"
                            :key="t.value"
                            class="flex-1 py-2 sm:py-3 transition"
                            :class="type===t.value
                ? 'border-b-2 border-primary text-primary'
                : 'hover:bg-base-200'"
                            @click="type=t.value;fetch()"
                        >
                            {{ t.label }}
                        </button>
                    </div>

                    <div class="flex-1 min-h-0 overflow-y-auto p-4 sm:p-6 space-y-8">
                        <template v-if="showBlock('artwork')">
                            <h3 class="block-title">Артворки</h3>
                            <div class="grid grid-cols-2 xs:grid-cols-3 sm:grid-cols-4 gap-3">
                                <template v-if="rows.artworks.length">
                                    <Link
                                        v-for="a in rows.artworks"
                                        :key="a.id"
                                        :href="route('artworks.show',a.id)"
                                        class="group"
                                    >
                                        <img
                                            :src="a.thumb"
                                            class="w-full h-32 sm:h-40 object-cover rounded-lg group-hover:opacity-80"
                                        />
                                        <p class="truncate text-xs mt-1">{{ a.name }}</p>
                                    </Link>
                                </template>
                                <p v-else class="col-span-full text-center text-gray-500">Не&nbsp;найдено</p>
                            </div>
                        </template>

                        <template v-if="showBlock('author')">
                            <h3 class="block-title">Авторы</h3>
                            <div class="grid grid-cols-1 xs:grid-cols-2 sm:grid-cols-3 gap-3">
                                <template v-if="rows.authors.length">
                                    <Link
                                        v-for="u in rows.authors"
                                        :key="u.id"
                                        :href="`/profile/${u.id}`"
                                        class="flex items-center gap-3 p-2 rounded-lg hover:bg-base-200"
                                    >
                                        <img
                                            :src="u.avatar"
                                            class="w-10 h-10 rounded-full object-cover shrink-0"
                                        />
                                        <span class="truncate">{{ u.name }}</span>
                                    </Link>
                                </template>
                                <p v-else class="col-span-full text-center text-gray-500">Не&nbsp;найдено</p>
                            </div>
                        </template>

                        <template v-if="showBlock('tag')">
                            <h3 class="block-title">Теги</h3>
                            <div class="flex flex-wrap gap-2">
                                <template v-if="rows.tags.length">
                                    <Link
                                        v-for="t in rows.tags"
                                        :key="t.id"
                                        :href="`/search?q=${encodeURIComponent(t.name)}&type=tag`"
                                        class="badge badge-outline hover:badge-primary"
                                    >
                                        #{{ t.name }}
                                    </Link>
                                </template>
                                <p v-else class="w-full text-center text-gray-500">Не&nbsp;найдено</p>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.block-title{font-weight:600;margin-bottom:.5rem}
.fade-enter-active,.fade-leave-active{transition:opacity .2s}
.fade-enter-from,.fade-leave-to{opacity:0}
@media(max-width:639px){.max-h-\[80vh\]{max-height:100vh!important}}
</style>
