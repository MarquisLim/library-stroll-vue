<script setup>
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
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
const saving = ref(false)
const saveError = ref('')
const saved = ref(false)

// отправка решения
function submit() {
    if (saving.value) return
    saveError.value = ''
    saved.value = false
    router.post(
        route('moderation.complaints.review', complaint.id),
        { status: status.value, moderator_note: moderatorNote.value },
        { preserveScroll: true, onStart: () => { saving.value = true }, onFinish: () => { saving.value = false }, onSuccess: () => { saved.value = true }, onError: errors => { saveError.value = Object.values(errors).flat().join(' ') } }
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
    if (type === 'users') return route('user.profile.show', id)
    if (type === 'comments') return null

    return null
})
</script>

<template>
    <Head :title="'Жалоба #' + complaint.id" />
    <div class="workspace-page">
        <Link :href="route('moderation.complaints.index')" class="inline-flex items-center gap-2 text-sm font-semibold text-base-content/60 hover:text-primary"><i class="mdi mdi-arrow-left" /> К списку обращений</Link>
        <WorkspaceHeader eyebrow="Модерация · рассмотрение обращения" :title="'Жалоба #' + complaint.id" description="Изучите материалы обращения и зафиксируйте решение.">
            <template #actions><span :class="['workspace-status', complaint.status === 'pending' ? 'bg-warning/10 text-warning' : complaint.status === 'approved' ? 'bg-success/10 text-success' : 'bg-error/10 text-error']">{{ props.statuses[complaint.status] }}</span></template>
        </WorkspaceHeader>
        <div class="grid items-start gap-5 xl:grid-cols-2">
            <section class="ui-panel min-w-0 p-5 sm:p-7">
                <h2 class="text-lg">Детали обращения</h2>
                <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div><dt class="ui-muted text-xs">Отправитель</dt><dd class="mt-1 font-semibold">{{ complaint.user?.name || 'Пользователь удалён' }}</dd></div>
                    <div><dt class="ui-muted text-xs">Дата обращения</dt><dd class="mt-1 text-sm">{{ new Date(complaint.created_at).toLocaleString('ru-RU') }}</dd></div>
                    <div><dt class="ui-muted text-xs">Причина</dt><dd class="mt-1 font-semibold">{{ complaint.type?.name || '—' }}</dd></div>
                    <div><dt class="ui-muted text-xs">Объект</dt><dd class="mt-1 text-sm"><Link v-if="resourceLink" :href="resourceLink" class="font-semibold text-primary">Открыть объект <i class="mdi mdi-arrow-top-right" /></Link><span v-else>#{{ complaint.complaintable_id }}</span></dd></div>
                </dl>
                <div class="mt-6 border-t border-base-300 pt-5"><h3 class="text-sm">Описание жалобы</h3><p class="ui-muted mt-3 whitespace-pre-wrap break-words text-sm">{{ complaint.details || 'Описание не указано.' }}</p></div>
                <div class="mt-6 rounded-xl border border-base-300 bg-base-200/50 p-4"><p class="ui-eyebrow !text-[10px]">Содержимое объекта</p>
                    <template v-if="complaintable"><p class="mt-3 font-semibold">{{ complaintable.title || complaintable.name || 'Комментарий' }}</p><p class="ui-muted mt-2 whitespace-pre-wrap break-words text-sm">{{ complaintable.text || complaintable.description || complaintable.bio || 'Дополнительного описания нет.' }}</p></template>
                    <p v-else class="ui-muted mt-3 text-sm">Объект удалён или недоступен.</p>
                </div>
            </section>
            <form class="ui-panel min-w-0 p-5 sm:p-7" @submit.prevent="submit">
                <h2 class="text-lg">Решение модератора</h2><p class="ui-muted mt-2 text-sm">Выберите статус и добавьте пояснение к решению.</p>
                <div v-if="saveError" role="alert" class="mt-5 rounded-xl bg-error/10 p-4 text-sm text-error">{{ saveError }}</div>
                <div v-if="saved" role="status" class="mt-5 rounded-xl bg-success/10 p-4 text-sm text-success"><i class="mdi mdi-check-circle-outline" /> Решение сохранено</div>
                <label class="mt-6 block text-sm font-semibold">Статус обращения<select v-model="status" class="select mt-2 w-full" :disabled="saving"><option v-for="opt in statuses" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select></label>
                <label class="mt-5 block text-sm font-semibold">Примечание модератора<textarea v-model="moderatorNote" rows="6" maxlength="2000" class="textarea mt-2 w-full" placeholder="На что вы обратили внимание и почему приняли это решение…" :disabled="saving" /></label>
                <div class="mt-6 flex justify-end border-t border-base-300 pt-5"><button class="btn btn-primary" :disabled="saving"><span v-if="saving" class="loading loading-spinner loading-xs" /><i v-else class="mdi mdi-check" /> Сохранить решение</button></div>
            </form>
        </div>
    </div>
</template>
