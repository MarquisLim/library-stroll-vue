<script setup>
import { ref, computed } from 'vue'
import { Head, usePage, Link, router } from '@inertiajs/vue3'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { defineOptions } from 'vue'

defineOptions({ layout: DashboardLayout })

const { props } = usePage()
const complaint = props.complaint
const statuses = Object.entries(props.statuses).map(
    ([value, label]) => ({ value, label })
)
const complaintable = computed(() => props.complaint.complaintable)

// локальные поля для формы
const status        = ref(complaint.status)
const moderatorNote = ref(complaint.moderator_note || '')

// отправка решения
function submit() {
    router.post(
        route('moderation.complaints.review', complaint.id),
        { status: status.value, moderator_note: moderatorNote.value },
        { preserveScroll: true }
    )
}

// вычисляем псевдо-ресурс: берём последний фрагмент класса и добавляем "s"
const resourceName = computed(() => {
    const parts = complaint.complaintable_type.split('\\')
    return parts[parts.length - 1].toLowerCase() + 's'
})

const resourceLink = computed(() => {
    const type = resourceName.value
    const id = complaint.complaintable_id

    if (type === 'artworks') return route('artworks.show', id)
    if (type === 'profiles') return route('profiles.show', id)
    if (type === 'comments') return null

    return null
})
</script>

<template>
    <Head :title="`Жалоба #${complaint.id} — ${status}`" />

    <div class="space-y-6 bg-base-100 text-base-content min-h-screen p-6">

        <header class="flex items-center gap-4">
            <!-- ссылка назад -->
            <Link
                :href="route('moderation.complaints.index')"
                class="btn btn-ghost btn-sm"
            >
                ←
            </Link>

            <!-- Заголовок с текущим статусом -->
            <h1 class="text-2xl font-bold">
                Жалоба #{{ complaint.id }} — <span class="capitalize">{{ status }}</span>
            </h1>
        </header>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- левая колонка -->
            <div class="space-y-2">
                <p><strong>Кто</strong>: {{ complaint.user.name }}</p>

                <p>
                    <strong>Что</strong>:
                    <Link
                        :href="resourceLink"
                        class="link link-primary"
                    >
                        <span class="capitalize">
                          {{ complaint.complaintable_type.split('\\').pop() }}
                        </span>
                        #{{ complaint.complaintable_id }}
                    </Link>
                </p>
                <div v-if="complaintable" class="bg-base-200 p-4 rounded">
                    <h4 class="text-md font-semibold mb-2">Содержимое:</h4>

                    <!-- Artwork -->
                    <div v-if="resourceName === 'artworks'">
                        <p><strong>Название:</strong> {{ complaintable.title }}</p>
                        <p v-if="complaintable.description">
                            <strong>Описание:</strong> {{ complaintable.description.slice(0, 150) }}…
                        </p>
                    </div>

                    <!-- Comment -->
                    <div v-else-if="resourceName === 'comments'">
                        <p><strong>Комментарий:</strong> {{ complaintable.text }}</p>
                    </div>

                    <!-- Profile -->
                    <div v-else-if="resourceName === 'profiles'">
                        <p><strong>Пользователь:</strong> {{ complaintable.name }}</p>
                        <p v-if="complaintable.bio"><strong>О себе:</strong> {{ complaintable.bio }}</p>
                    </div>
                </div>


                <p><strong>Тип</strong>: {{ complaint.type.name }}</p>
                <p>
                    <strong>Описание</strong>:<br/>
                    {{ complaint.details || '—' }}
                </p>
                <p>
                    <strong>Создано</strong>:
                    {{ new Date(complaint.created_at).toLocaleString() }}
                </p>
            </div>

            <!-- правая колонка -->
            <div class="space-y-4">
                <label class="block">
                    <span>Статус жалобы</span>
                    <select v-model="status" class="select select-bordered w-full">
                        <option
                            v-for="opt in statuses"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </label>

                <label class="block">
                    <span>Примечание модератора</span>
                    <textarea
                        v-model="moderatorNote"
                        rows="4"
                        class="textarea textarea-bordered w-full"
                        placeholder="Можно оставить комментарий"
                    />
                </label>

                <button class="btn btn-primary" @click="submit">
                    Сохранить
                </button>
            </div>
        </div>
    </div>
</template>
