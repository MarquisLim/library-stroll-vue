<script setup>
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
import WorkspaceEmptyState from '@/Components/Workspace/WorkspaceEmptyState.vue'
import WorkspacePagination from '@/Components/Workspace/WorkspacePagination.vue'
import axios             from 'axios'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'

import EditModal from '@/Components/Studio/EditArtworkModal.vue'
import DeleteModal from '@/Components/Studio/ConfirmDeleteModal.vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

defineOptions({ layout: DashboardLayout })

const props = defineProps({
    artworks: Object,
    filters: Object,
    collections: Array,
})

const visibility = ref(props.filters.visibility)
const type       = ref(props.filters.type)
const status     = ref(props.filters.status)
const search     = ref(props.filters.search)
const sort       = ref(props.filters.sort)
const dir        = ref(props.filters.dir)
const page       = usePage()

const hasActiveFilters = computed(() =>
    Boolean(search.value?.trim()) ||
    visibility.value !== 'all' ||
    type.value       !== 'all' ||
    status.value     !== 'all' ||
    sort.value       !== 'created' ||
    dir.value        !== 'desc'
)

const { setCollections } = useArtworkActions()
if (props.collections?.length) {
    setCollections(props.collections)
}

const isAdmin = computed(() => page.props.isAdminView === true)

function fetch(pageNum = 1) {
    const routeName = isAdmin.value ? 'admin.artworks.manager' : 'studio.manager'

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

function toggle(col) {
    if (sort.value === col) {
        dir.value = dir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sort.value = col
        dir.value  = 'desc'
    }
    fetch()
}

function resetFilters() {
    visibility.value = 'all'
    type.value       = 'all'
    status.value     = 'all'
    search.value     = ''
    sort.value       = 'created'
    dir.value        = 'desc'
    fetch()
}

// модалки
const editArt = ref(null)
const delId   = ref(null)

const saved = () => fetch(props.artworks.current_page)
</script>

<template>
    <Head :title="isAdmin ? 'Все работы' : 'Мои работы'"/>

    <div class="workspace-page">
        <!-- Header -->
        <WorkspaceHeader :eyebrow="isAdmin ? 'Администрирование' : 'Ваша студия'" :title="isAdmin ? 'Все работы' : 'Мои работы'" :count="props.artworks.total" :description="isAdmin ? 'Контент сообщества: просматривайте, проверяйте и управляйте публикациями.' : 'Все ваши идеи в одном месте. Управляйте публикациями и возвращайтесь к черновикам.'">
 <template v-if="!isAdmin" #actions><Link :href="route('studio.index')" class="btn btn-primary"><i class="mdi mdi-plus" /> Создать работу</Link></template>
 </WorkspaceHeader>

        <!-- Filters -->
        <section class="workspace-toolbar sm:grid-cols-2 xl:grid-cols-6">
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
                    <option value="published">Опубликованные</option>
                    <option value="draft">Черновик</option>
                </select>
            </div>
            <div class="sm:col-span-2 xl:col-span-3">
                <label class="label"><span class="label-text text-sm">Поиск</span></label>
                <div class="relative">
                    <input
                        v-model="search"
                        @keyup.enter="fetch()"
                        placeholder="Название…"
                        class="input input-bordered w-full pr-10"
                    />
                    <button
                        @click="fetch()" aria-label="Найти работы"
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
                class="btn btn-sm btn-ghost text-base-content/70"
            >
                <i class="mdi mdi-filter-remove-outline mr-1"></i>Сброс
            </button>
        </div>

        <WorkspaceEmptyState v-if="!props.artworks.data.length" title="Работы не найдены" description="Попробуйте изменить фильтры или начните с новой работы." />
<!-- Mobile list view -->
        <div v-if="props.artworks.data.length" class="block md:hidden space-y-4">
            <div
                v-for="(a,i) in props.artworks.data"
                :key="a.id"
                class="workspace-mobile-card"
            >
                <div class="flex items-center p-4">
                    <img v-if="a.thumb_url" :src="a.thumb_url" alt="" class="h-16 w-16 shrink-0 rounded-xl object-cover mr-4"/>
                    <span v-else class="mr-4 flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-2xl text-primary"><i class="mdi mdi-image-outline" /></span>
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
                        <button aria-label="Редактировать работу" @click="editArt = a" class="btn btn-ghost btn-sm btn-square">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button aria-label="Удалить работу" @click="delId = a.id" class="btn btn-ghost btn-sm btn-square text-error">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Desktop table view -->
        <div v-if="props.artworks.data.length" class="workspace-table hidden md:block">
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
                        <i class="mdi mdi-eye-outline"></i>
                        <SortIcon v-if="sort==='views'" :dir="dir"/>
                    </th>
                    <th @click="toggle('likes')" class="text-center cursor-pointer">
                        <i class="mdi mdi-heart-outline"></i>
                        <SortIcon v-if="sort==='likes'" :dir="dir"/>
                    </th>
                    <th class="text-center">Статус</th>
                    <th @click="toggle('updated')" class="text-center cursor-pointer">
                        Дата обновления
                        <SortIcon v-if="sort==='updated'" :dir="dir"/>
                    </th>
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
                    <td><img v-if="a.thumb_url" :src="a.thumb_url" alt="" class="h-12 w-12 rounded-xl mx-auto object-cover bg-base-200"/><span v-else class="mx-auto flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-xl text-primary"><i class="mdi mdi-image-outline" /></span></td>
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
                        <span v-if="a.is_published" class="badge badge-success">Опубликовано</span>
                        <span v-else class="badge badge-warning">Черновик</span>
                    </td>
                    <td class="text-center">
                        <span>{{ a.updated_date ? new Date(a.updated_date).toLocaleString() : '—' }}</span>
                    </td>
                    <td class="space-x-1 text-center">
                        <button aria-label="Редактировать работу" @click="editArt = a" class="btn btn-ghost btn-sm">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button aria-label="Удалить работу" @click="delId = a.id" class="btn btn-ghost btn-sm text-error">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <WorkspacePagination :pagination="props.artworks" />
    </div>

    <!-- Модалка редактирования -->
    <EditModal
        :open="Boolean(editArt)"
        :artwork="editArt"
        @close="editArt = null"
        @saved="saved"
    />

    <!-- Модалка удаления -->
    <DeleteModal
        :open="Boolean(delId)"
        @close="delId = null"
        @confirm="
        axios.delete(`/studio/artworks/${delId}`).then(() => {
          delId = null
          saved()
        })
    "
    />
</template>

<script>
// Значок сортировки
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
