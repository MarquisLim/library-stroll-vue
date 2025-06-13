<script setup>
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

    <div class="p-4 bg-base-100 text-base-content min-h-screen space-y-6">

        <header class="flex items-center gap-2">
            <FlagIcon class="w-6 h-6 text-primary" />
            <h1 class="text-2xl font-bold">Жалобы</h1>
        </header>

        <!-- FILTERS -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
            <select v-model="status" class="select select-bordered w-full">
                <option value="">Все статусы</option>
                <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                </option>
            </select>

            <select v-model="type" class="select select-bordered w-full">
                <option v-for="opt in typeOptions" :key="opt.slug" :value="opt.slug">
                    {{ opt.name }}
                </option>
            </select>

            <select v-model="subject" class="select select-bordered w-full">
                <option v-for="opt in subjectOptions" :key="opt.value" :value="opt.value">
                    {{ opt.name }}
                </option>
            </select>
        </div>

        <!-- DESKTOP TABLE -->
        <div class="hidden md:block overflow-x-auto rounded-lg shadow">
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
                  'bg-yellow-100 text-yellow-800 px-2 py-1 rounded': c.status==='pending',
                  'bg-green-100  text-green-800  px-2 py-1 rounded': c.status==='approved',
                  'bg-red-100    text-red-800    px-2 py-1 rounded': c.status==='rejected',
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
        <div class="block md:hidden space-y-4">
            <div
                v-for="c in props.complaints.data"
                :key="c.id"
                class="card bg-base-200 p-4 space-y-2"
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
              'text-yellow-600': c.status==='pending',
              'text-green-600': c.status==='approved',
              'text-red-600':   c.status==='rejected',
            }"
                    >{{ statuses[c.status] }}</span>
                </p>
                <p><strong>Модератор:</strong> {{ c.moderator?.name || '—' }}</p>
                <p><strong>Дата мод.:</strong> {{ c.reviewed_at ? new Date(c.reviewed_at).toLocaleString() : '—' }}</p>
                <p><strong>Дата созд.:</strong> {{ new Date(c.created_at).toLocaleString() }}</p>
            </div>
        </div>

        <!-- PAGINATION -->
        <nav v-if="props.complaints.last_page>1" class="flex justify-center space-x-1">
            <Link
                v-for="l in props.complaints.links.filter(l=>l.label && !isNaN(l.label))"
                :key="l.label"
                :href="l.url"
                class="px-3 py-1 rounded"
                :class="l.active
          ? 'bg-primary text-primary-content'
          : 'bg-base-200 hover:bg-base-300'"
            >{{ l.label }}</Link>
        </nav>

    </div>
</template>
