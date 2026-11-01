<script setup>
import UserAvatar from '@/Components/Workspace/UserAvatar.vue'
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
import WorkspaceEmptyState from '@/Components/Workspace/WorkspaceEmptyState.vue'
import WorkspacePagination from '@/Components/Workspace/WorkspacePagination.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'

import CreateUserModal     from '@/Components/Admin/CreateUserModal.vue'
import EditUserModal       from '@/Components/Admin/EditUserModal.vue'
import DeleteUserModal  from '@/Components/Admin/DeleteUserModal.vue'

defineOptions({ layout: DashboardLayout })

const roleLabels = { User: 'Участник', Moderator: 'Модератор', Admin: 'Администратор', SuperAdmin: 'Суперадминистратор' }
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
const deleting = ref(false)
const deleteError = ref('')
watch(delUser, () => { deleteError.value = '' })
async function confirmDeleteUser() {
    if (!delUser.value || deleting.value) return
    deleting.value = true
    try { await axios.delete('/admin/users/' + delUser.value.id); delUser.value = null; refresh() }
    catch { deleteError.value = 'Не удалось удалить пользователя. Попробуйте ещё раз.' }
    finally { deleting.value = false }
}
const refresh    = () => fetch(props.users.current_page)
</script>

<template>
    <Head title="Пользователи" />

    <div class="workspace-page">
        <!-- HEADER -->
        <WorkspaceHeader eyebrow="Администрирование" title="Пользователи" :count="props.users.total" description="Участники сообщества, их роли и доступ к возможностям платформы.">
 <template #actions><button class="btn btn-primary" @click="createOpen = true"><i class="mdi mdi-account-plus-outline" /> Добавить пользователя</button></template>
 </WorkspaceHeader>

        <!-- FILTERS -->
        <section class="workspace-toolbar sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <label class="label"><span class="label-text">Роль</span></label>
                <select aria-label="Фильтр по роли" v-model="role" class="select select-bordered w-full">
                    <option value="">Все</option>
                    <option v-for="r in props.roles" :key="r" :value="r">{{ roleLabels[r] || r }}</option>
                </select>
            </div>

            <div class="relative xl:col-span-2">
                <label class="label"><span class="label-text">Поиск</span></label>
                <input
                    v-model="search"
                    @keyup.enter="fetch()"
                    placeholder="Имя или email"
                    aria-label="Поиск пользователей по имени или почте"
                    class="input input-bordered w-full pl-10"
                />
                <i class="mdi mdi-magnify absolute left-3 bottom-3 text-base-content/50" />
            </div>

            <div class="flex flex-wrap gap-2"><button class="btn btn-primary" @click="fetch()"><i class="mdi mdi-magnify" /> Найти</button>
                <button
                    v-if="hasActiveFilters"
                    class="btn btn-sm btn-ghost mt-auto"
                    @click="resetFilters()"
                >
                    <i class="mdi mdi-filter-remove mr-1" /> Сбросить
                </button>
            </div>
        </section>

        <WorkspaceEmptyState v-if="!props.users.data.length" title="Пользователи не найдены" description="Проверьте имя, почту или выбранную роль." icon="mdi-account-search-outline" />
<!-- MOBILE LIST -->
        <div v-if="props.users.data.length" class="block md:hidden space-y-4">
            <div
                v-for="(u,i) in props.users.data"
                :key="u.id"
                class="workspace-mobile-card space-y-3"
            >
                <div class="flex items-center">
                    <UserAvatar :src="u.profile_photo_url" :name="u.name" class="mr-3" />
                    <div class="flex-1">
                        <Link
                            :href="route('user.profile.show', u.id)"
                            class="font-semibold hover:underline"
                        >{{ u.name }}</Link>
                        <div class="text-xs opacity-70">{{ u.email }}</div>
                    </div>
                    <button class="btn btn-ghost btn-sm btn-square" aria-label="Редактировать пользователя" @click="editUser = u">
                        <i class="mdi mdi-pencil" />
                    </button>
                    <button class="btn btn-ghost btn-sm btn-square text-error" aria-label="Удалить пользователя" @click="delUser = u">
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
          >{{ roleLabels[r.name] || r.name }}</span>
                </div>
            </div>
        </div>

        <!-- DESKTOP TABLE -->
        <div v-if="props.users.data.length" class="workspace-table hidden md:block">
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
                        <UserAvatar :src="u.profile_photo_url" :name="u.name" class="mr-3" />
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
              >{{ roleLabels[r.name] || r.name }}</span>
                    </td>
                    <td class="text-center space-x-1">
                        <button class="btn btn-ghost btn-sm" aria-label="Редактировать пользователя" @click="editUser = u">
                            <i class="mdi mdi-pencil" />
                        </button>
                        <button class="btn btn-ghost btn-sm text-error" aria-label="Удалить пользователя" @click="delUser = u">
                            <i class="mdi mdi-trash-can" />
                        </button>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <WorkspacePagination :pagination="props.users" />
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

    <DeleteUserModal v-if="delUser" :user="delUser" :processing="deleting" :error="deleteError" @close="!deleting && (delUser = null)" @confirmed="confirmDeleteUser" />
</template>

<style scoped>
.input.pl-10 { padding-left: 2.5rem; }
</style>
