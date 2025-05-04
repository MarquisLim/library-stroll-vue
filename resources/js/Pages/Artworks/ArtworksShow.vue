<template>
    <AppLayout :title="artwork.title || 'Artwork'">
        <div class="p-4 bg-gray-900 text-white min-h-screen">
            <div class="max-w-7xl mx-auto">
                <!-- назад -->
                <button class="btn bg-black bg-opacity-50 hover:bg-opacity-80 rounded-full mb-4" @click="goBack">
                    <img src="/images/icons/back.svg" alt="back" class="w-4 h-4" />
                </button>

                <!-- контент -->
                <div class="flex flex-col md:flex-row gap-4">
                    <!-- медиа -->
                    <div class="md:w-1/2">
                        <video v-if="artwork.type==='video'"
                               :src="artwork.media[0]?.original_url"
                               class="w-full h-auto object-cover rounded" controls preload="metadata"/>
                        <img   v-else
                               :src="artwork.media[0]?.original_url"
                               class="w-full h-auto object-cover rounded"
                               loading="lazy" :alt="artwork.title"/>
                    </div>

                    <!-- описание и кнопки -->
                    <div class="md:w-1/2 flex flex-col space-y-4">
                        <h1 class="text-2xl font-bold">{{ artwork.title || 'Без названия' }}</h1>
                        <p>{{ artwork.description }}</p>

                        <!-- теги -->
                        <div v-if="artwork.tags?.length" class="flex flex-wrap gap-2">
              <span v-for="tag in artwork.tags" :key="tag.id"
                    class="bg-purple-700 px-2 py-1 rounded cursor-pointer hover:bg-purple-600"
                    @click="goToTag(tag.name)">
                #{{ tag.name }}
              </span>
                        </div>

                        <!-- автор -->
                        <div class="flex items-center space-x-2">
                            <img class="h-8 w-8 rounded-full object-cover" :src="author.profile_photo_url"/>
                            <Link :href="`/profile/${author.id}`" class="font-semibold text-blue-400 hover:underline">
                                {{ author.name }}
                            </Link>
                        </div>

                        <!-- лайк / плюс -->
                        <div class="flex items-center space-x-4">
                            <!-- лайк -->
                            <button class="rounded-full w-8 h-8 flex items-center justify-center
                             bg-black bg-opacity-50 hover:bg-opacity-80 transition"
                                    @click="toggleLike(artwork)">
                                <img :src="artwork.liked_by_user ? '/images/icons/liked.svg':'/images/icons/like.svg'"
                                     class="w-5 h-5"/>
                            </button>
                            <span>{{ artwork.likes_count }}</span>

                            <!-- просмотры -->
                            <div class="flex items-center space-x-1">
                                <img src="/images/icons/views.svg" class="w-5 h-5"/>
                                <span>{{ artwork.views_count }}</span>
                            </div>

                            <!-- плюс -->
                            <button class="rounded-full bg-black bg-opacity-50 w-8 h-8 flex items-center justify-center hover:bg-opacity-80"
                                    @click="openSelector(artwork, $event)">
                                <img src="/images/icons/plus-btn.svg" class="w-5 h-5"/>
                            </button>
                        </div>

                        <!-- комментарии -->
                        <CommentsSection :artworkId="artwork.id" @updateCommentsCount="cnt=>artwork.comments_count = cnt"/>
                    </div>
                </div>
            </div>

            <!-- другие работы -->
            <h2 class="text-xl font-bold mt-8 mb-2">Другие работы автора</h2>
            <div class="bg-gray-800 p-4 rounded">
                <MasonryGrid :items="authorWorks">
                    <template #default="{ item }">
                        <ArtworkCard :art="item"/>
                    </template>
                </MasonryGrid>

                <div class="flex justify-center mt-4">
                    <button v-if="hasMoreAuthorWorks"
                            class="btn btn-secondary"
                            :disabled="loadingAuthorWorks"
                            @click="loadAuthorWorks">
                        {{ loadingAuthorWorks ? 'Загрузка…' : 'Загрузить ещё' }}
                    </button>
                </div>

                <p v-if="!hasMoreAuthorWorks && authorWorks.length" class="text-center text-gray-400 mt-4">
                    Больше работ нет
                </p>
                <p v-if="!loadingAuthorWorks && !authorWorks.length" class="text-center text-gray-400 mt-4">
                    Нет других работ автора
                </p>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Link, usePage } from '@inertiajs/vue3'
import { Inertia }        from '@inertiajs/inertia'

import AppLayout    from '@/Layouts/AppLayout.vue'
import MasonryGrid  from '@/Components/MasonryGrid.vue'
import ArtworkCard  from '@/Components/Gallery/ArtworkCard.vue'
import CommentsSection from '@/Components/Comments/CommentsSection.vue'

import { useArtworkActions } from '@/stores/useArtworkActions'
import { storeToRefs }       from 'pinia'

/* ---------- данные с сервера ---------- */
const page     = usePage()
const artwork  = ref({ ...page.props.artwork })
const author   = page.props.author

/* ---------- Pinia ---------- */
const actions = useArtworkActions()
const { toggleLike, openSelector: openSelectorFromStore, setCollections } = actions

/* список коллекций от сервера → store */
if (page.props.collections) setCollections(page.props.collections)

/* ---------- другие работы автора ---------- */
const authorWorks        = ref([])
const loadingAuthorWorks = ref(false)
const hasMoreAuthorWorks = ref(true)
let   authorPage         = 1

onMounted(loadAuthorWorks)

function loadAuthorWorks () {
    if (loadingAuthorWorks.value || !hasMoreAuthorWorks.value) return
    loadingAuthorWorks.value = true
    axios.get(`/author/${author.id}/works`, {
        params: { page: authorPage, per_page: 12, exclude_artwork_id: artwork.value.id }
    }).then(({ data }) => {
        if (data.artworks?.length) {
            authorWorks.value.push(...data.artworks)
            authorPage++
            if (data.artworks.length < 12) hasMoreAuthorWorks.value = false
        } else {
            hasMoreAuthorWorks.value = false
        }
    }).finally(() => loadingAuthorWorks.value = false)
}

/* ---------- вспомогательные ---------- */
function goBack()        { window.history.back() }
function goToTag(tag)    { Inertia.get('/search', { q: tag }) }
function openSelector (art, evt) {
    const rect = evt.currentTarget.getBoundingClientRect()
    openSelectorFromStore(art, rect)
}
</script>
