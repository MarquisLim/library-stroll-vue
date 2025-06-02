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

const participants = ref([])
const myPivot = computed(() => {
    return props.conversation.users.find(u => u.id === meId)?.pivot || {}
})
const isAdmin = computed(() => myPivot.value.role === 'admin')

const newTitle = ref(props.conversation.title || '')
const newAvatarFile = ref(null)
const newAvatarPreview = ref(null)
const avatarError = ref(null)
const searchQuery = ref('')
const searchResults = ref([])
const isSearching = ref(false)
const generalError = ref(null)

function open() {
    participants.value = props.conversation.users.map(u => ({ ...u, showMenu: false }))
    newTitle.value = props.conversation.title || ''
    newAvatarFile.value = null
    newAvatarPreview.value = null
    avatarError.value = null
    searchQuery.value = ''
    searchResults.value = []
    generalError.value = null
    nextTick(() => dialogRef.value.showModal())
}

function close() {
    dialogRef.value.close()
}

function onAvatarChange(e) {
    const file = e.target.files[0]
    if (!file) return
    if (!file.type.startsWith('image/')) {
        avatarError.value = 'Нужно выбрать изображение'
        return
    }
    if (file.size > 5 * 1024 * 1024) {
        avatarError.value = 'Размер не более 5 МБ'
        return
    }
    newAvatarFile.value = file
    const reader = new FileReader()
    reader.onload = () => {
        newAvatarPreview.value = reader.result
    }
    reader.readAsDataURL(file)
}

async function updateAvatar() {
    if (!newAvatarFile.value) {
        avatarError.value = 'Сначала выберите файл'
        return
    }
    const formData = new FormData()
    // "подделываем" PATCH через POST
    formData.append('_method', 'PATCH')
    formData.append('avatar', newAvatarFile.value)

    try {
        const { data } = await axios.post(
            route('messenger.conversations.avatar.update', props.conversation.id),
            formData
        )
        props.conversation.avatar_url = data.avatar_url
        newAvatarFile.value = null
        newAvatarPreview.value = null
        avatarError.value = null
    } catch {
        avatarError.value = 'Не удалось обновить аватар'
    }
}

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
        generalError.value = 'Ошибка при сохранении названия'
    }
}

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
        searchResults.value = data.filter(u =>
            !participants.value.some(p => p.id === u.id)
        )
    } catch {
        searchResults.value = []
    } finally {
        isSearching.value = false
    }
})

async function addParticipant(user) {
    try {
        await axios.post(
            route(`messenger.conversations.${props.conversation.id}.addUser`),
            { user_id: user.id }
        )
        participants.value.push({ ...user, showMenu: false })
        searchQuery.value = ''
        searchResults.value = []
    } catch {}
}

async function removeParticipant(user) {
    if (user.id === meId) return
    try {
        await axios.post(
            route(`messenger.conversations.${props.conversation.id}.removeUser`),
            { user_id: user.id }
        )
        participants.value = participants.value.filter(p => p.id !== user.id)
    } catch {}
}

async function leaveGroup() {
    try {
        await axios.post(
            route(`messenger.conversations.${props.conversation.id}.leave`),
            {}
        )
        close()
        router.visit(route('messenger.index'))
    } catch {}
}

async function deleteGroup() {
    if (!confirm('Удалить группу навсегда?')) return
    try {
        await axios.delete(route('messenger.conversations.destroy', props.conversation.id))
        close()
        router.visit(route('messenger.index'))
    } catch {}
}

defineExpose({ open, close })
</script>

<template>
    <dialog ref="dialogRef" class="modal">
        <div class="modal-box max-w-md p-6 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold">
                    {{ conversation.title }}
                    <span class="text-sm text-base-content/60">({{ participants.length }})</span>
                </h3>
                <button @click="close" class="btn btn-ghost btn-sm">
                    <XMarkIcon class="w-5 h-5" />
                </button>
            </div>

            <div class="flex items-center gap-4">
                <div class="relative">
                    <div
                        class="w-16 h-16 bg-base-200 rounded-full flex items-center justify-center overflow-hidden"
                    >
                        <template v-if="newAvatarPreview">
                            <img
                                :src="newAvatarPreview"
                                class="object-cover w-full h-full"
                                alt="Превью аватара"
                            />
                        </template>
                        <template v-else>
                            <img
                                v-if="conversation.avatar_url"
                                :src="conversation.avatar_url"
                                class="object-cover w-full h-full"
                                alt="Аватар группы"
                            />
                            <template v-else>
                                <svg
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
                            </template>
                        </template>
                    </div>
                    <label
                        class="absolute inset-0 flex items-center justify-center bg-black/20 opacity-0 hover:opacity-100 rounded-full cursor-pointer transition"
                    >
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
                        <input
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="onAvatarChange"
                        />
                    </label>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        v-if="newAvatarPreview"
                        @click="updateAvatar"
                        class="btn btn-sm btn-primary"
                    >
                        Сохранить
                    </button>
                </div>
            </div>

            <div v-if="isAdmin" class="space-y-1">
                <label class="text-sm">Название группы</label>
                <div class="flex gap-2">
                    <input
                        v-model="newTitle"
                        type="text"
                        class="input input-bordered flex-1"
                    />
                    <button @click="updateTitle" class="btn btn-sm btn-primary">
                        Сохранить
                    </button>
                </div>
            </div>

            <p v-if="generalError" class="text-xs text-red-600">{{ generalError }}</p>
            <p v-if="avatarError" class="text-xs text-red-600">{{ avatarError }}</p>

            <hr />

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
                                :src="u.profile_photo_url"
                                class="w-8 h-8 rounded-full object-cover"
                                alt="avatar"
                            />
                            <span class="text-sm">{{ u.name }}</span>
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
                                class="absolute right-0 mt-1 w-40 bg-base-100 border border-base-300 rounded shadow z-10"
                            >
                                <li>
                                    <button
                                        @click="router.visit(route('profile.show', u.id))"
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

            <div v-if="isAdmin" class="space-y-2">
                <label class="text-sm">Добавить участника</label>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Поиск по имени…"
                    class="input input-bordered w-full"
                />
                <ul class="max-h-32 overflow-auto space-y-1">
                    <li v-if="isSearching" class="text-xs text-center py-1">
                        Идёт поиск…
                    </li>
                    <li v-for="u in searchResults" :key="u.id">
                        <button
                            @click="addParticipant(u)"
                            class="flex items-center w-full p-2 hover:bg-base-200 rounded"
                        >
                            <img
                                :src="u.profile_photo_url"
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
