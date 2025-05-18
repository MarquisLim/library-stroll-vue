<script setup>
import { ref, onMounted, nextTick } from 'vue'
import axios from 'axios'
import { Link, usePage } from '@inertiajs/vue3'
import { ChatBubbleOvalLeftEllipsisIcon, EllipsisHorizontalIcon } from '@heroicons/vue/24/outline'
import ComplaintModal from '@/Components/ComplaintModal.vue'

const props = defineProps({
    artworkId: Number,
    artworkOwner: Number,
    complaintTypes: Array
})
const emit = defineEmits(['updateCommentsCount'])

let page = 1
const comments = ref([])
const busy = ref(false)
const hasMore = ref(true)

const newComment = ref('')
const newError = ref('')

const replyText = ref({})
const replyError = ref({})
const isReplying = ref({})
const replyTarget = ref({})

const expanded = ref({})

const isAuth = usePage().props.auth.user

const showComplaint = ref(false)
const complaintTarget = ref(null)

function openComplaint(id) {
    complaintTarget.value = id
    showComplaint.value = true
}

function renderText(t) {
    const esc = t.replace(/&/g, '&amp;').replace(/</g, '&lt;')
    return esc
        .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" class="text-primary hover:underline">$1</a>')
        .replace(/\n/g, '<br>')
}

function toggleExpand(id) {
    expanded.value[id] = !expanded.value[id]
}

function toggleReply(id, userName) {
    Object.keys(isReplying.value).forEach(k => (isReplying.value[k] = false))
    isReplying.value[id] = true
    replyTarget.value[id] = userName
    replyText.value[id] = ''
    nextTick(() => document.getElementById(`reply-${id}`)?.focus())
}

function formatDate(ts) {
    return new Date(ts).toLocaleString('ru-RU', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    })
}

function hydrateReplies(list) {
    list.forEach(root => {
        root.replies?.forEach(r => {
            r.parentName = r.parent?.user?.name
        })
    })
}

onMounted(loadComments)

function loadComments() {
    if (busy.value || !hasMore.value) return
    busy.value = true
    axios
        .get(`/artworks/${props.artworkId}/comments`, { params: { page } })
        .then(({ data }) => {
            hydrateReplies(data.comments)
            comments.value.push(...data.comments)
            hasMore.value = data.hasMore
            page++
            emit('updateCommentsCount', data.total)
        })
        .finally(() => (busy.value = false))
}

function pushReplyIntoTree(reply) {
    const root =
        comments.value.find(c => c.id === reply.parent_id) ||
        comments.value.find(c => c.replies?.some(r => r.id === reply.parent_id))
    if (root) {
        root.replies = root.replies || []
        root.replies.unshift(reply)
    }
}

function send(parentId = null) {
    const txt = (parentId ? replyText.value[parentId] : newComment.value).trim()
    if (!txt) return
    if (txt.length > 1000) {
        ;(parentId ? replyError.value[parentId] : newError).value = 'Не более 1000 символов'
        return
    }
    const url = parentId
        ? `/artworks/comments/${parentId}/reply`
        : `/artworks/${props.artworkId}/comments`
    axios.post(url, { text: txt }).then(({ data }) => {
        if (parentId) {
            data.reply.parentName = data.reply.parent.user.name
            pushReplyIntoTree(data.reply)
            isReplying.value[parentId] = false
            replyText.value[parentId] = ''
            replyError.value[parentId] = ''
        } else {
            comments.value.unshift(data.comment)
            newComment.value = ''
            newError.value = ''
        }
        emit('updateCommentsCount', comments.value.length)
    })
}
</script>

<template>
    <div class="bg-base-200 dark:bg-base-800 rounded-xl space-y-6">
        <div class="flex items-center gap-2">
            <ChatBubbleOvalLeftEllipsisIcon class="w-6 h-6 text-primary" />
            <h3 class="text-xl font-semibold">Комментарии ({{ comments.length }})</h3>
        </div>

        <div v-if="isAuth" class="space-y-1">
      <textarea
          v-model="newComment"
          rows="3"
          maxlength="1000"
          class="textarea textarea-bordered w-full resize-y"
          placeholder="Добавить комментарий…"
          @keydown.enter.prevent="send()"
      />
            <div class="flex justify-between text-sm">
                <span class="text-error">{{ newError }}</span>
                <span>{{ newComment.length }}/1000</span>
            </div>
            <button class="btn btn-primary" @click="send()">Отправить</button>
        </div>

        <div v-if="busy && page === 1" class="flex justify-center py-10">
            <span class="loading loading-spinner loading-lg text-primary" />
        </div>

        <div v-if="comments.length" class="space-y-6">
            <div v-for="c in comments" :key="c.id" class="space-y-4">
                <div class="flex gap-3">
                    <img :src="c.user.profile_photo_url" class="w-9 h-9 rounded-full object-cover" />
                    <div class="flex-1">
                        <div class="flex items-center gap-2">
                            <Link :href="`/profile/${c.user.id}`" class="font-semibold hover:underline">
                                {{ c.user.name }}
                            </Link>
                            <span v-if="c.user.id === props.artworkOwner" class="badge badge-sm badge-outline">Автор</span>
                            <span class="text-xs opacity-60">{{ formatDate(c.created_at) }}</span>
                            <div class="dropdown dropdown-end ml-auto">
                                <button tabindex="0" class="btn btn-xs btn-ghost">
                                    <EllipsisHorizontalIcon class="w-4 h-4" />
                                </button>
                                <ul tabindex="0" class="dropdown-content menu p-1 shadow bg-base-100 rounded-box w-28">
                                    <li>
                                        <button @click="openComplaint(c.id)">Пожаловаться</button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <p v-html="renderText(c.text)" :class="expanded[c.id] ? '' : 'line-clamp-3'" class="mt-1" />
                        <div class="flex items-center gap-2 mt-1">
                            <button
                                v-if="c.text.length > 200"
                                class="link link-primary text-xs"
                                @click="toggleExpand(c.id)"
                            >{{ expanded[c.id] ? 'Свернуть' : 'Читать полностью' }}</button>
                            <button
                                v-if="isAuth"
                                class="text-primary text-xs"
                                @click="toggleReply(c.id, c.user.name)"
                            >Ответить</button>
                        </div>
                    </div>
                </div>

                <div v-if="isReplying[c.id]" class="md:pl-12 pl-0 space-y-1 w-full">
                    <div class="text-sm opacity-60">↳ Ответ {{ replyTarget[c.id] }}</div>
                    <textarea
                        :id="`reply-${c.id}`"
                        v-model="replyText[c.id]"
                        rows="2"
                        maxlength="1000"
                        class="textarea textarea-bordered w-full resize-y"
                        placeholder="Ваш ответ…"
                        @keydown.enter.prevent="send(c.id)"
                    />
                    <div class="flex justify-between text-sm">
                        <span class="text-error">{{ replyError[c.id] }}</span>
                        <span>{{ replyText[c.id]?.length || 0 }}/1000</span>
                    </div>
                    <button class="btn btn-sm btn-primary" @click="send(c.id)">Отправить</button>
                </div>

                <div v-if="c.replies?.length" class="md:pl-12 pl-0">
                    <details class="group space-y-4">
                        <summary class="cursor-pointer text-sm opacity-60">
                            {{ c.replies.length }} {{ c.replies.length > 1 ? 'ответа' : 'ответ' }}
                        </summary>
                        <div class="mt-2 space-y-4">
                            <div v-for="r in c.replies" :key="r.id" class="space-y-2" :id="'comment-' + r.id">
                                <div class="flex gap-3">
                                    <img :src="r.user.profile_photo_url" class="w-8 h-8 rounded-full object-cover" />
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 text-sm">
                                            <Link :href="`/profile/${r.user.id}`" class="font-semibold hover:underline">
                                                {{ r.user.name }}
                                            </Link>
                                            <span v-if="r.user.id === props.artworkOwner" class="badge badge-xs badge-outline">Автор</span>
                                            <span class="text-xs opacity-60">{{ formatDate(r.created_at) }}</span>
                                            <div class="dropdown dropdown-end ml-auto">
                                                <button tabindex="0" class="btn btn-xs btn-ghost">
                                                    <EllipsisHorizontalIcon class="w-4 h-4" />
                                                </button>
                                                <ul tabindex="0" class="dropdown-content menu p-1 shadow bg-base-100 rounded-box w-28">
                                                    <li>
                                                        <button @click="openComplaint(r.id)">Пожаловаться</button>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="text-xs opacity-60">↳ к {{ r.parentName }}</div>
                                        <p
                                            v-html="renderText(r.text)"
                                            :class="expanded[r.id] ? '' : 'line-clamp-3'"
                                            class="mt-1 text-sm"
                                        />
                                        <div class="flex items-center gap-2 mt-1">
                                            <button
                                                v-if="r.text.length > 200"
                                                class="link link-primary text-xxs"
                                                @click="toggleExpand(r.id)"
                                            >{{ expanded[r.id] ? 'Свернуть' : 'Читать полностью' }}</button>
                                            <button
                                                v-if="isAuth"
                                                class="text-primary text-xs"
                                                @click="toggleReply(r.id, r.user.name)"
                                            >Ответить</button>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="isReplying[r.id]" class="md:pl-16 pl-0 space-y-1 w-full">
                                    <div class="text-sm opacity-60">↳ Ответ {{ replyTarget[r.id] }}</div>
                                    <textarea
                                        :id="`reply-${r.id}`"
                                        v-model="replyText[r.id]"
                                        rows="2"
                                        maxlength="1000"
                                        class="textarea textarea-bordered w-full resize-y"
                                        placeholder="Ваш ответ…"
                                        @keydown.enter.prevent="send(r.id)"
                                    />
                                    <div class="flex justify-between text-sm">
                                        <span class="text-error">{{ replyError[r.id] }}</span>
                                        <span>{{ replyText[r.id]?.length || 0 }}/1000</span>
                                    </div>
                                    <button class="btn btn-sm btn-primary" @click="send(r.id)">Отправить</button>
                                </div>
                            </div>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <p v-else-if="!busy" class="opacity-60">Нет комментариев.</p>

        <button
            v-if="hasMore && !busy"
            class="btn btn-outline w-full"
            @click="loadComments"
        >Показать ещё</button>

        <ComplaintModal
            :show="showComplaint"
            :types="complaintTypes"
            targetType="comment"
            :target-id="complaintTarget"
            @close="showComplaint = false"
        />
    </div>
</template>

<style scoped>
.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.group > summary::-webkit-details-marker {
    display: none;
}
.group > summary:before {
    content: '▾';
    margin-right: 0.25rem;
    transition: transform 0.2s;
}
.group[open] > summary:before {
    transform: rotate(-180deg);
}
</style>
