<script setup>
import { ref, watch, nextTick } from 'vue'
import axios from 'axios'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    recentUsers: { type: Array, default: () => [] }
})
const emit = defineEmits()

// Ссылка на <dialog>
const dialogRef = ref(null)
const mode = ref('select')

// Для диалога
const searchQuery = ref('')
const searchResults = ref([])
const isSearching = ref(false)

// Для группы
const groupName = ref('')
const groupAvatar = ref(null)        // локально выбранный файл
const groupAvatarPreview = ref(null) // data-url для превью
const selectedGroupUsers = ref([])

const error = ref(null)

function open() {
    mode.value = 'select'
    groupName.value = ''
    groupAvatar.value = null
    groupAvatarPreview.value = null
    selectedGroupUsers.value = []
    searchQuery.value = ''
    searchResults.value = []
    error.value = null
    nextTick(() => {
        dialogRef.value.showModal()
    })
}

function close() {
    dialogRef.value.close()
}

// 3.1. Обработка поиска для режима «dialog»
watch(searchQuery, async (q) => {
    if (mode.value !== 'dialog') return
    if (!q.trim()) {
        searchResults.value = []
        return
    }
    isSearching.value = true
    try {
        const { data } = await axios.get(route('users.search'), {
            params: { q: q.trim() }
        })
        searchResults.value = data
    } catch {
        searchResults.value = []
    } finally {
        isSearching.value = false
    }
})

async function startDialog(user) {
    try {
        const { data } = await axios.post(route('messenger.conversations.store'), {
            user_ids: [user.id],
            type: 'dialog'
        })
        close()
        router.visit(route('messenger.index', data.id))
    } catch {
        // на случай ошибки
    }
}

// 3.2. Обработка выбора аватара группы (проверяем формат и размер до 5 МБ)
function onGroupAvatarChange(e) {
    const file = e.target.files[0]
    if (!file) return
    if (!file.type.startsWith('image/')) {
        error.value = 'Это должен быть файл-изображение'
        return
    }
    const maxBytes = 5 * 1024 * 1024
    if (file.size > maxBytes) {
        error.value = 'Размер изображения не должен превышать 5 МБ'
        return
    }
    groupAvatar.value = file
    // Превью через FileReader
    const reader = new FileReader()
    reader.onload = () => {
        groupAvatarPreview.value = reader.result
    }
    reader.readAsDataURL(file)
}

// Добавление/удаление участников в группу
function toggleUserForGroup(user) {
    const idx = selectedGroupUsers.value.indexOf(user.id)
    if (idx === -1) {
        selectedGroupUsers.value.push(user.id)
    } else {
        selectedGroupUsers.value.splice(idx, 1)
    }
}

// 3.3. Создание группы
async function createGroup() {
    error.value = null
    if (!groupName.value.trim()) {
        error.value = 'Введите название группы'
        return
    }
    if (selectedGroupUsers.value.length === 0) {
        error.value = 'Выберите хотя бы одного участника'
        return
    }

    const formData = new FormData()
    formData.append('type', 'group')
    formData.append('title', groupName.value.trim())
    // Список ID участников
    selectedGroupUsers.value.forEach(id => formData.append('user_ids[]', id))
    // Если есть аватар, передаём его
    if (groupAvatar.value) {
        formData.append('avatar', groupAvatar.value)
    }

    try {
        const { data } = await axios.post(
            route('messenger.conversations.store'),
            formData,
            {
                headers: { 'Content-Type': 'multipart/form-data' }
            }
        )
        close()
        router.visit(route('messenger.index', data.id))
    } catch (e) {
        error.value = 'Не удалось создать группу'
    }
}

defineExpose({ open, close })
</script>

<template>
    <dialog ref="dialogRef" class="modal">
        <div class="modal-box w-80 p-6 space-y-4">
            <!-- 3.4. Режим выбора -->
            <template v-if="mode === 'select'">
                <h3 class="text-lg font-semibold mb-2">Новый чат</h3>
                <div class="flex flex-col gap-2">
                    <button
                        @click="mode = 'dialog'; nextTick(() => $refs.searchInput?.focus())"
                        class="btn btn-outline w-full"
                    >
                        С пользователем
                    </button>
                    <button
                        @click="mode = 'group'"
                        class="btn btn-outline w-full"
                    >
                        Создать группу
                    </button>
                </div>
                <div class="modal-action justify-end">
                    <form method="dialog">
                        <button class="btn btn-error">Отмена</button>
                    </form>
                </div>
            </template>

            <!-- 3.5. Режим «диалог с пользователем» -->
            <template v-if="mode === 'dialog'">
                <h3 class="text-lg font-semibold mb-2">Найти пользователя</h3>
                <input
                    v-model="searchQuery"
                    ref="searchInput"
                    type="text"
                    placeholder="Поиск по имени..."
                    class="input input-bordered w-full mb-2"
                />
                <ul class="max-h-40 overflow-auto space-y-1 mb-2">
                    <li v-if="isSearching" class="text-xs text-center py-2">
                        Идёт поиск...
                    </li>
                    <li v-for="u in searchResults" :key="u.id">
                        <button
                            @click="startDialog(u)"
                            class="flex items-center w-full p-2 hover:bg-base-200 rounded"
                        >
                            <img
                                :src="u.avatar"
                                class="w-8 h-8 rounded-full mr-2"
                            />
                            <span>{{ u.name }}</span>
                        </button>
                    </li>
                    <li
                        v-if="!isSearching && searchResults.length === 0 && searchQuery.trim()"
                    >
                        <p class="text-xs text-center py-2 text-gray-500">Ничего не найдено</p>
                    </li>
                </ul>
                <div class="modal-action justify-end">
                    <button @click="mode = 'select'" class="btn btn-ghost">Назад</button>
                    <form method="dialog">
                        <button class="btn btn-error">Отмена</button>
                    </form>
                </div>
            </template>

            <!-- 3.6. Режим «Создать группу» -->
            <template v-if="mode === 'group'">
                <!-- Заголовок -->
                <h3 class="text-xl font-semibold text-center mb-4">Новая группа</h3>

                <!-- 1. Аватар + название -->
                <div class="flex flex-col items-center gap-4 mb-4">
                    <!-- Аватар-плейсхолдер -->
                    <div class="relative">
                        <div
                            class="w-24 h-24 bg-base-200 rounded-full flex items-center justify-center overflow-hidden"
                        >
                            <template v-if="groupAvatarPreview">
                                <img
                                    :src="groupAvatarPreview"
                                    alt="Превью аватара"
                                    class="object-cover w-full h-full"
                                />
                            </template>
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
                                        d="M12 4v16m8-8H4"
                                    />
                                </svg>
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
                                @change="onGroupAvatarChange"
                            />
                        </label>
                    </div>

                    <!-- Поле ввода названия группы -->
                    <input
                        v-model="groupName"
                        type="text"
                        placeholder="Название группы"
                        class="input input-bordered w-full max-w-xs text-base"
                    />
                </div>

                <!-- 2. Ошибка валидации -->
                <p v-if="error" class="text-center text-sm text-red-600 mb-4">{{ error }}</p>

                <!-- 3. Список участников: вертикальный -->
                <div class="mb-4">
                    <p class="text-sm font-medium mb-2 text-center">Выберите участников</p>
                    <div
                        class="max-h-60 overflow-auto border border-base-200 rounded p-2"
                    >
                        <ul class="space-y-2">
                            <li
                                v-for="u in props.recentUsers"
                                :key="u.id"
                                class="flex items-center gap-2 p-1 hover:bg-base-200 rounded"
                            >
                                <input
                                    type="checkbox"
                                    :id="'user-' + u.id"
                                    class="checkbox checkbox-sm"
                                    :checked="selectedGroupUsers.includes(u.id)"
                                    @change="toggleUserForGroup(u)"
                                />
                                <img
                                    :src="u.avatar"
                                    alt="аватар"
                                    class="w-8 h-8 rounded-full object-cover"
                                />
                                <label :for="'user-' + u.id" class="truncate text-sm">
                                    {{ u.name }}
                                </label>
                            </li>
                            <li
                                v-if="props.recentUsers.length === 0"
                                class="text-xs text-center text-gray-500 py-2"
                            >
                                Нет доступных пользователей
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- 4. Кнопки внизу -->
                <div class="modal-action justify-center space-x-2">
                    <button @click="mode = 'select'" class="btn btn-ghost">Назад</button>
                    <button @click="createGroup" class="btn btn-primary">Создать</button>
                    <form method="dialog">
                        <button class="btn btn-error">Отмена</button>
                    </form>
                </div>
            </template>
        </div>

        <!-- фон-маска -->
        <form method="dialog" class="modal-backdrop">
            <button> </button>
        </form>
    </dialog>
</template>
