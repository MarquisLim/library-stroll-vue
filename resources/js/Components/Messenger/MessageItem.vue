<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { usePage } from '@inertiajs/vue3'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import { useArtworkActions } from '@/stores/useArtworkActions'

// Props
const props = defineProps({
    msg: { type: Object, required: true },
})
const msg = props.msg

// Auth
const page = usePage()
const myId = page.props.auth.user.id
const isMine = computed(() => msg.user_id === myId)

// Pinia store for likes and collections
const artStore = useArtworkActions()
// Initialize collections if provided
onMounted(() => {
    const cols = page.props.collections || []
    artStore.setCollections(
        Array.isArray(cols)
            ? cols
            : Object.entries(cols).map(([id, title]) => ({ id: +id, title }))
    )
})

// Reactions
const reacting = ref(false)
async function toggleReaction(emoji) {
    if (reacting.value) return
    reacting.value = true
    await axios.post(
        `/messenger/messages/${msg.id}/reaction`,
        { emoji }
    )
    reacting.value = false
}

// Helpers
function formatBytes(bytes) {
    if (bytes === 0) return '0 B'
    const k = 1024, sizes = ['B','KB','MB','GB','TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Artwork actions
function onLikeArtwork() {
    artStore.toggleLike(msg.artwork)
}
function onOpenSelector(art, rect) {
    artStore.openSelector(art, rect)
}

</script>

<template>
    <div :class="[
      'flex items-start mb-4',
      isMine ? 'justify-end space-x-reverse' : 'justify-start'
    ]"
         :data-id="msg.id"
         :data-incoming="isMine ? 0 : 1">

        <!-- Avatar -->
        <img
            :src="msg.user.profile_photo_url"
            class="w-8 h-8 rounded-full object-cover mr-2"
            :class="isMine ? 'ml-2' : ''"
            alt="avatar"
        />

        <div class="max-w-xs">
            <!-- Username -->
            <div :class="[
          'text-xs text-base-content/60 mb-1',
          isMine ? 'text-right' : 'text-left'
        ]">
                {{ msg.user.name }}
            </div>

            <!-- Bubble -->
            <div :class="[
          'inline-block p-3 rounded-lg break-words',
          isMine ? 'bg-primary text-primary-content' : 'bg-base-200 dark:bg-base-800'
        ]">

                <!-- Text -->
                <p v-if="msg.body">{{ msg.body }}</p>

                <!-- Artwork embed -->
                <div v-if="msg.artwork" class="mt-2">
                    <div class="w-full max-w-xs">
                        <ArtworkCard
                            :art="msg.artwork"
                            @like="onLikeArtwork"
                            @open-selector="onOpenSelector"
                        />
                    </div>
                </div>

                <!-- Attachments -->
                <template v-for="att in msg.attachments" :key="att.id">
                    <img
                        v-if="att.mime.startsWith('image/')"
                        :src="att.url"
                        class="mt-2 rounded"
                        alt="attachment"
                    />
                    <video
                        v-else-if="att.mime.startsWith('video/')"
                        :src="att.url"
                        class="mt-2 rounded max-w-full"
                        controls
                    />
                    <div v-else class="mt-2 p-3 bg-blue-100 rounded-lg text-blue-900">
                        <div class="flex items-center space-x-2">
                            <svg xmlns="http://www.w3.org/2000/svg"
                                 class="w-5 h-5 text-blue-500 shrink-0"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M7 7V4a1 1 0 011-1h6l4 4v12a1 1 0 01-1 1H8a1 1 0 01-1-1v-3m0-4h8"/>
                            </svg>
                            <a :href="att.url"
                               class="text-blue-700 underline break-all"
                               target="_blank">
                                {{ att.path.split('/').pop() }}
                            </a>
                        </div>
                        <div class="text-xs text-blue-600 mt-1 text-right">
                            {{ formatBytes(att.size) }}
                        </div>
                    </div>
                </template>

                <!-- Timestamp -->
                <div :class="[
                    'text-[10px] text-base-content/50 mt-1',
                    isMine ? 'text-primary-content/80 text-right' : 'text-base-content/50 text-left'
                  ]"
                >
                    {{ new Date(msg.created_at).toLocaleString() }}
                </div>
            </div>
        </div>
    </div>
</template>
