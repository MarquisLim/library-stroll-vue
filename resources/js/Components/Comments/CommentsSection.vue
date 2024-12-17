<template>
    <div class="mt-4 bg-gray-800 p-4 rounded">
        <h3 class="font-bold text-lg mb-2">Комментарии</h3>

        <!-- Список комментариев -->
        <div v-if="comments.length">
            <div v-for="comment in comments" :key="comment.id" class="mt-2 bg-gray-700 p-2 rounded">
                <!-- Информация о комментаторе -->
                <div class="flex items-center space-x-2">
                    <img class="h-6 w-6 rounded-full object-cover" :src="comment.user.profile_photo_url" alt="User Avatar" />
                    <span class="font-semibold">{{ comment.user.name }}</span>
                    <span class="text-gray-400 text-xs">{{ timeAgo(comment.created_at) }}</span>
                </div>
                <!-- Текст комментария -->
                <p class="ml-8">{{ comment.text }}</p>

                <!-- Ответы на комментарий -->
                <div v-if="comment.replies && comment.replies.length" class="ml-8 mt-1">
                    <div v-for="reply in comment.replies" :key="reply.id" class="bg-gray-600 p-1 rounded mt-1">
                        <div class="flex items-center space-x-2">
                            <img class="h-5 w-5 rounded-full object-cover" :src="reply.user.profile_photo_url" alt="User Avatar" />
                            <span class="font-semibold text-sm">{{ reply.user.name }}</span>
                            <span class="text-gray-300 text-xs">{{ timeAgo(reply.created_at) }}</span>
                        </div>
                        <p class="ml-6 text-sm">{{ reply.text }}</p>
                    </div>
                </div>
            </div>
        </div>
        <div v-else class="text-gray-400">Нет комментариев. Будьте первым!</div>

        <!-- Форма добавления комментария -->
        <div class="mt-4 flex space-x-2">
            <input
                v-model="newComment"
                type="text"
                placeholder="Написать комментарий..."
                class="flex-1 px-2 py-1 rounded bg-gray-700 text-white border border-gray-600"
                @keyup.enter="postComment"
            />
            <button class="btn btn-primary" @click="postComment">Отправить</button>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const props = defineProps({
    artworkId: {
        type: Number,
        required: true
    }
})

const emit = defineEmits(['updateCommentsCount'])

const comments = ref([])
const newComment = ref('')

// Загрузка комментариев при монтировании
onMounted(() => {
    loadComments()
})

// Функция для загрузки комментариев
function loadComments() {
    axios.get(`/artworks/${props.artworkId}/comments`)
        .then(res => {
            comments.value = res.data.comments
            // Эмитируем обновление количества комментариев
            emit('updateCommentsCount', comments.value.length)
        }).catch(err => {
        console.error('Ошибка при загрузке комментариев:', err)
    })
}

// Функция для отправки нового комментария
function postComment() {
    if (!newComment.value.trim()) return
    axios.post(`/artworks/${props.artworkId}/comments`, { text: newComment.value })
        .then(res => {
            comments.value.unshift(res.data.comment)
            newComment.value = ''
            // Эмитируем обновление количества комментариев
            emit('updateCommentsCount', comments.value.length)
        }).catch(err => {
        console.error('Ошибка при отправке комментария:', err)
    })
}

// Функция для отображения времени в формате "X мин назад"
function timeAgo(dateStr) {
    const date = new Date(dateStr)
    const now = new Date()
    const diffInSeconds = Math.floor((now - date) / 1000)

    const intervals = [
        { label: 'год', seconds: 31536000 },
        { label: 'месяц', seconds: 2592000 },
        { label: 'день', seconds: 86400 },
        { label: 'час', seconds: 3600 },
        { label: 'минуту', seconds: 60 },
    ]

    for (const interval of intervals) {
        const count = Math.floor(diffInSeconds / interval.seconds)
        if (count >= 1) {
            return `${count} ${pluralize(count, interval.label)} назад`
        }
    }

    return 'только что'
}

// Функция для правильного склонения слов
function pluralize(count, singular) {
    const forms = {
        'год': ['год', 'года', 'лет'],
        'месяц': ['месяц', 'месяца', 'месяцев'],
        'день': ['день', 'дня', 'дней'],
        'час': ['час', 'часа', 'часов'],
        'минуту': ['минуту', 'минуты', 'минут'],
    }

    const [form1, form2, form5] = forms[singular]
    if (count % 10 === 1 && count % 100 !== 11) return form1
    if ([2, 3, 4].includes(count % 10) && ![12, 13, 14].includes(count % 100)) return form2
    return form5
}
</script>

<style scoped>
/* Добавьте любые дополнительные стили при необходимости */
</style>
