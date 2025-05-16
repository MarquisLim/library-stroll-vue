<script setup>
import { Head, Link } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import { Inertia } from '@inertiajs/inertia'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

// модалки
import EditModal   from '@/Components/Collections/EditCollectionModal.vue'
import DeleteModal from '@/Components/Collections/ConfirmDeleteModal.vue'
import axios from "axios";

defineOptions({ layout: DashboardLayout })

// -------- props --------
const props = defineProps({
    collections: Object,   // paginator
    filters     : Object,
})

// -------- реактивные фильтры --------
const visibility = ref(props.filters.visibility) // all / public / private
const search     = ref(props.filters.search)
const sort       = ref(props.filters.sort)       // updated / items
const dir        = ref(props.filters.dir)        // asc / desc

const hasActiveFilters = computed(() =>
    visibility.value !== 'all' ||
    search.value.trim() !== '' ||
    sort.value   !== 'updated' ||
    dir.value    !== 'desc'
)

// запрос
function fetch(page = 1) {
    Inertia.get(route('studio.collections'), {
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
    sort.value='updated'
    dir.value='desc'
    fetch()
}

// модалки
const editCol = ref(null)      // объект коллекции
const delCol  = ref(null)      // объект
const saved   = () => fetch(props.collections.current_page)
</script>

<template>
    <Head title="Коллекции"/>

    <div class="bg-base-100 text-base-content min-h-screen p-4 md:p-6 space-y-6">

        <!-- Заголовок -->
        <header class="flex items-center justify-between">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <i class="mdi mdi-folder-multiple-outline text-primary text-3xl"></i>
                Коллекции
            </h1>
        </header>

        <!-- Фильтры -->
        <section class="grid gap-4 md:grid-cols-4 lg:grid-cols-5 items-center">
            <div>
                <label class="label"><span class="label-text text-sm">Доступ</span></label>
                <select v-model="visibility" class="select select-bordered w-full">
                    <option value="all">Все</option>
                    <option value="public">Публичные</option>
                    <option value="private">Приватные</option>
                </select>
            </div>

            <div class="md:col-span-2 lg:col-span-3">
                <label class="label"><span class="label-text text-sm">Поиск</span></label>
                <div class="relative">
                    <input v-model="search"
                           @keyup.enter="fetch()"
                           placeholder="Название…"
                           class="input input-bordered w-full pr-10" />
                    <button @click="fetch"
                            class="btn btn-ghost btn-square absolute right-0 top-0 h-full">
                        <i class="mdi mdi-magnify text-xl"></i>
                    </button>
                </div>
            </div>

            <button v-if="hasActiveFilters"
                    @click="resetFilters"
                    class="btn btn-xs btn-outline hidden md:inline-flex self-end">
                <i class="mdi mdi-filter-remove-outline mr-1"></i>Сброс
            </button>
        </section>

        <!-- Таблица -->
        <div class="overflow-x-auto rounded-lg shadow">
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
                    <td class="space-x-1 text-center">
                        <button class="btn btn-ghost btn-sm" @click="editCol = c">
                            <i class="mdi mdi-pencil"></i>
                        </button>
                        <button class="btn btn-ghost btn-sm text-error" @click="delCol = c">
                            <i class="mdi mdi-trash-can"></i>
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- Пагинация -->
        <nav v-if="props.collections.last_page>1" class="flex justify-center flex-wrap gap-1 pt-4">
            <Link v-for="l in props.collections.links.filter(l=>Number(l.label))"
                  :key="l.label"
                  :href="l.url || '#'"
                  preserve-state preserve-scroll
                  :class="[
              'px-3 py-1 rounded',
              l.active ? 'bg-primary text-primary-content' : 'bg-base-200 hover:bg-base-300'
            ]">
                {{ l.label }}
            </Link>
        </nav>
    </div>

    <!-- Modals -->
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
