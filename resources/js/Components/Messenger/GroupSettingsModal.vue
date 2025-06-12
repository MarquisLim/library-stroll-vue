<template>
    <dialog ref="dialogRef" class="modal">
        <div class="modal-box max-w-md p-6 space-y-4 overflow-visible">

            <!-- Заголовок -->
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">
                    {{ conversation.title }}
                    <span class="text-sm text-base-content/60">({{ participants.length }})</span>
                </h3>
                <button @click="close" class="btn btn-ghost btn-sm">
                    <XMarkIcon class="w-5 h-5" />
                </button>
            </div>

            <!-- Аватар группы -->
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="w-16 h-16 bg-base-200 rounded-full flex items-center justify-center overflow-hidden">
                        <!-- Превью нового аватара -->
                        <img
                            v-if="newAvatarPreview"
                            :src="newAvatarPreview"
                            class="object-cover w-full h-full"
                            alt="Превью аватара"
                        />
                        <!-- Текущий аватар -->
                        <img
                            v-else-if="conversation.avatar_url"
                            :src="conversation.avatar_url"
                            class="object-cover w-full h-full"
                            alt="Аватар группы"
                        />
                        <!-- Заглушка -->
                        <svg
                            v-else
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-8 h-8 text-base-content/40"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m0 0A4 4 0 0115 16v4m-6-4v4m6-4a4 4 0 00-6 0"
                            />
                        </svg>
                    </div>

                    <!-- Кнопка загрузки нового аватара — только для админа -->
                    <label
                        v-if="isAdmin"
                        class="absolute inset-0 flex items-center justify-center bg-black/20 opacity-0 hover:opacity-100 rounded-full cursor-pointer transition"
                    >
                        <input type="file" accept="image/*" class="hidden" @change="onAvatarChange" />
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="w-5 h-5 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M15.232 5.232l3.536 3.536M4 20h4.586a1 1 0 00.707-.293l10-10a1 1 0 00-1.414-1.414l-10 10a1 1 0 00-.293.707V20z"
                            />
                        </svg>
                    </label>
                </div>
                <!-- Кнопка сохранения аватара -->
                <button
                    v-if="isAdmin && newAvatarPreview"
                    @click="updateAvatar"
                    class="btn btn-sm btn-primary"
                >
                    Сохранить аватар
                </button>
            </div>

            <!-- Редактирование названия — только админ -->
            <div v-if="isAdmin" class="space-y-1">
                <label class="text-sm">Название группы</label>
                <div class="flex gap-2">
                    <input v-model="newTitle" type="text" class="input input-bordered flex-1" />
                    <button @click="updateTitle" class="btn btn-sm btn-primary">Сохранить</button>
                </div>
                <p v-if="generalError" class="text-xs text-red-600">{{ generalError }}</p>
            </div>

            <hr />

            <!-- Список участников -->
            <div>
                <h4 class="text-sm font-medium mb-2">Участники:</h4>
                <ul class="space-y-1 max-h-48 overflow-auto">
                    <li
                        v-for="u in participants"
                        :key="u.id"
                        class="flex items-center justify-between"
                    >
                        <div class="flex items-center gap-2 break-all">
                            <img
                                :src="u.profile_photo_url || '/default-avatar.png'"
                                class="w-8 h-8 rounded-full object-cover"
                                alt="avatar"
                            />
                            <span class="text-sm">{{ u.name }}</span>
                            <span class="text-xs text-base-content/60 ml-2">({{ u.role }})</span>
                        </div>
                        <div class="relative">
                            <button
                                @click.prevent="u.showMenu = !u.showMenu"
                                class="btn btn-ghost btn-xs p-1"
                            >
                                <EllipsisHorizontalIcon class="w-5 h-5" />
                            </button>
                            <ul
                                v-if="u.showMenu"
                                class="absolute right-0 mt-1 w-40 bg-base-100 border border-base-300 rounded shadow z-10 overflow-visible"
                            >
                                <li>
                                    <button
                                        @click="() => router.visit(route('user.profile.show', u.id))"
                                        class="w-full text-left px-3 py-1 hover:bg-base-200 text-xs"
                                    >
                                        Профиль
                                    </button>
                                </li>
                                <li v-if="isAdmin && u.id !== meId">
                                    <button
                                        @click="removeParticipant(u)"
                                        class="w-full text-left px-3 py-1 hover:bg-base-200 text-xs text-red-600"
                                    >
                                        Удалить из группы
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </li>
                    <li v-if="participants.length === 0" class="text-xs text-gray-500">
                        Нет участников
                    </li>
                </ul>
            </div>

            <!-- Добавление участников — только админ -->
            <div v-if="isAdmin" class="space-y-2">
                <label class="text-sm">Добавить участника</label>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Поиск по имени…"
                    class="input input-bordered w-full"
                />
                <ul class="max-h-32 overflow-auto space-y-1">
                    <li v-if="isSearching" class="text-xs text-center py-1">Идёт поиск…</li>
                    <li v-for="u in searchResults" :key="u.id">
                        <button
                            @click="addParticipant(u)"
                            class="flex items-center w-full p-2 hover:bg-base-200 rounded"
                        >
                            <img
                                :src="u.profile_photo_url || '/default-avatar.png'"
                                class="w-6 h-6 rounded-full mr-2"
                            />
                            <span>{{ u.name }}</span>
                        </button>
                    </li>
                    <li
                        v-if="!isSearching && searchResults.length === 0 && searchQuery.trim()"
                        class="text-xs text-center py-1 text-gray-500"
                    >
                        Ничего не найдено
                    </li>
                </ul>
            </div>

            <hr />

            <!-- Выход или удаление группы -->
            <div class="flex justify-between pt-2">
                <button
                    v-if="!isAdmin"
                    @click="leaveGroup"
                    class="btn btn-sm btn-warning flex items-center gap-1"
                >
                    <ArrowLeftOnRectangleIcon class="w-5 h-5" />
                    Выйти из группы
                </button>
                <button
                    v-if="isAdmin"
                    @click="deleteGroup"
                    class="btn btn-sm btn-error flex items-center gap-1"
                >
                    <TrashIcon class="w-5 h-5" />
                    Удалить группу
                </button>
            </div>

        </div>
        <form method="dialog" class="modal-backdrop"><button /></form>
    </dialog>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue'
import axios from 'axios'
import { usePage, router } from '@inertiajs/vue3'
import {
    XMarkIcon,
    EllipsisHorizontalIcon,
    TrashIcon,
    ArrowLeftOnRectangleIcon
} from '@heroicons/vue/24/outline'

const props = defineProps({
    conversation: { type: Object, required: true }
})

const dialogRef = ref(null)
const page = usePage()
const meId = page.props.auth.user.id

// Участники с ролями
const participants = ref([])
const myPivot = computed(() =>
    props.conversation.users.find(u => u.id === meId)?.pivot || {}
)
const isAdmin = computed(() => myPivot.value.role === 'admin')

// Новые значения
const newTitle = ref(props.conversation.title || '')
const newAvatarFile = ref(null)
const newAvatarPreview = ref(null)
const avatarError = ref(null)
const generalError = ref(null)

// Поиск пользователей
const searchQuery = ref('')
const searchResults = ref([])
const isSearching = ref(false)

function open() {
    participants.value = props.conversation.users.map(u => ({
        id: u.id,
        name: u.name,
        profile_photo_url: u.profile_photo_url,
        role: u.pivot.role,
        showMenu: false,
    }))
    newTitle.value = props.conversation.title || ''
    newAvatarFile.value = null
    newAvatarPreview.value = null
    avatarError.value = null
    generalError.value = null
    searchQuery.value = ''
    searchResults.value = []
    nextTick(() => dialogRef.value.showModal())
}

function close() {
    dialogRef.value.close()
}

// Обработка выбора файла
function onAvatarChange(e) {
    const file = e.target.files[0]
    if (!file || !file.type.startsWith('image/')) {
        avatarError.value = 'Выберите корректное изображение'
        return
    }
    if (file.size > 5 * 1024 * 1024) {
        avatarError.value = 'Макс. размер 5 МБ'
        return
    }
    newAvatarFile.value = file
    const reader = new FileReader()
    reader.onload = () => (newAvatarPreview.value = reader.result)
    reader.readAsDataURL(file)
}

// Сохранить аватар
async function updateAvatar() {
    const form = new FormData()
    form.append('_method', 'PATCH')
    form.append('avatar', newAvatarFile.value)
    try {
        const { data } = await axios.post(
            route('messenger.conversations.avatar.update', props.conversation.id),
            form
        )
        props.conversation.avatar_url = data.avatar_url
        newAvatarFile.value = null
        newAvatarPreview.value = null
        avatarError.value = null
    } catch {
        avatarError.value = 'Ошибка при загрузке аватара'
    }
}

// Сохранить название
async function updateTitle() {
    if (!newTitle.value.trim()) {
        generalError.value = 'Название не может быть пустым'
        return
    }
    try {
        await axios.patch(
            route('messenger.conversations.update', props.conversation.id),
            { title: newTitle.value.trim() }
        )
        props.conversation.title = newTitle.value.trim()
        generalError.value = null
    } catch {
        generalError.value = 'Ошибка при сохранении'
    }
}

// Поиск пользователей
watch(searchQuery, async q => {
    if (!isAdmin.value) return
    if (!q.trim()) {
        searchResults.value = []
        return
    }
    isSearching.value = true
    try {
        const { data } = await axios.get(route('users.search'), {
            params: { q: q.trim() }
        })
        // Отфильтровать уже участников
        searchResults.value = data.filter(u =>
            !participants.value.some(p => p.id === u.id)
        )
    } finally {
        isSearching.value = false
    }
})

// Добавить участника
async function addParticipant(user) {
    try {
        await axios.post(
            route('messenger.conversations.addUser', props.conversation.id),
            { user_id: user.id }
        )
        participants.value.push({
            ...user,
            role: 'member',
            showMenu: false
        })
        searchQuery.value = ''
        searchResults.value = []
    } catch {}
}

// Удалить участника
async function removeParticipant(user) {
    if (user.id === meId) return
    try {
        await axios.post(
            route('messenger.conversations.removeUser', props.conversation.id),
            { user_id: user.id }
        )
        participants.value = participants.value.filter(p => p.id !== user.id)
    } catch {}
}

// Выйти из группы
async function leaveGroup() {
    try {
        await axios.post(
            route('messenger.conversations.leave', props.conversation.id)
        )
        close()
        router.visit(route('messenger.index'))
    } catch {}
}

// Удалить группу
async function deleteGroup() {
    if (!confirm('Удалить группу?')) return
    try {
        await axios.delete(
            route('messenger.conversations.destroy', props.conversation.id)
        )
        close()
        router.visit(route('messenger.index'))
    } catch {}
}

defineExpose({ open, close })
</script>
