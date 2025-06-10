<script setup>
import {ref, nextTick, onMounted, computed} from 'vue'
import {Link, usePage, router} from '@inertiajs/vue3'
import axios from 'axios'

// Heroicons
import {
    ArrowLeftIcon,
    PlusCircleIcon,
    EyeIcon,
    ArrowDownTrayIcon,
    ShareIcon,
    HeartIcon as HeartOutlineIcon,
    EllipsisHorizontalIcon,
} from '@heroicons/vue/24/outline'
import { HeartIcon as HeartSolidIcon } from '@heroicons/vue/24/solid'


// Layout & components
import AppLayout from '@/Layouts/AppLayout.vue'
import MasonryGrid from '@/Components/MasonryGrid.vue'
import ArtworkCard from '@/Components/Gallery/ArtworkCard.vue'
import CommentsSection from '@/Components/Comments/CommentsSection.vue'
import ShareModal from '@/Components/Messenger/ShareModal.vue'
import ComplaintModal from '@/Components/ComplaintModal.vue'
import Plyr from 'plyr'
import 'plyr/dist/plyr.css'

// Pinia store
import {useArtworkActions} from '@/stores/useArtworkActions'

const {toggleLike, openSelector, setCollections} = useArtworkActions()

// props from Inertia
const page = usePage()
const { requireAuth } = useArtworkActions()
const artwork = ref({...page.props.artwork})
const myChats = usePage().props.myChats
const author = page.props.author
const complaintTypes  = page.props.complaintTypes
const showComplaint   = ref(false)
const showShare = ref(false)
const plyrVideo = ref(null)

const loaded = ref(false)
const onMediaLoad = () => (loaded.value = true)

const aspectRatio = computed(() => {
    const w = artwork.value.thumb_width || 1
    const h = artwork.value.thumb_height || 1
    return (h / w) * 100 // аналогично artworkcard
})

function openComplaint() {
    if (!requireAuth(openComplaint)) return;
    showComplaint.value = true;
}
function openShare() {
    if (!requireAuth(openShare)) return;
    showShare.value = true
}

if (page.props.collections) setCollections(page.props.collections)

// tabs
const tabs = ['comments', 'author', 'similar']
const activeTab = ref('comments')
function openTab(t) {
    activeTab.value = t
}
const initialAuthorWorks  = page.props.authorWorks  || []
const initialSimilarWorks = page.props.similarWorks || []
const authorWorks  = ref([...initialAuthorWorks])
const similarWorks = ref([...initialSimilarWorks])

// helpers
const expanded = ref(false)
const canDownload = ref(!!artwork.value.allow_download)
const showComments = ref(!!artwork.value.allow_comments)

function goBack() {
    history.back()
}

function goToTag(tag) {
    router.get('/search', {q: tag})
}

function openColSelector(e) {
    const rect = e.currentTarget.getBoundingClientRect()
    openSelector(artwork.value, rect)
}

// canvas for image protection
const canvasRef = ref(null)
onMounted(async () => {
    if (artwork.value.type === 'image') {
        const res = await fetch(artwork.value.media[0].original_url, {credentials: 'include'})
        const blob = await res.blob()
        const img = new Image()
        img.onload = () => {
            const canvas = canvasRef.value
            canvas.width = img.naturalWidth
            canvas.height = img.naturalHeight
            const ctx = canvas.getContext('2d')
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
            // watermark
            ctx.font = '20px sans-serif'
            ctx.fillStyle = 'rgba(255, 255, 255, 0.5)'
            ctx.fillText('© LibraryStroll', 10, canvas.height - 10)
        }
        img.src = URL.createObjectURL(blob)
    }
})


onMounted(() => {
    if (artwork.value.type === 'video') {
        nextTick(() => {
            if (plyrVideo.value) {
                new Plyr(plyrVideo.value, {
                    autoplay: true,
                    muted: true,
                    loop: { active: true },
                    controls: ['play', 'progress', 'current-time', 'mute', 'volume', 'fullscreen'],
                })
            }
        })
    }
})

</script>

<template>
    <AppLayout :title="artwork.title || 'Artwork'">
        <div class="min-h-screen bg-base-200 dark:bg-base-800 text-base-content p-4 space-y-6">

            <!-- back -->
            <button class="btn btn-ghost btn-sm rounded-full" @click="goBack">
                <ArrowLeftIcon class="w-5 h-5"/>
            </button>

            <!-- CARD -->
            <div
                class="mx-auto w-full max-w-5xl bg-base-100 dark:bg-base-700 rounded-2xl p-6 shadow flex flex-col md:flex-row gap-6">

                <!-- media -->
                <div
                    class="md:w-1/2 flex justify-center relative"
                    @contextmenu.prevent
                >
                    <!-- video with no-download -->
                    <video
                        ref="plyrVideo"
                        v-if="artwork.type==='video'"
                        class="plyr-video max-h-[80vh] w-full rounded-xl select-none"
                        controls
                        controlsList="nodownload"
                        autoplay
                        muted
                        loop
                        playsinline
                        @contextmenu.prevent
                    >
                        <source :src="artwork.media[0]?.original_url" type="video/mp4" />
                    </video>
                    <img
                        v-else-if="artwork.media[0]?.mime_type === 'image/gif'"
                        :src="artwork.media[0]?.original_url"
                        class="max-h-[80vh] w-full rounded-xl select-none object-contain"
                        draggable="false"
                        @contextmenu.prevent
                    />

                    <canvas
                        v-else
                        ref="canvasRef"
                        class="max-h-[80vh] w-full rounded-xl select-none"
                        draggable="false"
                        @contextmenu.prevent
                    />
                </div>

                <!-- info -->
                <div class="md:w-1/2 flex flex-col gap-5">

                    <div class="flex justify-between items-center gap-2">
                        <h1 class="text-3xl font-bold break-words flex-1">
                            {{ artwork.title || 'Без названия' }}
                        </h1>
                        <button
                            @click="openShare"
                            class="btn btn-sm btn-circle btn-ghost"
                            title="Поделиться"
                        >
                            <ShareIcon class="w-5 h-5"/>
                        </button>
                        <div class="dropdown dropdown-end">
                            <label tabindex="0" class="btn btn-sm btn-circle btn-ghost">
                                <EllipsisHorizontalIcon class="w-5 h-5" />
                            </label>
                            <ul
                                tabindex="0"
                                class="dropdown-content menu p-2 shadow bg-base-100 rounded-box w-40 z-10"
                            >
                                <li>
                                    <button
                                        @click="openComplaint"
                                        class="flex items-center gap-2 w-full text-left"
                                    >
                                        <span>Пожаловаться</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <ComplaintModal
                            :show="showComplaint"
                            :types="complaintTypes"
                            targetType="artwork"
                            :targetId="artwork.id"
                            @close="showComplaint = false"
                        />
                    </div>

                    <div v-if="artwork.description" class="space-y-2">
                        <p :class="expanded ? '' : 'line-clamp-5'">{{ artwork.description }}</p>
                        <button
                            v-if="artwork.description.length > 200"
                            class="link link-primary text-sm"
                            @click="expanded = !expanded"
                        >
                            {{ expanded ? 'Скрыть' : 'Читать полностью' }}
                        </button>
                    </div>

                    <div v-if="artwork.tags?.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="t in artwork.tags"
                            :key="t.id"
                            class="badge badge-outline badge-primary lowercase"
                            @click="goToTag(t.name)"
                        >
                            #{{ t.name }}
                        </button>
                    </div>

                    <div class="flex items-center gap-3">
                        <img
                            :src="author.profile_photo_url"
                            class="w-10 h-10 rounded-full object-cover ring ring-primary/50"
                        />
                        <Link
                            :href="`/profile/${author.id}`"
                            class="font-semibold text-primary hover:underline"
                        >
                            {{ author.name }}
                        </Link>
                    </div>

                    <div class="flex items-center gap-4">
                        <button class="btn btn-circle btn-sm btn-ghost" @click="toggleLike(artwork)">
                            <component
                                :is="artwork.liked_by_user ? HeartSolidIcon : HeartOutlineIcon"
                                class="w-6 h-6 text-error"
                            />
                        </button>
                        <span class="font-bold text-lg">{{ artwork.likes_count }}</span>

                        <button
                            class="btn btn-circle btn-sm bg-success/10 hover:bg-success/20"
                            @click="openColSelector"
                        >
                            <PlusCircleIcon class="w-6 h-6 text-success"/>
                        </button>

                        <a
                            v-if="canDownload"
                            class="btn btn-circle btn-sm bg-info/10 hover:bg-info/20"
                            :href="artwork.media[0]?.original_url"
                            :download="artwork.title || 'artwork'"
                        >
                            <ArrowDownTrayIcon class="w-6 h-6 text-info"/>
                        </a>

                        <div class="flex items-center gap-1 text-base-content/70">
                            <EyeIcon class="w-6 h-6"/>
                            <span class="font-bold">{{ artwork.views_count }}</span>
                        </div>
                        <ShareModal
                            v-if="showShare"
                            :artwork-id="artwork.id"
                            :chats="myChats"
                            @close="showShare = false"
                        />
                    </div>
                </div>
            </div>

            <!-- tabs -->
            <div
                class="flex gap-6 border-b border-base-300 text-lg font-medium overflow-x-auto scrollbar-hide md:overflow-visible"
            >
                <button
                    v-for="t in tabs"
                    :key="t"
                    class="min-w-fit pb-2 md:pb-1"
                    :class="activeTab === t ? 'border-b-2 border-primary text-primary' : ''"
                    @click="openTab(t)"
                >
                    {{
                        t === 'comments'
                            ? `Комментарии (${artwork.comments_count})`
                            : t === 'author'
                                ? 'Работы автора'
                                : 'Похожие'
                    }}
                </button>
            </div>
            <!-- COMMENTS -->
            <section v-show="activeTab === 'comments'">
                <CommentsSection
                    v-if="showComments"
                    :artwork-id="artwork.id"
                    :complaint-types="complaintTypes"
                    :artwork-owner="author.id"
                    @updateCommentsCount="cnt => artwork.comments_count = cnt"
                />
                <p v-else class="text-base-content/60 mt-4">Автор отключил комментарии</p>
            </section>

            <!-- AUTHOR WORKS -->
            <section v-show="activeTab === 'author'">
                <MasonryGrid
                    :items="authorWorks"
                    :start-page="authorWorks.length ? 1 : 0"
                    :load-more-url="`/author/${author.id}/works?exclude_artwork_id=${artwork.id}`"
                    :per-page="12"
                    @update:items="authorWorks = $event"
                    class="w-full"
                >
                    <template #default="{ item }">
                        <ArtworkCard :art="item" />
                    </template>
                </MasonryGrid>
            </section>

            <!-- SIMILAR WORKS -->
            <section v-show="activeTab === 'similar'">
                <MasonryGrid
                    :items="similarWorks"
                    :start-page="similarWorks.length ? 1 : 0"
                    :load-more-url="`/artworks/${artwork.id}/similar`"
                    :per-page="12"
                    @update:items="similarWorks = $event"
                    class="w-full"
                >
                    <template #default="{ item }">
                        <ArtworkCard :art="item" />
                    </template>
                </MasonryGrid>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.select-none {
    user-select: none;
    -webkit-user-drag: none;
    user-drag: none;
}

.line-clamp-5 {
    display: -webkit-box;
    -webkit-line-clamp: 5;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
