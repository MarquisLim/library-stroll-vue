<script setup>
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
import WorkspaceEmptyState from '@/Components/Workspace/WorkspaceEmptyState.vue'
import WorkspacePagination from '@/Components/Workspace/WorkspacePagination.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import {ref, watch, computed, defineComponent, h} from 'vue'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

import CreateCollectionModal from '@/Components/Collections/CreateCollectionModal.vue'
import EditModal   from '@/Components/Collections/EditCollectionModal.vue'
import DeleteModal from '@/Components/Collections/ConfirmDeleteModal.vue'
import axios from "axios";

defineOptions({ layout: DashboardLayout })

// -------- props --------
const props = defineProps({
    collections: Object,
    filters     : Object,
})

const visibility = ref(props.filters.visibility)
const search     = ref(props.filters.search)
const sort       = ref(props.filters.sort)
const dir        = ref(props.filters.dir)

const hasActiveFilters = computed(() =>
    visibility.value !== 'all' ||
    search.value.trim() !== '' ||
    sort.value   !== 'created' ||
    dir.value    !== 'desc'
)

// запрос
function fetch(page = 1) {
    router.get(route('studio.collections'), {
        page,
        visibility: visibility.value==='all' ? undefined : visibility.value,
        search    : search.value || undefined,
        sort      : sort.value,
        dir       : dir.value,
    }, { preserveState:true, preserveScroll:true, replace:true })
}
watch(visibility, () => fetch())

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
    visibility.value='all'
    search.value=''
    sort.value='created'
    dir.value='desc'
    fetch()
}

const SortIcon = defineComponent({
    props: { dir: String },
    setup(p) {
        return () =>
            h('i', { class: p.dir === 'desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up' })
    },
})

// модалки
const createOpen = ref(false)
const editCol = ref(null)      // объект коллекции
const delCol  = ref(null)      // объект
const saved   = () => fetch(props.collections.current_page)
</script>

<template>
    <Head title="Коллекции"/>

    <div class="workspace-page">

        <!-- Заголовок -->
        <WorkspaceHeader eyebrow="Ваша студия" title="Коллекции" :count="props.collections.total" description="Личные подборки, любимые работы и идеи, к которым хочется вернуться."><template #actions><button class="btn btn-primary" @click="createOpen = true"><i class="mdi mdi-folder-plus-outline" /> Создать коллекцию</button></template></WorkspaceHeader>

        <!-- Фильтры -->
        <section class="workspace-toolbar sm:grid-cols-3">
            <div>
                <label class="label"><span class="label-text text-sm">Доступ</span></label>
                <select v-model="visibility" class="select select-bordered w-full">
                    <option value="all">Все</option>
                    <option value="public">Публичные</option>
                    <option value="private">Приватные</option>
                </select>
            </div>

            <div class="sm:col-span-2">
                <label class="label"><span class="label-text text-sm">Поиск</span></label>
                <div class="relative">
                    <input v-model="search"
                           @keyup.enter="fetch()"
                           placeholder="Название…"
                           class="input input-bordered w-full pr-10" />
                    <button @click="fetch()" aria-label="Найти коллекции"
                            class="btn btn-ghost btn-square absolute right-0 top-0 h-full">
                        <i class="mdi mdi-magnify text-xl"></i>
                    </button>
                </div>
            </div>
        </section>
        <button v-if="hasActiveFilters"
                @click="resetFilters"
                class="btn btn-sm btn-ghost self-end">
            <i class="mdi mdi-filter-remove-outline mr-1"></i>Сброс
        </button>

        <WorkspaceEmptyState v-if="!props.collections.data.length" title="Коллекции не найдены" description="Сохраните понравившуюся работу в коллекцию или измените условия поиска." icon="mdi-folder-multiple-outline" />
<!-- Таблица -->
        <div v-if="props.collections.data.length" class="space-y-3 md:hidden">
            <article v-for="collection in props.collections.data" :key="collection.id" class="workspace-mobile-card">
                <div class="flex items-center gap-3"><img v-if="collection.thumb" :src="collection.thumb" alt="" class="h-14 w-14 rounded-xl object-cover" /><span v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-2xl text-primary"><i class="mdi mdi-folder-multiple-outline" /></span><div class="min-w-0"><Link :href="route('collections.show', collection.id)" class="block font-semibold">{{ collection.name }}</Link><p class="ui-muted mt-1 text-xs">Работ: {{ collection.artworks_count }} · {{ collection.is_private ? 'Приватная' : 'Публичная' }}</p></div></div>
                <div class="mt-4 flex justify-end gap-2 border-t border-base-300 pt-3"><button class="btn btn-sm btn-ghost" @click="editCol = collection"><i class="mdi mdi-pencil-outline" /> Изменить</button><button class="btn btn-sm btn-ghost text-error" aria-label="Удалить коллекцию" @click="delCol = collection"><i class="mdi mdi-trash-can-outline" /></button></div>
            </article>
        </div>
        <div v-if="props.collections.data.length" class="workspace-table hidden md:block">
            <table class="table w-full">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Превью</th>
                    <th class="text-left">Название</th>
                    <th @click="toggle('items')" class="cursor-pointer text-center">
                        Работ <i v-if="sort==='items'" :class="dir==='desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up'"/>
                    </th>
                    <th class="text-center">Доступ</th>
                    <th @click="toggle('updated')" class="text-center cursor-pointer">
                        Дата обновления
                        <SortIcon v-if="sort==='updated'" :dir="dir"/>
                    </th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="(c,i) in props.collections.data" :key="c.id" class="hover">
                    <td>{{ props.collections.from + i }}</td>
                    <td class="text-left">
                        <img v-if="c.thumb"
                             :src="c.thumb"
                             class="h-12 w-12 rounded object-cover" />
                        <div v-else
                             class="h-12 w-12 rounded bg-base-300 flex items-center justify-center">
                            <i class="mdi mdi-folder-multiple-outline text-xl text-base-content/60"></i>
                        </div>
                    </td>
                    <td class="text-left">
                        <Link :href="route('collections.show', c.id)" class="link">
                            {{ c.name }}
                        </Link>
                    </td>
                    <td class="text-center">{{ c.artworks_count }}</td>
                    <td class="text-center">
                        <i :class="c.is_private ? 'mdi mdi-lock text-error' : 'mdi mdi-earth text-success'"/>
                    </td>
                    <td class="text-center">
                        <span>{{ c.updated_date ? new Date(c.updated_date).toLocaleString() : '—' }}</span>
                    </td>
                    <td class="space-x-1 text-center">
                        <button class="btn btn-ghost btn-sm" aria-label="Редактировать коллекцию" @click="editCol = c">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm text-error" aria-label="Удалить коллекцию" @click="delCol = c">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <WorkspacePagination :pagination="props.collections" />
    </div>

    <!-- Modals -->
    <CreateCollectionModal v-if="createOpen" @close="createOpen = false" @created="() => fetch()" />
    <EditModal  v-if="editCol"
                :initial-collection="editCol"
                @close="editCol = null"
                @saved="data => {
                axios.post(`/collections/${editCol.id}`, data)
                   .then(() => saved())
                   .finally(() => editCol = null)
                }" />

    <DeleteModal v-if="delCol"
                 :collection="delCol"
                 @close="delCol = null"
                 @confirmed="() => {
                  axios.delete(`/collections/${delCol.id}`)
                       .then(() => saved())
                       .finally(() => delCol = null)
                               }" />
</template>
