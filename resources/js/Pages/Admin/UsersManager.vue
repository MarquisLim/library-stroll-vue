<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

import CreateUserModal     from '@/Components/Admin/CreateUserModal.vue'
import EditUserModal       from '@/Components/Admin/EditUserModal.vue'
import DeleteUserModal  from '@/Components/Admin/DeleteUserModal.vue'

defineOptions({ layout: DashboardLayout })

const props = defineProps({
    users   : Object,
    roles   : Array,
    filters : Object,
})

// reactive filters
const role   = ref(props.filters.role)
const search = ref(props.filters.search)
const sort   = ref(props.filters.sort)
const dir    = ref(props.filters.dir)

const hasActiveFilters = computed(() =>
    Boolean(role.value || search.value)
)

// fetch helper
function fetch(page = 1) {
    router.get(route('admin.users'), {
        page,
        role:   role.value   || undefined,
        search: search.value || undefined,
        sort:   sort.value,
        dir:    dir.value,
    }, {
        preserveState: true,
        preserveScroll:true,
        replace:       true,
    })
}

// watch role changes to re-fetch
watch(role, () => fetch())

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

// reset filters
function resetFilters() {
    role.value   = ''
    search.value = ''
    sort.value   = 'created'
    dir.value    = 'desc'
    fetch(1)
}

// modals state
const createOpen = ref(false)
const editUser   = ref(null)
const delUser    = ref(null)
const refresh    = () => fetch(props.users.current_page)
</script>

<template>
    <Head title="Пользователи" />

    <div class="p-6 space-y-6 bg-base-100 text-base-content min-h-screen">
        <!-- HEADER -->
        <header class="flex justify-between items-center">
            <h1 class="text-2xl font-bold flex items-center gap-2">
                <i class="mdi mdi-account-multiple text-primary text-3xl" /> Пользователи
            </h1>
            <button class="btn btn-primary btn-sm" @click="createOpen = true">
                <i class="mdi mdi-plus mr-1" /> Создать
            </button>
        </header>

        <!-- FILTERS -->
        <section class="grid md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="label"><span class="label-text">Роль</span></label>
                <select v-model="role" class="select select-bordered w-full">
                    <option value="">Все</option>
                    <option v-for="r in props.roles" :key="r" :value="r">{{ r }}</option>
                </select>
            </div>

            <div class="md:col-span-2 relative">
                <label class="label"><span class="label-text">Поиск</span></label>
                <input
                    v-model="search"
                    @keyup.enter="fetch()"
                    placeholder="Имя или email"
                    class="input input-bordered w-full pl-10"
                />
                <i class="mdi mdi-magnify absolute left-3 bottom-3 text-base-content/50" />
            </div>

            <div class="flex gap-2">
                <button
                    v-if="hasActiveFilters"
                    class="btn btn-sm btn-error mt-auto"
                    @click="resetFilters()"
                >
                    <i class="mdi mdi-filter-remove mr-1" /> Сбросить
                </button>
            </div>
        </section>

        <!-- MOBILE LIST -->
        <div class="block md:hidden space-y-4">
            <div
                v-for="(u,i) in props.users.data"
                :key="u.id"
                class="card bg-base-200 p-4 space-y-2"
            >
                <div class="flex items-center">
                    <img
                        :src="u.profile_photo_url"
                        class="w-10 h-10 rounded-full mr-3"
                    />
                    <div class="flex-1">
                        <Link
                            :href="route('user.profile.show', u.id)"
                            class="font-semibold hover:underline"
                        >{{ u.name }}</Link>
                        <div class="text-xs opacity-70">{{ u.email }}</div>
                    </div>
                    <button class="btn btn-ghost btn-sm btn-square" @click="editUser = u">
                        <i class="mdi mdi-pencil" />
                    </button>
                    <button class="btn btn-ghost btn-sm btn-square text-error" @click="delUser = u">
                        <i class="mdi mdi-trash-can" />
                    </button>
                </div>
                <div class="flex justify-between text-sm">
                    <span>Работ: {{ u.artworks_count }}</span>
                    <span>Колл.: {{ u.collections_count }}</span>
                </div>
                <div class="flex flex-wrap gap-1">
          <span
              v-for="r in u.roles"
              :key="r.id"
              class="badge badge-sm badge-outline"
          >{{ r.name }}</span>
                </div>
            </div>
        </div>

        <!-- DESKTOP TABLE -->
        <div class="hidden md:block overflow-x-auto rounded-lg shadow">
            <table class="table w-full">
                <thead>
                <tr>
                    <th>#</th>
                    <th>Аватар</th>
                    <th class="text-left">Имя / Email</th>
                    <th @click="toggle('artworks')" class="cursor-pointer text-center">
                        Работ
                        <i
                            v-if="sort==='artworks'"
                            :class="dir==='desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up'"
                        />
                    </th>
                    <th @click="toggle('collections')" class="cursor-pointer text-center">
                        Колл.
                        <i
                            v-if="sort==='collections'"
                            :class="dir==='desc' ? 'mdi mdi-chevron-down' : 'mdi mdi-chevron-up'"
                        />
                    </th>
                    <th>Роли</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="(u,i) in props.users.data" :key="u.id" class="hover">
                    <td>{{ props.users.from + i }}</td>
                    <td>
                        <img
                            :src="u.profile_photo_url"
                            class="h-10 w-10 rounded-full object-cover"
                        />
                    </td>
                    <td class="text-left">
                        <Link
                            :href="route('user.profile.show', u.id)"
                            class="font-medium hover:underline"
                        >{{ u.name }}</Link>
                        <div class="text-xs opacity-70">{{ u.email }}</div>
                    </td>
                    <td class="text-center">{{ u.artworks_count }}</td>
                    <td class="text-center">{{ u.collections_count }}</td>
                    <td>
              <span
                  v-for="r in u.roles"
                  :key="r.id"
                  class="badge badge-sm badge-outline mr-1"
              >{{ r.name }}</span>
                    </td>
                    <td class="text-center space-x-1">
                        <button class="btn btn-ghost btn-sm" @click="editUser = u">
                            <i class="mdi mdi-pencil" />
                        </button>
                        <button class="btn btn-ghost btn-sm text-error" @click="delUser = u">
                            <i class="mdi mdi-trash-can" />
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <nav v-if="props.users.last_page>1" class="flex justify-center flex-wrap gap-1 pt-4">
            <Link
                v-for="l in props.users.links.filter(l => Number(l.label))"
                :key="l.label"
                :href="l.url||'#'"
                preserve-state
                preserve-scroll
                :class="[
          'px-3 py-1 rounded',
          l.active
            ? 'bg-primary text-primary-content'
            : 'bg-base-200 hover:bg-base-300'
        ]"
            >{{ l.label }}</Link>
        </nav>
    </div>

    <!-- MODALS -->
    <CreateUserModal
        v-if="createOpen"
        :roles="props.roles"
        @close="createOpen = false"
        @created="refresh"
    />

    <EditUserModal
        v-if="editUser"
        :user="editUser"
        :roles="props.roles"
        @close="editUser = null"
        @saved="refresh"
    />

    <DeleteUserModal
        v-if="delUser"
        :collection="{ name: delUser.name }"
        @close="delUser = null"
        @confirmed="() => {
      axios
        .delete(`/admin/users/${delUser.id}`)
        .then(refresh)
        .finally(() => delUser = null)
    }"
    />
</template>

<style scoped>
.input.pl-10 { padding-left: 2.5rem; }
</style>
