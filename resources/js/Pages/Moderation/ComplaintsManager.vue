<script setup>
import WorkspaceHeader from '@/Components/Workspace/WorkspaceHeader.vue'
import WorkspaceEmptyState from '@/Components/Workspace/WorkspaceEmptyState.vue'
import WorkspacePagination from '@/Components/Workspace/WorkspacePagination.vue'
import { ref, watch, computed } from 'vue'
import { Head, Link, usePage, router } from '@inertiajs/vue3'
import { FlagIcon } from '@heroicons/vue/24/solid'
import DashboardLayout from '@/Layouts/DashboardLayout.vue'
import { defineOptions } from 'vue'

defineOptions({ layout: DashboardLayout })

const { props } = usePage()

// === Filters ===
const status  = ref(props.filters.status || '')
const type    = ref(props.filters.type   || '')
const subject = ref(props.filters.subject || '')

// statuses from model constant
const statuses = props.statuses
const statusOptions = computed(() =>
    Object.entries(statuses).map(([value,label])=>({ value, label }))
)

// types for filter
const typeOptions = computed(() =>
    [{ slug:'', name:'Все'}].concat(props.types)
)

// subject filter
const subjectOptions = [
    { value:'', name:'Все' },
    { value:'artwork', name:'Артворк' },
    { value:'comment', name:'Комментарий' },
    { value:'user',    name:'Профиль' },
]

// refetch → always page=1
function fetch() {
    router.get(route('moderation.complaints.index'), {
        page: 1,
        status:  status.value  || undefined,
        type:    type.value    || undefined,
        subject: subject.value || undefined,
    }, {
        replace: true,
        // сброс state => новая выборка
    })
}
watch([status,type,subject], fetch)

function subjectLink(c) {
    const type = c.complaintable_type.split('\\').pop()?.toLowerCase()
    const id   = c.complaintable_id
    const payload = c.complaintable

    if (type === 'artwork') {
        return route('artworks.show', id)
    }

    if (type === 'user') {
        return route('user.profile.show', id)
    }

    if (type === 'comment') {
        const artworkId = payload?.commentable_id
        if (artworkId) {
            return route('artworks.show', artworkId) + `#comment-${id}`
        }
    }

    return '#'
}

function complaintSubjectLabel(c) {
    const type = c.complaintable_type.split('\\').pop()?.toLowerCase()
    const entity = c.complaintable

    if (type === 'artwork') return `Артворк: ${entity?.title ?? '—'}`
    if (type === 'user') return `Профиль: ${entity?.name ?? '—'}`
    if (type === 'comment') return `Комментарий к: ${entity?.commentable?.title ?? '—'}`
    return `${type} #${c.complaintable_id}`
}

</script>

<template>
    <Head title="Модерация → Жалобы" />

    <div class="workspace-page">

        <WorkspaceHeader eyebrow="Модерация" title="Жалобы" :count="props.complaints.total" description="Помогайте сообществу оставаться безопасным. Рассматривайте обращения и принимайте решения." />

        <!-- FILTERS -->
        <div class="workspace-toolbar sm:grid-cols-3">
            <select aria-label="Статус жалобы" v-model="status" class="select select-bordered w-full">
                <option value="">Все статусы</option>
                <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>

            <select aria-label="Тип жалобы" v-model="type" class="select select-bordered w-full">
                <option v-for="opt in typeOptions" :key="opt.slug" :value="opt.slug">
                    {{ opt.name }}
                </option>
            </select>

            <select aria-label="Объект жалобы" v-model="subject" class="select select-bordered w-full">
                <option v-for="opt in subjectOptions" :key="opt.value" :value="opt.value">
                    {{ opt.name }}
                </option>
            </select>
        </div>

        <WorkspaceEmptyState v-if="!props.complaints.data.length" title="Обращений не найдено" description="Сейчас здесь спокойно. Новые жалобы появятся в этом разделе." icon="mdi-shield-check-outline" />
<!-- DESKTOP TABLE -->
        <div v-if="props.complaints.data.length" class="workspace-table hidden md:block">
            <table class="table w-full">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Жалобщик</th>
                    <th>Объект</th>
                    <th>Тип</th>
                    <th>Описание</th>
                    <th>Статус</th>
                    <th>Модератор</th>
                    <th>Дата мод.</th>
                    <th>Дата создан.</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr v-for="c in props.complaints.data" :key="c.id">
                    <td>{{ c.id }}</td>
                    <td>
                        <Link :href="route('user.profile.show', c.user.id)">
                            {{ c.user.name }}
                        </Link>
                    </td>
                    <td>
                        <Link :href="subjectLink(c)" class="link link-primary">
                            {{ complaintSubjectLabel(c) }}
                        </Link>
                    </td>
                    <td>{{ c.type.name }}</td>
                    <td class="truncate max-w-xs">{{ c.details }}</td>
                    <td>
              <span
                  :class="{
                  'workspace-status bg-warning/10 text-warning': c.status==='pending',
                  'workspace-status bg-success/10 text-success': c.status==='approved',
                  'workspace-status bg-error/10 text-error': c.status==='rejected',
                }"
              >
                {{ statuses[c.status] }}
              </span>
                    </td>
                    <td>{{ c.moderator?.name || '—' }}</td>
                    <td>{{ c.reviewed_at ? new Date(c.reviewed_at).toLocaleString() : '—' }}</td>
                    <td>{{ new Date(c.created_at).toLocaleString() }}</td>
                    <td class="text-right">
                        <Link
                            :href="route('moderation.complaints.show', c.id)"
                            class="btn btn-sm btn-ghost"
                        >Обработать</Link>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <!-- MOBILE CARDS -->
        <div v-if="props.complaints.data.length" class="block md:hidden space-y-4">
            <div
                v-for="c in props.complaints.data"
                :key="c.id"
                class="workspace-mobile-card space-y-3"
            >
                <div class="flex justify-between items-center">
                    <span class="font-bold">#{{ c.id }}</span>
                    <Link :href="route('moderation.complaints.show', c.id)"
                          class="btn btn-xs btn-outline">→</Link>
                </div>
                <p><strong>Жалобщик:</strong>
                    <Link :href="route('user.profile.show', c.user.id)" class="link">
                        {{ c.user.name }}
                    </Link>
                </p>
                <p><strong>Объект:</strong>
                    <Link :href="subjectLink(c)" class="link">
                        {{ c.complaintable_type.split('\\').pop() }} #{{ c.complaintable_id }}
                    </Link>
                </p>
                <p><strong>Тип:</strong> {{ c.type.name }}</p>
                <p><strong>Описание:</strong> {{ c.details }}</p>
                <p>
                    <strong>Статус:</strong>
                    <span
                        :class="{
              'text-warning': c.status==='pending',
              'text-success': c.status==='approved',
              'text-error':   c.status==='rejected',
            }"
                    >{{ statuses[c.status] }}</span>
                </p>
                <p><strong>Модератор:</strong> {{ c.moderator?.name || '—' }}</p>
                <p><strong>Дата мод.:</strong> {{ c.reviewed_at ? new Date(c.reviewed_at).toLocaleString() : '—' }}</p>
                <p><strong>Дата созд.:</strong> {{ new Date(c.created_at).toLocaleString() }}</p>
            </div>
        </div>

        <WorkspacePagination :pagination="props.complaints" />

    </div>
</template>
