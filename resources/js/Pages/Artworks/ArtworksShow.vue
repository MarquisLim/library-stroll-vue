<script setup>
import { computed, nextTick, ref, watch } from "vue";
import { Link, usePage, router } from "@inertiajs/vue3";
import {
    ArrowLeftIcon,
    ArrowDownTrayIcon,
    ShareIcon,
    HeartIcon as HeartOutlineIcon,
    EllipsisHorizontalIcon,
    PlusIcon,
    EyeIcon,
    ChatBubbleOvalLeftIcon,
    ShieldExclamationIcon,
} from "@heroicons/vue/24/outline";
import { HeartIcon as HeartSolidIcon } from "@heroicons/vue/24/solid";
import AppLayout from "@/Layouts/AppLayout.vue";
import ArtworkViewer from "@/Components/Gallery/ArtworkViewer.vue";
import UserAvatar from "@/Components/Workspace/UserAvatar.vue";
import MasonryGrid from "@/Components/MasonryGrid.vue";
import ArtworkCard from "@/Components/Gallery/ArtworkCard.vue";
import CommentsSection from "@/Components/Comments/CommentsSection.vue";
import ShareModal from "@/Components/Messenger/ShareModal.vue";
import ComplaintModal from "@/Components/ComplaintModal.vue";
import { useArtworkActions } from "@/stores/useArtworkActions";
const page = usePage();
const artwork = computed(() => page.props.artwork);
const author = computed(() => page.props.author);
const media = computed(() => artwork.value.media?.[0]);
const actions = useArtworkActions();
watch(
    () => page.props.collections,
    (value) => {
        if (value) actions.setCollections(value);
    },
    { immediate: true }
);
const showComplaint = ref(false);
const showShare = ref(false);
const expanded = ref(false);
const activeTab = ref("comments");
const authorWorks = ref([...(page.props.authorWorks || [])]);
const similarWorks = ref([...(page.props.similarWorks || [])]);
const adultConfirmed = ref(
    sessionStorage.getItem("adult-content-confirmed") === "true"
);
const adultHidden = computed(
    () => !!artwork.value.is_adult && !adultConfirmed.value
);
const published = computed(() => {
    const value = artwork.value.published_at || artwork.value.created_at;
    return value
        ? new Date(value).toLocaleDateString("ru-RU", {
              day: "numeric",
              month: "long",
              year: "numeric",
          })
        : "";
});
const number = (value) =>
    new Intl.NumberFormat("ru-RU").format(Number(value) || 0);
const tabs = computed(() => [
    {
        id: "comments",
        label: "Обсуждение",
        count: artwork.value.comments_count,
    },
    { id: "author", label: "Работы автора" },
    { id: "similar", label: "Похожие работы" },
]);
function openComplaint() {
    if (actions.requireAuth(openComplaint)) showComplaint.value = true;
}
function openShare() {
    if (actions.requireAuth(openShare)) showShare.value = true;
}
function openCollections(event) {
    actions.openSelector(
        artwork.value,
        event.currentTarget.getBoundingClientRect()
    );
}
function revealAdult() {
    sessionStorage.setItem("adult-content-confirmed", "true");
    adultConfirmed.value = true;
}
function goBack() {
    router.visit(route("gallery.index"));
}
function moveTab(id, direction) {
    const index = tabs.value.findIndex((tab) => tab.id === id);
    activeTab.value =
        tabs.value[
            (index + direction + tabs.value.length) % tabs.value.length
        ].id;
    nextTick(() =>
        document.getElementById(`artwork-tab-${activeTab.value}`)?.focus()
    );
}
watch(
    () => artwork.value.id,
    () => {
        authorWorks.value = [...(page.props.authorWorks || [])];
        similarWorks.value = [...(page.props.similarWorks || [])];
        activeTab.value = "comments";
        expanded.value = false;
        showShare.value = false;
        showComplaint.value = false;
    }
);
</script>

<template>
    <AppLayout :title="artwork.title || 'Без названия'">
        <div class="artwork-page">
            <nav
                class="mb-6 flex min-w-0 items-center gap-3 text-sm text-base-content/55 sm:mb-8"
                aria-label="Навигация по работе"
            >
                <button
                    type="button"
                    class="btn btn-ghost btn-sm gap-2 px-0 pr-3 text-base-content/65"
                    @click="goBack"
                >
                    <ArrowLeftIcon class="h-4 w-4" />В галерею</button
                ><span aria-hidden="true" class="text-base-content/25">/</span
                ><span class="truncate">{{
                    artwork.title || "Без названия"
                }}</span>
            </nav>
            <div
                class="grid min-w-0 items-start gap-7 lg:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)] xl:gap-10"
            >
                <section aria-label="Просмотр работы" class="min-w-0">
                    <div
                        v-if="adultHidden"
                        class="flex min-h-[440px] flex-col items-center justify-center gap-4 rounded-3xl border border-base-300 bg-base-200 p-8 text-center"
                    >
                        <span
                            class="flex h-14 w-14 items-center justify-center rounded-2xl bg-base-100 text-lg font-bold"
                            >18+</span
                        >
                        <h2 class="text-xl font-bold">Материал для взрослых</h2>
                        <p class="max-w-xs text-sm text-base-content/60">
                            Изображение скрыто. Подтвердите, что хотите его
                            посмотреть.
                        </p>
                        <button class="btn btn-primary" @click="revealAdult">
                            Показать работу
                        </button>
                    </div>
                    <ArtworkViewer
                        v-else
                        :key="artwork.id"
                        :src="media?.original_url"
                        :title="artwork.title"
                        :type="artwork.type"
                        :mime-type="media?.mime_type"
                        :allow-download="!!artwork.allow_download"
                    />
                    <div
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 px-1 text-xs text-base-content/50"
                    >
                        <span
                            >{{
                                artwork.type === "video"
                                    ? "Видео"
                                    : "Изображение"
                            }}<template v-if="artwork.video_duration_formatted">
                                ·
                                {{ artwork.video_duration_formatted }}</template
                            ></span
                        ><span v-if="published"
                            >Опубликовано {{ published }}</span
                        >
                    </div>
                </section>
                <article
                    class="min-w-0 rounded-3xl border border-base-300 bg-base-100 p-5 sm:p-7"
                >
                    <div class="mb-5 flex flex-wrap items-center gap-2 text-xs">
                        <span
                            class="font-bold uppercase tracking-[.14em] text-primary"
                            >В галерее LibraryStroll</span
                        ><span
                            v-if="artwork.has_ai"
                            class="rounded-full bg-primary/10 px-2.5 py-1 font-semibold text-primary"
                            >Создано с AI</span
                        ><span
                            v-if="artwork.is_adult"
                            class="rounded-full bg-base-200 px-2.5 py-1 font-semibold"
                            >18+</span
                        ><span
                            v-if="artwork.is_private"
                            class="rounded-full bg-base-200 px-2.5 py-1 font-semibold"
                            >Приватная работа</span
                        >
                    </div>
                    <h1
                        class="break-words text-3xl font-extrabold leading-tight tracking-tight sm:text-4xl"
                    >
                        {{ artwork.title || "Без названия" }}
                    </h1>
                    <dl v-if="artwork.provenance" class="mt-5 space-y-2 rounded-xl bg-base-200/60 p-4 text-xs leading-relaxed">
                        <div><dt class="text-base-content/50">Автор оригинала</dt><dd>{{ artwork.provenance.creator_name || 'Автор неизвестен' }}</dd></div>
                        <div><dt class="text-base-content/50">Источник</dt><dd><a :href="artwork.provenance.source_url" target="_blank" rel="noopener noreferrer" class="break-all text-primary hover:underline">{{ artwork.provenance.source }}</a></dd></div>
                        <div><dt class="text-base-content/50">Лицензия</dt><dd><a v-if="/^https:\/\//.test(artwork.provenance.license_url || '')" :href="artwork.provenance.license_url" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">{{ artwork.provenance.license }}</a><span v-else>{{ artwork.provenance.license }}</span></dd></div>
                        <div v-if="artwork.provenance.attribution_text"><dt class="text-base-content/50">Атрибуция</dt><dd class="break-words">{{ artwork.provenance.attribution_text }}</dd></div>
                    </dl>
                    <Link
                        v-if="author"
                        :href="`/profile/${author.id}`"
                        class="my-6 flex min-w-0 items-center gap-3 rounded-2xl border border-base-300 p-3 transition hover:border-primary/40 hover:bg-primary/5"
                        ><UserAvatar
                            :src="author.profile_photo_url"
                            :name="author.name"
                            :size="42"
                        /><span class="min-w-0 flex-1"
                            ><span
                                class="block text-[11px] text-base-content/50"
                                >{{ artwork.provenance ? 'Куратор публикации' : 'Автор / коллекция' }}</span
                            ><span
                                class="mt-0.5 block truncate text-sm font-bold"
                                >{{ author.name }}</span
                            ></span
                        ><span
                            class="text-lg text-base-content/35"
                            aria-hidden="true"
                            >↗</span
                        ></Link
                    >
                    <section v-if="artwork.description" class="mb-6">
                        <h2
                            class="mb-3 text-xs font-bold uppercase tracking-wider text-base-content/45"
                        >
                            О работе
                        </h2>
                        <p
                            class="whitespace-pre-line break-words text-sm leading-7 text-base-content/75"
                            :class="{ 'line-clamp-6': !expanded }"
                        >
                            {{ artwork.description }}
                        </p>
                        <button
                            v-if="artwork.description.length > 240"
                            class="mt-2 text-sm font-semibold text-primary hover:underline"
                            :aria-expanded="expanded"
                            @click="expanded = !expanded"
                        >
                            {{
                                expanded
                                    ? "Свернуть описание"
                                    : "Читать полностью"
                            }}
                        </button>
                    </section>
                    <div
                        v-if="artwork.tags?.length"
                        class="mb-6 flex flex-wrap gap-2"
                        aria-label="Теги работы"
                    >
                        <button
                            v-for="tag in artwork.tags"
                            :key="tag.id"
                            class="max-w-full break-words rounded-lg border border-base-300 bg-base-200/50 px-2.5 py-1.5 text-xs text-base-content/65 transition hover:border-primary/40 hover:text-primary"
                            @click="router.get('/search', { q: tag.name })"
                        >
                            #{{ tag.name }}
                        </button>
                    </div>
                    <div
                        class="flex flex-wrap items-center gap-x-5 gap-y-2 border-y border-base-300 py-4 text-xs text-base-content/55"
                    >
                        <span class="inline-flex items-center gap-1.5"><EyeIcon class="h-4 w-4" />Просмотры: {{ number(artwork.views_count) }}</span>
                        <span class="inline-flex items-center gap-1.5"><ChatBubbleOvalLeftIcon class="h-4 w-4" />Комментарии: {{ number(artwork.comments_count) }}</span>
                    </div>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <button
                            class="btn min-w-0"
                            :class="
                                artwork.liked_by_user
                                    ? 'border-error/25 bg-error/10 text-error'
                                    : 'btn-primary'
                            "
                            :aria-pressed="!!artwork.liked_by_user"
                            :aria-label="`${
                                artwork.liked_by_user
                                    ? 'Убрать отметку нравится'
                                    : 'Нравится'
                            }: ${number(artwork.likes_count)}`"
                            @click="actions.toggleLike(artwork)"
                        >
                            <component
                                :is="
                                    artwork.liked_by_user
                                        ? HeartSolidIcon
                                        : HeartOutlineIcon
                                "
                                class="h-5 w-5 shrink-0"
                            />{{ number(artwork.likes_count) }}
                            <span class="truncate">Нравится</span></button
                        ><button
                            class="btn btn-outline min-w-0"
                            @click="openCollections"
                        >
                            <PlusIcon class="h-5 w-5 shrink-0" /><span
                                class="truncate"
                                >В коллекцию</span
                            >
                        </button>
                    </div>
                    <div class="mt-3 flex items-center gap-2">
                        <button
                            class="btn btn-ghost min-w-0 flex-1 text-sm"
                            @click="openShare"
                        >
                            <ShareIcon class="h-4 w-4" />Поделиться</button
                        ><a
                            v-if="
                                artwork.allow_download &&
                                media?.original_url &&
                                !adultHidden
                            "
                            :href="media.original_url"
                            :download="
                                media.file_name || artwork.title || 'artwork'
                            "
                            class="btn btn-ghost btn-square"
                            aria-label="Скачать оригинал"
                            title="Скачать оригинал"
                            ><ArrowDownTrayIcon class="h-5 w-5"
                        /></a>
                        <div class="dropdown dropdown-end">
                            <button
                                type="button"
                                class="btn btn-ghost btn-square"
                                aria-label="Другие действия с работой"
                            >
                                <EllipsisHorizontalIcon class="h-5 w-5" />
                            </button>
                            <ul
                                tabindex="0"
                                class="dropdown-content menu z-20 w-52 rounded-xl border border-base-300 bg-base-100 p-2 shadow-lg"
                            >
                                <li>
                                    <button @click="openComplaint">
                                        <ShieldExclamationIcon
                                            class="h-4 w-4"
                                        />Пожаловаться
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                </article>
            </div>
            <section
                class="mt-10 min-w-0 rounded-3xl border border-base-300 bg-base-100 sm:mt-12"
                aria-label="Обсуждение и другие работы"
            >
                <div
                    role="tablist"
                    aria-label="Разделы работы"
                    class="flex gap-1 overflow-x-auto border-b border-base-300 px-3 pt-2 sm:px-5"
                >
                    <button
                        v-for="tab in tabs"
                        :id="`artwork-tab-${tab.id}`"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        :aria-selected="activeTab === tab.id"
                        :aria-controls="`artwork-panel-${tab.id}`"
                        :tabindex="activeTab === tab.id ? 0 : -1"
                        class="flex shrink-0 items-center gap-2 border-b-2 px-3 py-4 text-sm font-semibold transition"
                        :class="
                            activeTab === tab.id
                                ? 'border-primary text-primary'
                                : 'border-transparent text-base-content/50 hover:text-base-content'
                        "
                        @click="activeTab = tab.id"
                        @keydown.right.prevent="moveTab(tab.id, 1)"
                        @keydown.left.prevent="moveTab(tab.id, -1)"
                    >
                        {{ tab.label
                        }}<span
                            v-if="tab.count !== undefined"
                            class="rounded-md bg-base-200 px-1.5 py-0.5 text-[10px] text-base-content/55"
                            >{{ number(tab.count) }}</span
                        >
                    </button>
                </div>
                <div
                    id="artwork-panel-comments"
                    v-show="activeTab === 'comments'"
                    role="tabpanel"
                    aria-labelledby="artwork-tab-comments"
                    class="p-5 sm:p-7"
                >
                    <CommentsSection
                        v-if="artwork.allow_comments"
                        :key="artwork.id"
                        :artwork-id="artwork.id"
                        :complaint-types="page.props.complaintTypes"
                        :artwork-owner="author?.id"
                        @update-comments-count="
                            (count) => (artwork.comments_count = count)
                        "
                    />
                    <div v-else class="py-10 text-center">
                        <ChatBubbleOvalLeftIcon
                            class="mx-auto mb-3 h-8 w-8 text-base-content/25"
                        />
                        <h2 class="font-semibold">Обсуждение закрыто</h2>
                        <p class="mt-2 text-sm text-base-content/50">
                            Автор отключил комментарии к этой работе.
                        </p>
                    </div>
                </div>
                <div
                    v-if="activeTab === 'author'"
                    id="artwork-panel-author"
                    role="tabpanel"
                    aria-labelledby="artwork-tab-author"
                    class="p-2 sm:p-4"
                >
                    <MasonryGrid
                        :key="`author-${artwork.id}`"
                        :items="authorWorks"
                        :start-page="Math.ceil(authorWorks.length / 12)"
                        :load-more-url="`/author/${author.id}/works?exclude_artwork_id=${artwork.id}`"
                        :per-page="12"
                        @update:items="authorWorks = $event"
                        ><template #default="{ item }"
                            ><ArtworkCard :art="item" /></template
                    ></MasonryGrid>
                </div>
                <div
                    v-if="activeTab === 'similar'"
                    id="artwork-panel-similar"
                    role="tabpanel"
                    aria-labelledby="artwork-tab-similar"
                    class="p-2 sm:p-4"
                >
                    <MasonryGrid
                        :key="`similar-${artwork.id}`"
                        :items="similarWorks"
                        :start-page="Math.ceil(similarWorks.length / 12)"
                        :load-more-url="`/artworks/${artwork.id}/similar`"
                        :per-page="12"
                        @update:items="similarWorks = $event"
                        ><template #default="{ item }"
                            ><ArtworkCard :art="item" /></template
                    ></MasonryGrid>
                </div>
            </section>
            <ComplaintModal
                :show="showComplaint"
                :types="page.props.complaintTypes"
                target-type="artwork"
                :target-id="artwork.id"
                @close="showComplaint = false"
            />
            <ShareModal
                v-if="showShare"
                :artwork-id="artwork.id"
                :chats="page.props.myChats"
                @close="showShare = false"
            />
        </div>
    </AppLayout>
</template>

<style scoped>
.artwork-page {
    @apply mx-auto w-full max-w-screen-2xl px-4 pb-12 pt-5 sm:px-8 sm:pt-7 lg:px-10;
}
</style>
