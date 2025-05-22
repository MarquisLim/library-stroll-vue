<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'

import EditModal from '@/Components/Studio/EditArtworkModal.vue'
import DeleteModal from '@/Components/Studio/ConfirmDeleteModal.vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

defineOptions({ layout: DashboardLayout })

// props
const props = defineProps({
    artworks: Object,
    filters: Object,
    collections: Array,
})

// filters state
const visibility = ref(props.filters.visibility)
const type       = ref(props.filters.type)
const status     = ref(props.filters.status)
const search     = ref(props.filters.search)
const sort       = ref(props.filters.sort)
const dir        = ref(props.filters.dir)
const page = usePage()

// computed
const hasActiveFilters = computed(() =>
    visibility.value !== 'all' ||
    type.value       !== 'all' ||
    status.value     !== 'all' ||
    sort.value       !== 'updated' ||
    dir.value        !== 'desc'
)

// sync collections
const { setCollections } = useArtworkActions()
if (props.collections?.length) setCollections(props.collections)

const isAdmin = computed(() => page.props.isAdminView === true)

// fetch helper
function fetch(pageNum = 1) {
    const routeName = isAdmin.value
        ? 'admin.artworks.manager'
        : 'studio.manager'

    router.get(
        route(routeName),
        {
            page:       pageNum,
            visibility: visibility.value !== 'all' ? visibility.value : undefined,
            type:       type.value       !== 'all' ? type.value       : undefined,
            status:     status.value     !== 'all' ? status.value     : undefined,
            search:     search.value     || undefined,
            sort:       sort.value,
            dir:        dir.value,
        },
        {
            preserveState:  true,
            preserveScroll: true,
            replace:        true,
        }
    )
}
watch([visibility, type, status], () => fetch())

// sorting
function toggle(col) {
    if (sort.value === col) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sort.value = col
        dir.value  = 'desc'
    }
    fetch()
}

// reset
function resetFilters() {
    visibility.value = 'all'
    type.value       = 'all'
    status.value     = 'all'
    search.value     = ''
    sort.value       = 'updated'
    dir.value        = 'desc'
    fetch()
}

// modals
const editArt = ref(null)
const delId   = ref(null)
const saved   = () => fetch(props.artworks.current_page)
</script>

<template>
    <Head title="Менеджер работ"/>

    <div class="bg-base-100 text-base-content min-h-screen p-4 md:p-6 space-y-6">
        <!-- Header -->
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <i class="mdi mdi-folder-edit-outline text-primary text-3xl"></i>
                Менеджер работ
            </h1>
        </header>

        <!-- Filters -->
        <section class="grid gap-4 md:grid-cols-6 lg:grid-cols-7 items-center">
            <div>
                <label class="label"><span class="label-text text-sm">Доступ</span></label>
                <select v-model="visibility" class="select select-bordered w-full">
                    <option value="all">Все</option>
                    <option value="public">Публичные</option>
                    <option value="private">Приватные</option>
                </select>
            </div>
            <div>
                <label class="label"><span class="label-text text-sm">Тип</span></label>
                <select v-model="type" class="select select-bordered w-full">
                    <option value="all">Все</option>
                    <option value="image">Изображения</option>
                    <option value="video">Видео</option>
                </select>
            </div>
            <div>
                <label class="label"><span class="label-text text-sm">Статус</span></label>
                <select v-model="status" class="select select-bordered w-full">
                    <option value="all">Все</option>
                    <option value="published">Опублик</option>
                    <option value="draft">Черновик</option>
                </select>
            </div>
            <div class="md:col-span-2 lg:col-span-3">
                <label class="label"><span class="label-text text-sm">Поиск</span></label>
                <div class="relative">
                    <input
                        v-model="search"
                        @keyup.enter="fetch()"
                        placeholder="Название…"
                        class="input input-bordered w-full pr-10"
                    />
                    <button
                        @click="fetch"
                        class="btn btn-ghost btn-square absolute right-0 top-0 h-full"
                    >
                        <i class="mdi mdi-magnify text-xl"></i>
                    </button>
                </div>
            </div>
        </section>
        <div>
            <button
                v-if="hasActiveFilters"
                @click="resetFilters"
                class="btn btn-xs btn-outline md:inline-flex hidden"
            >
                <i class="mdi mdi-filter-remove-outline mr-1"></i>Сброс
            </button>
        </div>

        <!-- Mobile list view -->
        <div class="block md:hidden space-y-4">
            <div
                v-for="(a,i) in props.artworks.data"
                :key="a.id"
                class="card bg-base-200 shadow"
            >
                <div class="flex items-center p-4">
                    <img :src="a.thumb_url" class="h-16 w-16 rounded mr-4"/>
                    <div class="flex-1">
                        <h2 class="font-semibold">{{ a.title || '—' }}</h2>
                        <div class="text-sm opacity-70">
                            {{ a.type==='image' ? 'Изобр.' : 'Видео' }} ·
                            {{ a.is_private ? 'Приват' : 'Публично' }}
                        </div>
                        <div class="mt-2">
                            <template v-for="(tag, idx) in a.tags.slice(0,2)" :key="tag.id">
                                <span class="badge badge-sm badge-outline mr-1">{{ tag.name }}</span>
                            </template>
                            <span v-if="a.tags.length > 2" class="badge badge-sm badge-outline">
                                +{{ a.tags.length - 2 }}
                            </span>
                        </div>
                    </div>
                    <div class="flex flex-col space-y-2">
                        <button @click="editArt = a" class="btn btn-ghost btn-sm btn-square">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button @click="delId = a.id" class="btn btn-ghost btn-sm btn-square text-error">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop table view -->
        <div class="hidden md:block overflow-x-auto rounded-lg shadow">
            <table class="table w-full">
                <thead>
                <tr>
                    <th>№</th>
                    <th class="text-center">Превью</th>
                    <th class="text-left">Название</th>
                    <th class="text-left">Теги</th>
                    <th @click="toggle('type')" class="text-center cursor-pointer">
                        Тип <i v-if="sort==='type'" :class="dir==='desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up'"/>
                    </th>
                    <th class="text-center">Доступ</th>
                    <th @click="toggle('views')" class="text-center cursor-pointer">
                        <i class="mdi mdi-eye-outline"></i> <SortIcon v-if="sort==='views'" :dir="dir"/>
                    </th>
                    <th @click="toggle('likes')" class="text-center cursor-pointer">
                        <i class="mdi mdi-heart-outline"></i> <SortIcon v-if="sort==='likes'" :dir="dir"/>
                    </th>
                    <th class="text-center">Статус</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr
                    v-for="(a,i) in props.artworks.data"
                    :key="a.id"
                    class="hover"
                >
                    <td>{{ props.artworks.from + i }}</td>
                    <td><img :src="a.thumb_url" class="h-12 w-12 rounded mx-auto"/></td>
                    <td class="text-left">
                        <Link
                            :href="a.is_published ? route('artworks.show', a.id) : route('studio.index')"
                            class="link"
                        >
                            {{ a.title || '—' }}
                        </Link>
                    </td>
                    <td>
                        <template v-for="(tag, idx) in a.tags.slice(0,2)" :key="tag.id">
                            <span class="badge badge-outline m-1">{{ tag.name }}</span>
                        </template>
                        <span v-if="a.tags.length > 2" class="badge badge-outline">
                +{{ a.tags.length - 2 }}
              </span>
                    </td>
                    <td class="text-center">{{ a.type==='image' ? 'Изобр.' : 'Видео' }}</td>
                    <td class="text-center">
                        <i :class="a.is_private ? 'mdi mdi-lock text-error' : 'mdi mdi-earth text-success'"/>
                    </td>
                    <td class="text-center">{{ a.views_count }}</td>
                    <td class="text-center">{{ a.likes_count }}</td>
                    <td class="text-center">
                        <span v-if="a.is_published" class="badge badge-success">Опубл.</span>
                        <span v-else class="badge badge-warning">Черн.</span>
                    </td>
                    <td class="space-x-1 text-center">
                        <button @click="editArt = a" class="btn btn-ghost btn-sm">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button @click="delId = a.id" class="btn btn-ghost btn-sm text-error">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- Mobile pagination -->
        <nav v-if="props.artworks.last_page > 1" class="flex justify-between items-center md:hidden mt-4">
            <button
                @click="fetch(props.artworks.current_page - 1)"
                :disabled="!props.artworks.prev_page_url"
                class="btn btn-sm"
            >
                ← Назад
            </button>
            <button
                @click="fetch(props.artworks.current_page + 1)"
                :disabled="!props.artworks.next_page_url"
                class="btn btn-sm"
            >
                Вперёд →
            </button>
        </nav>

        <!-- Desktop pagination -->
        <nav v-if="props.artworks.last_page > 1" class="hidden md:flex justify-center flex-wrap gap-1 pt-4">
            <Link
                v-for="l in props.artworks.links.filter(l => Number(l.label))"
                :key="l.label"
                :href="l.url || '#'"
                preserve-state
                preserve-scroll
                :class="[
          'px-3 py-1 rounded',
          l.active
            ? 'bg-primary text-primary-content'
            : 'bg-base-200 hover:bg-base-300'
        ]"
            >
                {{ l.label }}
            </Link>
        </nav>
    </div>

    <!-- Modals -->
    <EditModal
        :open="Boolean(editArt)"
        :artwork="editArt"
        @close="editArt = null"
        @saved="saved"
    />
    <DeleteModal
        v-if="delId"
        @close="delId = null"
        @confirm="
      router.delete(`/studio/draft/${delId}`, {
        onFinish: () => { delId = null; saved() }
      })
    "
    />
</template>

<script>
// сортировочный значок
import { h, defineComponent } from 'vue'
const SortIcon = defineComponent({
    props: { dir: String },
    setup(p) {
        return () =>
            h('i', { class: p.dir === 'desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up' })
    },
})
export default { components: { SortIcon } }
</script>
